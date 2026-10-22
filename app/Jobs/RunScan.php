<?php
namespace App\Jobs;
 
use App\Clients\GvmClient;
use App\Support\Audit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
 
class RunScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
 
    public int $tries = 1;
    public int $timeout = 7200; // scans are long
 
    public function __construct(public string $scanId) {}
 
    public function handle(GvmClient $gvm): void
    {
        $scan = DB::table('scans')->where('id', $this->scanId)->first();
        if (!$scan) return;
 
        // OWNERSHIP GATE: the target's domain must be verified for this tenant.
        $owned = DB::table('tenant_domains')
            ->where('tenant_id', $scan->tenant_id)
            ->where('verified', true)
            ->where(function ($q) use ($scan) {
                $q->where('domain', $scan->target)
                  ->orWhere('domain', preg_replace('#^www\.#', '', $scan->target));
            })->exists();

        // ASSET TARGET: is this target one of the tenant's OWN enrolled Fleet hosts?
        // An IP belonging to a device the tenant has enrolled is a legitimate owned target.
        $ownedAsset = false;
        if (!$owned) {
            $secret = DB::table('tenant_artifacts')
                ->where('tenant_id', $scan->tenant_id)
                ->where('tool', 'fleet')
                ->where('artifact_type', 'enroll_secret')
                ->value('external_id');
            if ($secret) {
                try {
                    // REAL BUG FOUND 2026-08-24, on the very first device scan ever
                    // run on this platform: comparing against primary_ip alone
                    // rejected every legitimate private device.
                    //
                    // Fleet's primary_ip is ALWAYS the device's real LAN address,
                    // never its Tailscale one (confirmed empirically 2026-07-26 and
                    // documented in Scans::assetTargets()). For a private device the
                    // scannable target is therefore its VPN IP, and that is exactly
                    // what the portal submits -- so the portal offered 100.64.0.9
                    // while this check looked only for a 10.x address and refused it.
                    //
                    // Resolved the SAME way the portal resolves it, via the shared
                    // Reachability helper, so the two cannot drift apart again.
                    foreach (app(\App\Clients\FleetClient::class)->hostsForTenant($secret) as $h) {
                        $lanIp = $h['primary_ip'] ?? ($h['public_ip'] ?? null);
                        if (!$lanIp) continue;

                        if ($lanIp === $scan->target) { $ownedAsset = true; break; }

                        $name = $h['hostname'] ?? $lanIp;
                        if (\App\Support\Reachability::isPrivate($lanIp)) {
                            $vpnIp = \App\Support\Reachability::vpnIpForHost($scan->tenant_id, $name);
                            if ($vpnIp && $vpnIp === $scan->target) { $ownedAsset = true; break; }
                        }
                    }
                } catch (\Throwable $e) {
                    // Fleet unreachable; fall through to the normal checks.
                }
            }
        }
 
        // In dev, allow explicit localhost/owned test targets.
        $devTargets = ['127.0.0.1', 'localhost'];
        if (!$owned && !$ownedAsset && !in_array($scan->target, $devTargets)) {
            DB::table('scans')->where('id', $scan->id)->update(['status' => 'failed', 'updated_at' => now()]);
            Audit::log($scan->tenant_id, 'system', 'scan.refused_unverified', 'failure', 'gvm',
                ['target' => $scan->target]);
            throw new \RuntimeException('Refused: target not a verified domain or enrolled device for this tenant');
        }
 
        DB::table('scans')->where('id', $scan->id)->update([
            'status' => 'running', 'started_at' => now(), 'updated_at' => now(),
        ]);
 
        // REACHABILITY GATE: refuse targets we cannot actually reach. Phase 4:
        // isScannable() now requires tenant_id — a private-range IP is only
        // scannable if it's a currently-online node on THIS tenant's own
        // Headscale instance (IPs are not globally unique across tenants).
        $host = $scan->target;
        if (filter_var($host, FILTER_VALIDATE_IP) && !\App\Support\Reachability::isScannable($host, $scan->tenant_id)) {
            DB::table('scans')->where('id', $scan->id)->update(['status' => 'failed', 'updated_at' => now()]);
            Audit::log($scan->tenant_id, 'system', 'scan.refused_unreachable', 'failure', 'gvm',
                ['target' => $scan->target]);
            throw new \RuntimeException('Refused: target is on a private network and is not reachable from the platform');
        }


        $targetId = $gvm->createTarget('obaits-' . $scan->id, $scan->target);
        $taskId = $gvm->createTask('obaits-' . $scan->id,
            config('services.gvm.scan_config_id'), $targetId, config('services.gvm.scanner_id'));
 
        DB::table('scans')->where('id', $scan->id)->update([
            'gvm_target_id' => $targetId, 'gvm_task_id' => $taskId, 'updated_at' => now(),
        ]);
 
        $gvm->startTask($taskId);

        // LAUNCH AND EXIT. This job used to poll to completion in a while loop
        // with sleep(30), holding a worker for the scan's entire duration.
        //
        // REAL FAILURE 2026-08-25: a scan of a real device over a tenant VPN ran
        // for TWO HOURS and the job was then killed by the worker timeout. GVM
        // had finished successfully -- the task reported Done and the findings
        // were sitting there -- but nothing was left alive to collect them. The
        // scan row sat at 96% with zero findings and had to be recovered by
        // hand. A scan against localhost took six minutes, so this only appeared
        // once scanning did something real.
        //
        // Collection now happens in obaits:poll-scans, scheduled every two
        // minutes. Same pattern as PollPolicyRuns and PollSoftwareInstalls, and
        // exactly what FleetScriptRunner's own docblock recommends: launch, then
        // poll from something that can be restarted, so a slow target never ties
        // up a worker and a worker restart never loses a scan.
        Audit::log($scan->tenant_id, 'system', 'scan.launched', 'success', 'gvm',
            ['target' => $scan->target, 'task' => $taskId]);
    }
}
