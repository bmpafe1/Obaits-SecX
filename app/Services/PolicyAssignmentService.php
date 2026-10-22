<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Real, shared policy-assignment logic -- extracted 2026-08-23 from
 * GroupPolicies (the portal's own Livewire component) so the External
 * API's PolicyAssignmentsController can reuse the EXACT same real
 * logic, matching this project's established "one real service, zero
 * duplicated logic" pattern (see Scanner.php / ScansController).
 *
 * Real, careful extraction: every method here is a faithful, behavior-
 * preserving copy of GroupPolicies's own real private methods, with
 * $this->tenantId / session('auth_email') replaced by real, explicit
 * parameters -- nothing about the actual real logic changed.
 */
class PolicyAssignmentService
{
    /**
     * Real, shared "assign a policy to a device group" logic. Returns
     * a real, structured array: either ['assignment_id' => ...] on
     * success, or ['error' => '...'] on validation failure. Callers
     * decide how to surface that error for their own real context
     * (Livewire's addError() vs a real, structured API JSON response).
     */
    public function assign(string $tenantId, string $policyId, string $groupId, ?string $rawParameters, string $actorEmail): array
    {
        $policy = DB::table('policies')->where('id', $policyId)->first();
        $group = DB::table('device_groups')->where('id', $groupId)->where('tenant_id', $tenantId)->first();
        if (!$policy || !$group) {
            // REAL, IMPORTANT: 'field' matches GroupPolicies's own
            // original real behavior exactly -- this specific error
            // was always shown under assignPolicyId, not a generic
            // field, and callers need to preserve that.
            return ['error' => 'Please choose a real policy and device group.', 'field' => 'assignPolicyId'];
        }

        $parametersJson = null;
        if ($policy->requires_parameters) {
            $rawLines = array_values(array_filter(array_map('trim', explode("\n", (string) $rawParameters)), fn ($l) => $l !== ''));
            if (empty($rawLines)) {
                // REAL, IMPORTANT: this specific error was always shown
                // under assignParameters (a DIFFERENT field), not
                // assignPolicyId -- preserved exactly.
                return ['error' => 'This policy needs at least one real value (one per line).', 'field' => 'assignParameters'];
            }
            $parametersJson = json_encode($rawLines);
        }

        $existing = DB::table('policy_assignments')
            ->where('tenant_id', $tenantId)
            ->where('policy_id', $policy->id)
            ->where('device_group_id', $group->id)
            ->first();

        if ($existing) {
            $assignmentId = $existing->id;
            if ($parametersJson !== null) {
                DB::table('policy_assignments')->where('id', $assignmentId)->update([
                    'parameters' => $parametersJson,
                    'updated_at' => now(),
                ]);
            }
        } else {
            $assignmentId = (string) Str::uuid();
            DB::table('policy_assignments')->insert([
                'id' => $assignmentId,
                'tenant_id' => $tenantId,
                'policy_id' => $policy->id,
                'policy_version' => $policy->version,
                'device_group_id' => $group->id,
                'mode' => 'audit',
                'parameters' => $parametersJson,
                'created_by' => $actorEmail,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        \App\Support\Audit::log($tenantId, $actorEmail, 'group_policy.assigned', 'success', null,
            ['policy' => $policy->name, 'group' => $group->name]);

        $this->runCheckNow($tenantId, $assignmentId, $group->id, $actorEmail);

        return ['assignment_id' => $assignmentId];
    }

    public function platformMap(string $tenantId): array
    {
        $fleetSecret = DB::table('tenant_artifacts')
            ->where('tenant_id', $tenantId)->where('tool', 'fleet')
            ->where('artifact_type', 'enroll_secret')->value('external_id');
        if (!$fleetSecret) return [];
        try {
            $hosts = app(\App\Clients\FleetClient::class)->hostsForTenant($fleetSecret);
        } catch (\Throwable $e) {
            return [];
        }
        $map = [];
        foreach ($hosts as $h) {
            $id = (int) ($h['id'] ?? 0);
            if ($id) $map[$id] = \App\Support\PolicyRunner::normalizePlatform($h['platform'] ?? '');
        }
        return $map;
    }

    public function excludedHostIds(string $assignmentId): array
    {
        return DB::table('policy_exclusions')
            ->where('policy_assignment_id', $assignmentId)
            ->pluck('fleet_host_id')->all();
    }

    private function runCheckNow(string $tenantId, string $assignmentId, string $groupId, string $actorEmail): void
    {
        $platformById = $this->platformMap($tenantId);
        $excluded = $this->excludedHostIds($assignmentId);
        $members = DB::table('device_group_members')->where('device_group_id', $groupId)
            ->whereNotIn('fleet_host_id', $excluded)->get();
        foreach ($members as $m) {
            \App\Support\PolicyRunner::checkDevice(
                $assignmentId,
                (int) $m->fleet_host_id,
                $m->hostname,
                $platformById[(int) $m->fleet_host_id] ?? '',
                $actorEmail
            );
        }
    }
}
