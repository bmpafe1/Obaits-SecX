<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Syncs each tenant's LIVE Headscale node list into headscale_node_cache,
 * so Reachability::isScannable() can do a plain, fast DB read instead of
 * shelling out to `headscale nodes list` at request time.
 *
 * WHY THIS EXISTS AS A SEPARATE ROOT-RUN COMMAND, NOT A LIVE LOOKUP:
 * each tenant's Headscale unix socket is owned by headscale:headscale,
 * mode 0750 — the web server user (www-data) genuinely cannot read it,
 * confirmed directly during Phase 4 testing. Widening that permission
 * would weaken the exact per-tenant structural isolation Option B (Part E)
 * was chosen to protect. This command runs as root via cron instead,
 * mirroring the same root-required pattern already established for
 * ProvisionHeadscaleInstance.
 *
 * SECURITY: `headscale nodes list --output json` embeds the tenant's live,
 * reusable pre-auth key in EVERY node's record (pre_auth_key.key). This
 * command reads that JSON but extracts ONLY name/ip_addresses/online —
 * the raw payload is NEVER logged, cached, or passed anywhere else. Do not
 * "helpfully" log the full output for debugging; that would leak a
 * credential capable of joining the tenant's private VPN.
 */
#[Signature('headscale:sync-node-cache')]
#[Description('Refresh the cached Headscale node list for every active tenant instance')]
class SyncHeadscaleNodeCache extends Command
{
    public function handle(): int
    {
        if (posix_getuid() !== 0) {
            $this->error('This command must run as root (reads each tenant\'s headscale unix socket).');
            return self::FAILURE;
        }

        $instances = DB::table('headscale_instances')->where('status', 'active')->get();
        $now = now();

        foreach ($instances as $instance) {
            $configPath = "/etc/headscale-{$instance->slug}/config.yaml";

            $p = new Process(['headscale', '--config', $configPath, 'nodes', 'list', '--output', 'json'], null, null, null, 30);
            $p->run();

            if (!$p->isSuccessful()) {
                $this->warn("Skipping {$instance->slug}: " . trim($p->getErrorOutput()));
                continue;
            }

            $nodes = json_decode($p->getOutput(), true);
            if (!is_array($nodes)) {
                $this->warn("Skipping {$instance->slug}: unparseable node list");
                continue;
            }

            foreach ($nodes as $node) {
                // Extract ONLY what we need. $node also contains
                // pre_auth_key.key — a live credential — never persisted
                // beyond this loop iteration's local variable.
                $hostname = $node['name'] ?? $node['given_name'] ?? null;
                $ipv4 = null;
                foreach (($node['ip_addresses'] ?? []) as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $ipv4 = $ip;
                        break;
                    }
                }
                $online = (bool) ($node['online'] ?? false);

                if (!$hostname || !$ipv4) {
                    continue;
                }

                DB::table('headscale_node_cache')->updateOrInsert(
                    ['tenant_id' => $instance->tenant_id, 'ip_v4' => $ipv4],
                    [
                        'id' => (string) Str::uuid(),
                        'hostname' => $hostname,
                        'online' => $online,
                        'synced_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }

            $this->info("Synced {$instance->slug}: " . count($nodes) . ' node(s)');
        }

        // Remove cache rows for tenants whose instance no longer exists or
        // is no longer active — a deprovisioned tenant should not leave a
        // stale "yes this is scannable" entry behind.
        $activeTenantIds = $instances->pluck('tenant_id')->all();
        DB::table('headscale_node_cache')->whereNotIn('tenant_id', $activeTenantIds)->delete();

        return self::SUCCESS;
    }
}
