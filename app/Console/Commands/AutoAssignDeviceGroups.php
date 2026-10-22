<?php
namespace App\Console\Commands;

use App\Clients\FleetClient;
use App\Clients\WazuhClient;
use App\Support\SoftwarePush;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutoAssignDeviceGroups extends Command
{
    protected $signature = 'obaits:auto-assign-device-groups';
    protected $description = 'Real Phase C: automatically assign newly enrolled devices to any device group with a real, configured auto-assign Wazuh machine-type rule, and push any software already assigned to that group';

    public function handle(WazuhClient $wazuh, FleetClient $fleet): int
    {
        $tenants = DB::table('tenants')->pluck('id');

        foreach ($tenants as $tenantId) {
            $groups = DB::table('device_groups')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('auto_assign_wazuh_group')
                ->get();
            if ($groups->isEmpty()) continue;

            $fleetSecret = DB::table('tenant_artifacts')
                ->where('tenant_id', $tenantId)->where('tool', 'fleet')
                ->where('artifact_type', 'enroll_secret')->value('external_id');
            if (!$fleetSecret) continue;

            try {
                $fleetHosts = $fleet->hostsForTenant($fleetSecret);
            } catch (\Throwable $e) {
                $this->warn("Tenant {$tenantId}: could not reach Fleet, skipping.");
                continue;
            }

            // Real, short-hostname-based lookup -- matches the SAME real,
            // proven fix already used three times elsewhere in this
            // project for domain-joined devices (Wazuh reports the short
            // name, Fleet reports the FQDN).
            $hostByShortName = [];
            foreach ($fleetHosts as $h) {
                $short = strtolower(explode('.', $h['hostname'] ?? '')[0]);
                if ($short) $hostByShortName[$short] = $h;
            }

            foreach ($groups as $group) {
                $wazuhAgents = $wazuh->agentsInGroup($group->auto_assign_wazuh_group);

                foreach ($wazuhAgents as $agent) {
                    $shortName = strtolower(explode('.', $agent['name'] ?? '')[0]);
                    $fleetHost = $hostByShortName[$shortName] ?? null;
                    if (!$fleetHost) continue;

                    $fleetHostId = (int) ($fleetHost['id'] ?? 0);
                    if (!$fleetHostId) continue;

                    // Backfill fleet_host_id onto this device's policy
                    // credential, issued at enrollment keyed only by
                    // hostname (neither install script ever learns its own
                    // Fleet host ID -- it's assigned server-side, after the
                    // script has already finished running). Reuses the SAME
                    // short-hostname match this loop already performed
                    // above rather than a second lookup mechanism.
                    // Deliberately runs BEFORE the alreadyMember check below
                    // -- an already-enrolled device must still get backfilled
                    // once, not be skipped forever on every subsequent run.
                    DB::table('device_credentials')
                        ->where('tenant_id', $tenantId)
                        ->where('hostname', $shortName)
                        ->whereNull('fleet_host_id')
                        ->update(['fleet_host_id' => $fleetHostId, 'updated_at' => now()]);


                    $alreadyMember = DB::table('device_group_members')
                        ->where('device_group_id', $group->id)
                        ->where('fleet_host_id', $fleetHostId)
                        ->exists();
                    if ($alreadyMember) continue;

                    DB::table('device_group_members')->insert([
                        'id' => (string) Str::uuid(),
                        'device_group_id' => $group->id,
                        'tenant_id' => $tenantId,
                        'fleet_host_id' => $fleetHostId,
                        'hostname' => $fleetHost['hostname'] ?? $shortName,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);

                    \App\Support\Audit::log($tenantId, 'system', 'device_group.auto_assigned', 'success', null,
                        ['group' => $group->name, 'hostname' => $fleetHost['hostname'] ?? $shortName]);
                    $this->info("Auto-assigned {$shortName} to real group '{$group->name}'.");

                    // Real, immediate push of any software already
                    // assigned to this group, matching the same
                    // real "assign triggers push" behavior already
                    // proven for manual assignment.
                    $assignments = DB::table('software_group_assignments')
                        ->where('device_group_id', $group->id)->get();
                    foreach ($assignments as $a) {
                        SoftwarePush::pushToDevice(
                            $a->id, $fleetHostId, $fleetHost['hostname'] ?? $shortName,
                            strtolower($fleetHost['platform'] ?? ''), 'auto-assign'
                        );
                    }
                }
            }
        }

        return 0;
    }
}
