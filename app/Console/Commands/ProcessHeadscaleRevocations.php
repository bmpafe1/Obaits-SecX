<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Processes queued VPN-access revocations. Deliberately a SEPARATE root-run
 * command rather than something Assets::removeDevice() does directly —
 * that Livewire action runs as www-data, which cannot read/write a
 * tenant's headscale unix socket (0750, headscale:headscale), the same
 * constraint every other Headscale-touching command in this project has.
 *
 * removeDevice() only ever queues a row here; this command does the real
 * work moments later via the scheduler. A brief window where a removed
 * device is still VPN-connected is an acceptable, bounded cost — the
 * alternative (widening www-data into every tenant's headscale group) was
 * explicitly rejected earlier in Phase 4 as weakening structural isolation.
 */
#[Signature('headscale:process-revocations')]
#[Description('Remove queued devices from their tenant Headscale VPN')]
class ProcessHeadscaleRevocations extends Command
{
    /**
     * Real, short, lowercased device name -- everything before the first dot.
     *
     * REAL SECURITY BUG FOUND 2026-08-06 (confirmed against live data, not
     * theorised): this command matched node names with an EXACT strcasecmp
     * against the hostname queued by removeDevice(), which is Fleet's own
     * hostname. Fleet reports the FQDN for domain-joined and DNS-suffixed
     * machines while Headscale stores the short name:
     *
     *     Fleet 'WIN-2UQ47DD9KL4.AICEXPERTS.LOCAL' vs Headscale 'WIN-2UQ47DD9KL4'
     *     Fleet 'mail.aicexperts.com'              vs Headscale 'mail'
     *
     * No node matched, and the "not found" branch below treats that as
     * SUCCESS ("already gone") -- so removing either device marked the
     * revocation done, logged success, and LEFT THE VPN NODE LIVE. The
     * tenant saw "device removed" while the machine kept full access to
     * their private network. Exactly the gap Phase 4 set out to close,
     * silently reintroduced by a hostname format mismatch.
     *
     * This is the FIFTH occurrence of this bug class in this project --
     * the same real fix already used in AutoAssignDeviceGroups and
     * elsewhere. Any comparison between a Fleet hostname and a name from
     * Wazuh, Headscale, or Zabbix must go through this normalisation.
     */
    private static function shortName(string $name): string
    {
        return strtolower(explode('.', trim($name))[0]);
    }

    public function handle(): int
    {
        if (posix_getuid() !== 0) {
            $this->error('This command must run as root (manages each tenant\'s headscale nodes).');
            return self::FAILURE;
        }

        $pending = DB::table('headscale_revocation_queue')->where('status', 'pending')->get();

        foreach ($pending as $req) {
            $instance = DB::table('headscale_instances')
                ->where('tenant_id', $req->tenant_id)->where('status', 'active')->first();

            if (!$instance) {
                // No VPN instance for this tenant at all — nothing to revoke.
                DB::table('headscale_revocation_queue')->where('id', $req->id)->update([
                    'status' => 'done', 'processed_at' => now(), 'updated_at' => now(),
                ]);
                continue;
            }

            $configPath = "/etc/headscale-{$instance->slug}/config.yaml";

            $list = new Process(['headscale', '--config', $configPath, 'nodes', 'list', '--output', 'json'], null, null, null, 30);
            $list->run();

            if (!$list->isSuccessful()) {
                DB::table('headscale_revocation_queue')->where('id', $req->id)->update([
                    'status' => 'failed', 'last_error' => trim($list->getErrorOutput()), 'updated_at' => now(),
                ]);
                continue;
            }

            $nodes = json_decode($list->getOutput(), true) ?: [];
            $want  = self::shortName($req->hostname);

            // REAL GAP FOUND alongside the matching bug: this took only the
            // FIRST matching node. Live data genuinely showed one device
            // holding TWO nodes (obaitsclient1 on both 100.64.0.2 and
            // 100.64.0.4) -- a device re-joining the tailnet leaves the old
            // node behind. Revoking only the first left the second live,
            // which is the same exposure in a different shape. ALL matching
            // nodes are now revoked.
            $matches = array_values(array_filter(
                $nodes,
                fn ($n) => self::shortName((string) ($n['name'] ?? '')) === $want
            ));

            if (!$matches) {
                // Genuinely already gone (never joined, or already removed).
                // Safe to call done ONLY because the comparison above is now
                // correct -- previously this branch silently absorbed every
                // FQDN mismatch as a false success.
                DB::table('headscale_revocation_queue')->where('id', $req->id)->update([
                    'status' => 'done', 'processed_at' => now(), 'updated_at' => now(),
                ]);
                continue;
            }

            $failures = [];
            $revoked  = 0;

            foreach ($matches as $match) {
                $del = new Process(['headscale', '--config', $configPath, 'nodes', 'delete', '--identifier', (string) $match['id'], '--force'], null, null, null, 30);
                $del->run();

                if ($del->isSuccessful()) {
                    $revoked++;
                    \App\Support\Audit::log($req->tenant_id, 'system', 'headscale.node_revoked', 'success', 'headscale', [
                        'hostname' => $match['name'] ?? $req->hostname,
                        'node_id'  => $match['id'] ?? null,
                    ]);
                } else {
                    $failures[] = ($match['name'] ?? '?') . ': ' . trim($del->getErrorOutput());
                }
            }

            // Cache cleanup matches on the same normalised short name, since
            // the cache stores Headscale's own name, not Fleet's.
            DB::table('headscale_node_cache')
                ->where('tenant_id', $req->tenant_id)
                ->get()
                ->filter(fn ($c) => self::shortName($c->hostname) === $want)
                ->each(fn ($c) => DB::table('headscale_node_cache')->where('id', $c->id)->delete());

            if (!$failures) {
                DB::table('headscale_revocation_queue')->where('id', $req->id)->update([
                    'status' => 'done', 'processed_at' => now(), 'updated_at' => now(),
                ]);
                $this->info("Revoked {$revoked} node(s) for {$req->hostname}.");
                continue;
            }

            // A PARTIAL revocation is a real, serious condition: at least one
            // node is still live on the tenant's private network. Left
            // 'pending' deliberately so the next scheduled run retries the
            // remainder, rather than 'failed' which would stop retrying.
            DB::table('headscale_revocation_queue')->where('id', $req->id)->update([
                'last_error' => implode(' | ', $failures), 'updated_at' => now(),
            ]);

            \App\Support\Health\OperatorAlert::send('Headscale VPN revocation FAILED', [
                ['level' => 'critical', 'area' => 'headscale',
                 'message' => "tenant={$req->tenant_id} hostname={$req->hostname} revoked={$revoked} errors=" . implode(' | ', $failures)],
            ]);
        }

        return self::SUCCESS;
    }
}
