<?php

namespace App\Console\Commands;

use App\Support\Audit;
use App\Support\Health\OperatorAlert;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verify every scanner pool member can actually do its job.
 *
 * WHY THIS EXISTS SEPARATELY FROM THE CONTAINER HEALTH CHECK: a pool member can
 * be running, pass gvmd's own verification, and still be able to reach NOTHING.
 *
 * Confirmed 2026-08-25: ospd attached to a sidecar container that no longer
 * existed after a restart. The container was up, verify_scanner returned 200
 * with a version string, and its routing table was EMPTY -- every connection to
 * a tenant device failed with "Network is unreachable". Nothing detected it.
 *
 * So three layers are checked, and the third is the one nothing else can see:
 *
 *   1. The container is running.
 *   2. gvmd can reach it over TLS (verify_scanner).
 *   3. It can reach the tenant network it claims to be joined to.
 *
 * For (3) the test is that the sidecar holds a tailnet address AND sees at
 * least one online peer. Testing a SPECIFIC device would raise a false alarm
 * every time that one device happened to be switched off.
 */
#[Signature('obaits:verify-scanner-pool {--dry-run : report only, do not change member state}')]
#[Description('Check every scanner pool member can reach gvmd and its tenant network')]
class VerifyScannerPool extends Command
{
    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $members = DB::table('scanner_pool_members')
            ->where('state', '!=', 'disabled')   // disabled means an operator took it out on purpose
            ->orderBy('name')
            ->get();

        if ($members->isEmpty()) {
            $this->info('No pool members to verify.');
            return self::SUCCESS;
        }

        $problems = [];

        foreach ($members as $m) {
            [$ok, $status] = $this->verify($m);

            $this->{$ok ? 'info' : 'warn'}("  {$m->name}: {$status}");

            if ($dry) {
                continue;
            }

            DB::table('scanner_pool_members')->where('id', $m->id)->update([
                'last_verified_at'   => now(),
                'last_verify_status' => mb_substr($status, 0, 250),
                'last_error'         => $ok ? null : mb_substr($status, 0, 2000),
                // Only demote an otherwise-healthy member. Never overwrite an
                // in-flight allocation: a member mid-scan that fails one check
                // is handled by the scan's own staleness path, and flipping its
                // state here would lose track of the tenant it is joined to.
                'state'              => $ok
                    ? ($m->state === 'failed' ? 'idle' : $m->state)
                    : ($m->state === 'idle' ? 'failed' : $m->state),
                'updated_at'         => now(),
            ]);

            if (!$ok) {
                $problems[] = [
                    'level'   => 'critical',
                    'area'    => 'scanner pool',
                    'message' => "Pool member {$m->name} cannot scan: {$status}",
                ];
                Audit::log(null, 'system', 'scanner_pool.verify_failed', 'failure', 'gvm',
                    ['member' => $m->name, 'status' => $status]);
            }
        }

        if (!$dry && $problems) {
            OperatorAlert::send(count($problems) . ' scanner pool member(s) unhealthy', $problems);
        }

        return self::SUCCESS;
    }

    /** @return array{0: bool, 1: string} */
    private function verify(object $m): array
    {
        // --- 1. Container running -------------------------------------------
        // Derived from the member's own name, not hardcoded. Until 2026-08-30
        // these were the string literals "obaits-gvm-pool-1-...", so a second
        // pool member would have been checked against pool-1's containers --
        // reporting the wrong member healthy, which is worse than not checking
        // it at all.
        //
        // The pattern comes from the compose project directory
        // (ops/gvm-pool/compose.pool-{n}.yaml run as project obaits-gvm-{name})
        // and Docker's own service-instance naming.
        $project = 'obaits-gvm-' . $m->name;
        $ospd = "{$project}-ospd-{$m->name}-1";
        $side = "{$project}-tailscale-{$m->name}-1";

        $ps = shell_exec("docker ps --format '{{.Names}}|{{.Status}}' 2>/dev/null") ?? '';
        $up = [];
        foreach (explode("\n", trim($ps)) as $line) {
            if (!$line) continue;
            [$n, $st] = array_pad(explode('|', $line, 2), 2, '');
            $up[$n] = $st;
        }

        foreach ([$ospd, $side] as $c) {
            if (!isset($up[$c]) || !str_starts_with($up[$c], 'Up')) {
                return [false, "container {$c} is not running"];
            }
        }

        // --- 2. gvmd can reach it over TLS ----------------------------------
        try {
            $v = app(\App\Clients\GvmClient::class)->verifyScanner($m->gvm_scanner_id);
            if (($v['status'] ?? '') !== '200') {
                return [false, 'gvmd could not verify the scanner: ' . ($v['status_text'] ?? 'unknown')];
            }
        } catch (\Throwable $e) {
            return [false, 'verify_scanner failed: ' . $e->getMessage()];
        }

        // --- 3. Can it reach its tenant network? ----------------------------
        // The check nothing else performs. A member with an orphaned network
        // namespace passes both checks above and reaches nothing.
        $sock = '/var/run/tailscale/tailscaled.sock';
        $ip = trim((string) shell_exec(
            "docker exec " . escapeshellarg($side) . " tailscale --socket={$sock} ip -4 2>/dev/null"
        ));

        if (!preg_match('/^100\.\d+\.\d+\.\d+$/', $ip)) {
            return [false, 'not joined to any tenant network (no tailnet address)'];
        }

        $status = (string) shell_exec(
            "docker exec " . escapeshellarg($side) . " tailscale --socket={$sock} status 2>/dev/null"
        );

        $peers = 0;
        foreach (explode("\n", trim($status)) as $line) {
            if ($line === '' || str_contains($line, $ip)) continue;   // skip self
            if (!str_contains($line, 'offline')) $peers++;
        }

        if ($peers === 0) {
            return [false, "joined at {$ip} but sees no online peers -- cannot scan anything"];
        }

        return [true, "OK at {$ip}, {$peers} peer(s) reachable"];
    }
}
