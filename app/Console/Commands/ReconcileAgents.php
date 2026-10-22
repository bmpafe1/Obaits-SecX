<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Support\Facades\DB;
use App\Clients\FleetClient;
use App\Clients\WazuhClient;
use App\Support\Audit;
use App\Support\Health\OperatorAlert;

#[AsCommand(name: 'agents:reconcile', description: 'Verify and repair per-tenant agent enrollment resources')]
class ReconcileAgents extends Command
{
    protected $signature = 'agents:reconcile {--tenant= : limit to one tenant id or slug} {--dry-run}';
    protected $description = 'Verify and repair per-tenant agent enrollment resources';

    private bool $dry = false;
    private array $problems = [];   // collected, then sent as ONE alert per run

    public function handle(FleetClient $fleet, WazuhClient $wazuh): int
    {
        $this->dry = (bool) $this->option('dry-run');

        $tenants = DB::table('tenants')
            ->whereIn('status', ['active', 'license_expired'])   // suspended tenants left alone
            ->when($this->option('tenant'), function ($q, $t) {
                $isUuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $t);
                return $q->where(fn ($w) => $isUuid
                    ? $w->where('id', $t)->orWhere('slug', $t)
                    : $w->where('slug', $t));
            })
            ->get();

        $repaired = 0; $unknown = 0;

        foreach ($tenants as $tenant) {
            $r = $this->reconcileTenant($tenant, $fleet, $wazuh);
            $repaired += $r['repaired'];
            $unknown  += $r['unknown'];
        }

        $this->certificateExpiryCheck();
        $this->flushAlerts();

        // A dry run must never claim work it did not do. Reporting "repaired=1"
        // when nothing changed is the same failure as the label PATCH that returned
        // success without changing anything: a report that does not match reality.
        $prefix = $this->dry ? '[dry-run] ' : '';
        $verb   = $this->dry ? 'would_repair' : 'repaired';

        $this->info("{$prefix}tenants={$tenants->count()} {$verb}={$repaired} unknown={$unknown} problems=" . count($this->problems));

        if ($this->dry) {
            foreach ($this->problems as $p) {
                $this->line('  ' . strtoupper($p['level']) . ' — ' . $p['area'] . ': ' . $p['message']);
            }
        }

        return 0;
    }

    private function reconcileTenant($tenant, FleetClient $fleet, WazuhClient $wazuh): array
    {
        $repaired = 0; $unknown = 0;

        // ---- 1. Fleet enroll secret ------------------------------------
        $secret = DB::table('tenant_artifacts')
            ->where('tenant_id', $tenant->id)->where('tool', 'fleet')
            ->where('artifact_type', 'enroll_secret')->value('external_id');

        if (!$secret) {
            // No artifact at all — provisioning never completed for this tenant.
            // Not drift; a gap. Say so rather than silently skipping the whole check.
            $this->note($tenant->id, 'enroll_secret_absent', 'critical', 'fleet',
                "Tenant {$tenant->slug} has no Fleet enroll secret recorded. Fleet provisioning "
              . "never completed — they cannot enrol any device.");
        } else {
            $state = $fleet->enrollSecretExists($secret);   // true | false | null

            if ($state === false) {
                // Genuinely missing. addEnrollSecret is a read-modify-write, so this
                // is idempotent and leaves other tenants' secrets untouched.
                if (!$this->dry) {
                    $fleet->addEnrollSecret($secret);
                    Audit::log($tenant->id, 'reconciler', 'agents.reconcile.repair', 'success', 'fleet',
                        ['what' => 'enroll_secret_readded']);
                }
                $this->warn("[{$tenant->slug}] enroll secret missing from Fleet — "
                    . ($this->dry ? 'WOULD re-add' : 're-added'));
                $this->note($tenant->id, 'enroll_secret_missing', 'warning', 'fleet',
                    "Tenant {$tenant->slug}: enroll secret was absent from Fleet and has been re-added. "
                  . "Devices could not enrol while it was missing.");
                $repaired++;
            } elseif ($state === null) {
                // Fleet did not answer. NOT drift. Change nothing, say nothing.
                // Note we do NOT clear here either — an unanswered question is not
                // evidence that the problem went away.
                $unknown++;
            } else {
                $this->clear($tenant->id, 'enroll_secret_missing');
                $this->clear($tenant->id, 'enroll_secret_absent');
            }
        }

        // ---- 2. Fleet tenant label -------------------------------------
        // On Fleet Free this label is the ONLY thing attributing hosts to a tenant.
        $labelState = $fleet->tenantLabelState($tenant->id, $tenant->slug);

        if ($labelState === 'missing') {
            if (!$this->dry) {
                $fleet->ensureTenantLabel($tenant->id, $tenant->slug);
                Audit::log($tenant->id, 'reconciler', 'agents.reconcile.repair', 'success', 'fleet',
                    ['what' => 'tenant_label_recreated']);
            }
            $this->warn("[{$tenant->slug}] tenant label missing — "
                . ($this->dry ? 'WOULD recreate' : 'recreated'));
            $this->note($tenant->id, 'tenant_label_missing', 'critical', 'fleet',
                "Tenant {$tenant->slug}: the Fleet label was missing, so none of their devices "
              . "were visible to them. It has been recreated — hosts will reappear after the next "
              . "label evaluation (up to one FLEET_OSQUERY_LABEL_UPDATE_INTERVAL, default one hour).");
            $repaired++;

        } elseif ($labelState === 'stale_query') {
            // Fleet returns 200 for a query PATCH on a dynamic label and SILENTLY
            // IGNORES it — the query is immutable. So we attempt it, then VERIFY by
            // reading back. Returning true on the 200 produced an hourly loop that
            // logged a repair it never made.
            $ok = $this->dry ? false : $fleet->updateTenantLabelQuery($tenant->id, $tenant->slug);

            if ($ok) {
                Audit::log($tenant->id, 'reconciler', 'agents.reconcile.repair', 'success', 'fleet',
                    ['what' => 'tenant_label_query_updated']);
                $this->warn("[{$tenant->slug}] label query out of date — updated");
                $this->clear($tenant->id, 'tenant_label_query_stale');
                $repaired++;
            } else {
                $this->note($tenant->id, 'tenant_label_query_stale', 'warning', 'fleet',
                    "Tenant {$tenant->slug}: the Fleet label query is out of date and Fleet will not "
                  . "change it in place (dynamic label queries are immutable). Devices on platforms "
                  . "added since the label was created (e.g. Windows) will NOT be attributed. "
                  . "Fixing this needs a MAINTENANCE WINDOW: delete the label via "
                  . "DELETE /api/v1/fleet/labels/id/{id} (note the /id/ segment — /labels/{id} "
                  . "returns success and deletes nothing), then run "
                  . "php artisan agents:reconcile --tenant={$tenant->slug}. The tenant will see NO "
                  . "devices until the next label evaluation — up to one hour on default settings.");
            }

        } elseif ($labelState === 'unknown') {
            // Fleet did not answer. Not drift.
            $unknown++;

        } else {   // present
            $this->clear($tenant->id, 'tenant_label_missing');
            $this->clear($tenant->id, 'tenant_label_query_stale');
        }

        // ---- 3. Wazuh agent group --------------------------------------
        $group = DB::table('tenant_artifacts')
            ->where('tenant_id', $tenant->id)->where('tool', 'wazuh')
            ->where('artifact_type', 'agent_group')->value('external_id');

        if ($group) {
            try {
                $token = $wazuh->token();
                if ($wazuh->groupExists($token, $group) === false) {
                    if (!$this->dry) {
                        $wazuh->ensureGroup($token, $group);
                        Audit::log($tenant->id, 'reconciler', 'agents.reconcile.repair', 'success', 'wazuh',
                            ['what' => 'agent_group_recreated']);
                    }
                    $this->warn("[{$tenant->slug}] Wazuh group missing — "
                        . ($this->dry ? 'WOULD recreate' : 'recreated'));
                    $this->note($tenant->id, 'wazuh_group_missing', 'warning', 'wazuh',
                        "Tenant {$tenant->slug}: the Wazuh agent group was missing and has been recreated.");
                    $repaired++;
                } else {
                    $this->clear($tenant->id, 'wazuh_group_missing');
                }
            } catch (\Throwable $e) {
                $unknown++;   // manager unreachable — not drift, and we do NOT clear
            }
        }

        // ---- 4. Agent package ------------------------------------------
        // Alert only. fleetctl package is slow and network-bound; rebuilding every
        // tenant's package on a schedule is a good way to hammer the server.
        $pkgs = glob(storage_path('app/private/agent-packages/' . $tenant->id) . '/fleet-osquery*');

        if (!$pkgs) {
            $this->note($tenant->id, 'agent_package_missing', 'warning', 'enrollment',
                "Tenant {$tenant->slug}: no agent package has been built. They cannot enrol devices "
              . "until you run: php artisan obaits:agent-package {$tenant->slug}");
        } else {
            $this->clear($tenant->id, 'agent_package_missing');
        }

        return compact('repaired', 'unknown');
    }

    // ---- 5. Certificate expiry ------------------------------------------
    // Silent expiry is the worst unattended failure here: every deployed agent stops
    // trusting the server at the same moment, and every pre-built package is invalid.
    //
    // Note this only applies while a self-signed certificate is baked into packages
    // via --fleet-certificate. With a real CA certificate in production, agents use
    // the system trust store and rotation is invisible.
    private function certificateExpiryCheck(): void
    {
        $path = config('services.fleet.cert_path');
        if (!$path || !is_readable($path)) return;

        $cert = @openssl_x509_parse((string) file_get_contents($path));
        if (!$cert || empty($cert['validTo_time_t'])) return;

        $days = (int) floor(($cert['validTo_time_t'] - time()) / 86400);

        if ($days <= 30) {
            $this->note(null, 'fleet_cert_expiring', $days <= 7 ? 'critical' : 'warning', 'fleet',
                "The Fleet server certificate expires in {$days} days. When it does, every enrolled "
              . "agent will stop trusting the server and every pre-built package must be rebuilt.");
        } else {
            $this->clear(null, 'fleet_cert_expiring');
        }
    }

    // ---- 6. Alerting without fatigue -------------------------------------
    // This runs hourly. A problem lasting a week is 168 runs. Alerting each time
    // guarantees the alerts get ignored — the same reason critical-vulnerability
    // notifications send one digest per scan rather than one per finding.
    private function note(?string $tenantId, string $key, string $level, string $area, string $message): void
    {
        // A dry run must change nothing, including its own bookkeeping.
        if ($this->dry) {
            $this->problems[] = ['level' => $level, 'area' => $area, 'message' => $message];
            return;
        }

        $row = DB::table('agent_reconcile_state')
            ->where('problem_key', $key)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId),
                              fn ($q) => $q->whereNull('tenant_id'))
            ->first();

        $now = now();

        if (!$row) {
            DB::table('agent_reconcile_state')->insert([
                'tenant_id' => $tenantId, 'problem_key' => $key,
                'first_seen_at' => $now, 'last_alerted_at' => $now, 'resolved_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->problems[] = ['level' => $level, 'area' => $area, 'message' => $message];
            return;
        }

        // A problem that was resolved and has come BACK is news, whatever the clock
        // says. Without this, an issue that clears and recurs the same day is silent.
        if ($row->resolved_at !== null) {
            DB::table('agent_reconcile_state')->where('id', $row->id)->update([
                'first_seen_at' => $now, 'last_alerted_at' => $now,
                'resolved_at' => null, 'updated_at' => $now,
            ]);
            $this->problems[] = ['level' => $level, 'area' => $area,
                'message' => $message . ' (recurred)'];
            return;
        }

        // Still broken. Re-alert at most daily, and say for how long — an operator
        // needs to tell "just happened" from "three days now".
        $lastAlert = $row->last_alerted_at ? \Carbon\Carbon::parse($row->last_alerted_at) : null;
        if (!$lastAlert || $lastAlert->lt($now->copy()->subDay())) {
            $ageDays = (int) \Carbon\Carbon::parse($row->first_seen_at)->diffInDays($now);
            $this->problems[] = ['level' => $level, 'area' => $area,
                'message' => $message . ' (unresolved for ' . $ageDays . ' day(s))'];

            DB::table('agent_reconcile_state')->where('id', $row->id)
                ->update(['last_alerted_at' => $now, 'updated_at' => $now]);
        }
    }

    private function clear(?string $tenantId, string $key): void
    {
        if ($this->dry) return;   // a dry run changes nothing

        DB::table('agent_reconcile_state')
            ->where('problem_key', $key)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId),
                              fn ($q) => $q->whereNull('tenant_id'))
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'updated_at' => now()]);
    }

    private function flushAlerts(): void
    {
        if (empty($this->problems) || $this->dry) return;

        OperatorAlert::send('Agent enrollment reconciliation found ' . count($this->problems) . ' issue(s)',
            $this->problems);
    }
}