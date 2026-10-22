<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * REAL, SERIOUS GAP FOUND 2026-08-11: Headscale VPN provisioning has
 * always been a SEPARATE, root-only, manually-invoked command
 * (headscale:provision {tenant}) -- it was never part of
 * ProvisionTenant::handle(), and structurally CANNOT be, since it
 * requires root (systemctl, nginx, chown) while the queue worker that
 * runs ProvisionTenant does not run as root (the exact same constraint
 * already documented on ProcessHeadscaleRevocations).
 *
 * This meant provisioning a tenant's VPN instance depended entirely on a
 * human remembering to run a second, separate root command -- nothing
 * enforced or even checked that it happened. Confirmed live: NOFIA-SA
 * had every other artifact (Keycloak, Fleet, Wazuh, Zabbix) but NO
 * Headscale instance at all, dating back to its Aug 5 incident recovery
 * -- and the gap was invisible until a real customer's Windows Server
 * tried to join a VPN that structurally could not exist, surfacing only
 * as a raw, unhelpful 404 inside the install script.
 *
 * Real fix, matching the same self-healing pattern already proven twice
 * on this project (device_removals, obaits:reconcile-software-pushes):
 * find every tenant with no ACTIVE Headscale instance, and provision one
 * automatically. Reuses ProvisionHeadscaleInstance's own real,
 * already-proven logic via provisionOne() rather than duplicating it --
 * this command and the manual one now share one tested code path.
 *
 * MUST RUN AS ROOT -- identical constraint to every other Headscale
 * command in this project. Scheduled on the SEPARATE ROOT CRONTAB
 * alongside headscale:sync-node-cache / process-revocations /
 * rotate-preauth-keys, NOT via routes/console.php's Schedule::command()
 * -- that runs as the unprivileged web user and would silently fail
 * exactly the way three other Headscale commands already did once
 * (2026-07-27 incident).
 */
#[Signature('headscale:reconcile-instances')]
#[Description('Provision a Headscale VPN instance for any tenant that is missing one')]
class ReconcileHeadscaleInstances extends Command
{
    public function handle(): int
    {
        if (posix_getuid() !== 0) {
            $this->error('This command must run as root (writes to /etc, manages systemd/nginx).');
            return self::FAILURE;
        }

        $tenants = DB::table('tenants')->where('status', 'active')->get();
        $fixed = 0;
        $failed = 0;

        foreach ($tenants as $tenant) {
            $instance = DB::table('headscale_instances')
                ->where('tenant_id', $tenant->id)->where('status', 'active')->first();

            if ($instance) {
                continue; // already provisioned and active -- nothing to do
            }

            $this->warn("Tenant {$tenant->slug} has no active Headscale instance -- provisioning now.");

            $exitCode = $this->call('headscale:provision', ['tenant' => $tenant->slug]);

            if ($exitCode === self::SUCCESS) {
                $fixed++;
                \App\Support\Audit::log($tenant->id, 'system', 'headscale.instance_reconciled', 'success', 'headscale', []);
            } else {
                $failed++;
                \App\Support\Health\OperatorAlert::send('Headscale reconciliation failed', [
                    ['level' => 'critical', 'area' => 'headscale',
                     'message' => "tenant={$tenant->slug} could not be auto-provisioned -- see headscale_instances.last_error"],
                ]);
            }
        }

        $this->info("Reconcile complete. Fixed: {$fixed}. Failed: {$failed}.");
        return self::SUCCESS;
    }
}
