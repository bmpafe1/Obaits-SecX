<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Real device-removal safety net. Assets::removeDevice() only ever inserts
 * a row here now -- all the actual revocation happens here, per step,
 * durably, and retryably.
 *
 * ORDER MATTERS and is deliberate, most dangerous first:
 *   1. backup_selections -- disabled, not deleted (history stays for audit)
 *   2. headscale          -- reuses today's already-fixed queue+command
 *   3. role_credentials   -- deletes stored DB passwords for this host
 *   4. zabbix             -- deletes the monitored host
 *   5. wazuh               -- deletes the agent (lookup already normalises FQDN)
 *   6. group_membership   -- deletes device_group_members rows
 *   7. fleet               -- LAST; every earlier step resolves the device
 *      through Fleet's own host record, so deleting it first would destroy
 *      the information needed to retry anything that failed.
 *
 * Each step is independently retryable: one tool being briefly unreachable
 * never blocks revoking the others.
 */
#[Signature('obaits:process-device-removals')]
#[Description('Process queued device removals: revoke access across every system in exposure order')]
class ProcessDeviceRemovals extends Command
{
    private const STEPS = [
        'backup_selections',
        'headscale',
        'role_credentials',
        'zabbix',
        'wazuh',
        'group_membership',
        'device_credential',
        'threat_intel_health',
        'fleet',
    ];

    public function handle(): int
    {
        $open = DB::table('device_removals')->whereNull('completed_at')->get();

        foreach ($open as $removal) {
            $status = json_decode($removal->artifact_status, true) ?: [];
            $changed = false;

            foreach (self::STEPS as $step) {
                if (($status[$step] ?? 'pending') === 'done' || ($status[$step] ?? null) === 'skipped') {
                    continue;
                }

                try {
                    $result = $this->runStep($step, $removal);
                    $status[$step] = $result;
                    $changed = true;
                    $this->info("  {$removal->hostname} [{$step}]: {$result}");
                } catch (\Throwable $e) {
                    $status[$step] = 'failed';
                    $status[$step . '_error'] = $e->getMessage();
                    $changed = true;
                    $this->warn("  {$removal->hostname} [{$step}]: FAILED — {$e->getMessage()}");
                }
            }

            $allDone = collect(self::STEPS)->every(fn ($s) => in_array($status[$s] ?? 'pending', ['done', 'skipped']));

            if ($changed) {
                DB::table('device_removals')->where('id', $removal->id)->update([
                    'artifact_status' => json_encode($status),
                    'completed_at' => $allDone ? now() : null,
                    'updated_at' => now(),
                ]);
            }

            if ($allDone) {
                \App\Support\Audit::log($removal->tenant_id, $removal->requested_by ?? 'system',
                    'device.removal_completed', 'success', null,
                    ['hostname' => $removal->hostname, 'reason' => $removal->reason]);
            } else {
                $failedSteps = collect(self::STEPS)->filter(fn ($s) => ($status[$s] ?? null) === 'failed')->values();
                if ($failedSteps->isNotEmpty()) {
                    \App\Support\Health\OperatorAlert::send('Device removal incomplete', [
                        ['level' => 'warning', 'area' => 'device-removal',
                         'message' => "tenant={$removal->tenant_id} hostname={$removal->hostname} failed_steps=" . $failedSteps->implode(',')],
                    ]);
                }
            }
        }

        return self::SUCCESS;
    }

    private function runStep(string $step, object $removal): string
    {
        return match ($step) {
            'backup_selections' => $this->disableBackupSelections($removal),
            'headscale' => $this->queueHeadscaleRevocation($removal),
            'role_credentials' => $this->deleteRoleCredentials($removal),
            'zabbix' => $this->deleteZabbixHost($removal),
            'wazuh' => $this->deleteWazuhAgent($removal),
            'group_membership' => $this->deleteGroupMembership($removal),
            'device_credential' => $this->revokeDeviceCredential($removal),
            'threat_intel_health' => $this->deleteThreatIntelHealth($removal),
            'fleet' => $this->deleteFleetHost($removal),
        };
    }

    private function disableBackupSelections(object $removal): string
    {
        $updated = DB::table('backup_selections')
            ->where('tenant_id', $removal->tenant_id)
            ->where('fleet_host_id', $removal->fleet_host_id)
            ->update(['enabled' => false, 'updated_at' => now()]);

        return $updated > 0 ? 'done' : 'skipped';
    }

    private function queueHeadscaleRevocation(object $removal): string
    {
        $alreadyQueued = DB::table('headscale_revocation_queue')
            ->where('tenant_id', $removal->tenant_id)
            ->where('hostname', $removal->hostname)
            ->where('status', 'pending')
            ->exists();

        if (!$alreadyQueued) {
            DB::table('headscale_revocation_queue')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id' => $removal->tenant_id,
                'hostname' => $removal->hostname,
                'status' => 'pending',
                'requested_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return 'done';
    }

    private function revokeDeviceCredential(object $removal): string
    {
        // Soft-deletes (revoked_at) rather than removing the row --
        // matches the reasoning already applied to lost/stolen removals
        // keeping their audit trail. A revoked credential must still be
        // checkable later ("was this device ever authorised, and when
        // was it cut off") rather than silently vanishing.
        // REAL BUG FOUND 2026-08-24, the SEVENTH occurrence of this exact
        // mismatch in this project (see /enroll/policy-result's own comment):
        // device_removals.hostname holds FLEET's name, which on Windows is the
        // FQDN (WIN-2UQ47DD9KL4.AICEXPERTS.LOCAL), while device_credentials
        // stores what the ENROLLMENT SCRIPT sent -- $env:COMPUTERNAME, the
        // SHORT name. An exact match therefore never fired on any Windows
        // removal, and the step reported 'skipped' rather than failing, so
        // nothing looked wrong. Confirmed live: two decommissioned Windows
        // devices still held valid, unrevoked credentials -- credentials that
        // can still fetch policy scripts and post health reports.
        //
        // Compared on the SHORT name at both ends. Linux hostnames are already
        // short, so this is a no-op there.
        $shortName = strtolower(explode('.', (string) $removal->hostname)[0]);

        $updated = DB::table('device_credentials')
            ->where('tenant_id', $removal->tenant_id)
            ->whereRaw("lower(split_part(hostname, '.', 1)) = ?", [$shortName])
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        return $updated > 0 ? 'done' : 'skipped';
    }


    private function deleteRoleCredentials(object $removal): string
    {
        $deleted = DB::table('tenant_role_credentials')
            ->where('tenant_id', $removal->tenant_id)
            ->where('fleet_host_id', $removal->fleet_host_id)
            ->delete();

        return $deleted > 0 ? 'done' : 'skipped';
    }

    /**
     * REAL GAP FOUND 2026-08-24: a device that had already been decommissioned
     * successfully wrote a threat-intel health row. WIN-2UQ47DD9KL4 reported at
     * 14:15 and was removed from the portal at 14:28, and its row survived the
     * removal -- there was no step here to clear it.
     *
     * Left in place, a stale row makes a decommissioned device eligible for the
     * weekly silence alert (obaits:alert-threat-intel-silence), producing a
     * false alarm about a device that is gone on purpose.
     *
     * DELETED rather than soft-deleted, deliberately, unlike the device
     * credential above. A revoked credential answers a real audit question --
     * was this device ever authorised, and when was it cut off. Health
     * telemetry answers no such question once the device is gone.
     *
     * Short-name matched for the same reason as revokeDeviceCredential(): these
     * rows store the name the DEVICE reported, which on Windows is the short
     * $env:COMPUTERNAME, while device_removals.hostname holds Fleet's FQDN.
     */
    private function deleteThreatIntelHealth(object $removal): string
    {
        $shortName = strtolower(explode('.', (string) $removal->hostname)[0]);

        $deleted = DB::table('threat_intel_device_reports')
            ->where('tenant_id', $removal->tenant_id)
            ->whereRaw("lower(split_part(hostname, '.', 1)) = ?", [$shortName])
            ->delete();

        return $deleted > 0 ? 'done' : 'skipped';
    }

    private function deleteZabbixHost(object $removal): string
    {
        $groupId = DB::table('tenant_artifacts')
            ->where('tenant_id', $removal->tenant_id)
            ->where('tool', 'zabbix')->where('artifact_type', 'host_group')
            ->value('external_id');

        if (!$groupId) {
            return 'skipped';
        }

        $zabbix = app(\App\Clients\ZabbixClient::class);
        $hostId = $zabbix->findHostIdByName((int) $groupId, $removal->hostname);

        if (!$hostId) {
            return 'skipped';
        }

        $zabbix->deleteHost($hostId);
        return 'done';
    }

    private function deleteWazuhAgent(object $removal): string
    {
        $wazuhGroup = DB::table('tenant_artifacts')
            ->where('tenant_id', $removal->tenant_id)
            ->where('tool', 'wazuh')->where('artifact_type', 'agent_group')
            ->value('external_id');

        if (!$wazuhGroup) {
            return 'skipped';
        }

        $wazuh = app(\App\Clients\WazuhClient::class);
        $token = $wazuh->token();
        $agent = $wazuh->findAgentInGroup($token, $wazuhGroup, $removal->hostname);

        if (!$agent || !($agent['id'] ?? null)) {
            return 'skipped';
        }

        $wazuh->deleteAgentById($token, $agent['id']);
        return 'done';
    }

    private function deleteGroupMembership(object $removal): string
    {
        $deleted = DB::table('device_group_members')
            ->where('tenant_id', $removal->tenant_id)
            ->where('fleet_host_id', $removal->fleet_host_id)
            ->delete();

        return $deleted > 0 ? 'done' : 'skipped';
    }

    private function deleteFleetHost(object $removal): string
    {
        app(\App\Clients\FleetClient::class)->deleteHost((int) $removal->fleet_host_id);
        return 'done';
    }
}
