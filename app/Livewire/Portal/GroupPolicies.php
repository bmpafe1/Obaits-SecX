<?php

namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Perm;

/**
 * "Group Policies" -- user-facing name, chosen 2026-08-07 specifically to
 * avoid colliding with this platform's OTHER, pre-existing use of
 * "Policies" (compliance/governance documents, e.g. an "Information
 * Security Policy" required for an audit). The underlying tables/classes
 * (policies, PolicyRunner, etc.) were already migrated and verified
 * against a real device before this rename decision, so ONLY user-facing
 * names changed -- route, nav label, this class, its view.
 *
 * First slice: audit mode only. Assigning a policy runs its check script
 * against every current member of the chosen device group and shows the
 * real, POSTed-back result per device -- it can never change anything on
 * a device. Enforce mode has no code path anywhere in this feature yet.
 */
#[Layout('components.layouts.portal')]
class GroupPolicies extends Component
{
    public string $tenantId;
    public string $assignPolicyId = '';
    public string $assignGroupId = '';
    // REAL FEATURE 2026-08-20, fixing a real gap found while building
    // the External API: FOUR real, fully-built, fully-tested Group
    // Policies (IP Block, Website Block, Port Allow, Port Block) had
    // NO portal UI to actually assign them at all -- every real test
    // tonight was done via direct tinker/database manipulation, meaning
    // a real tenant using the actual product had no way to use these
    // features. One value per line (an IP, a domain, or a
    // "port/protocol" string depending on the chosen policy).
    public string $assignParameters = '';
    public ?string $confirmingEnforceId = null;
    public int $confirmingEnforceCount = 0;
    public ?string $confirmingDisableId = null;

    // Search/filter -- render-time only, over the already-fetched
    // assignment collection. No new queries: this list is per-tenant and
    // expected to stay small enough (tens, not thousands) that filtering
    // in PHP is simpler and fast enough, revisited only if that stops
    // being true.
    public string $search = '';
    public string $statusFilter = '';   // '' | needs_attention | enforced | audit
    public string $groupFilter = '';    // '' | device_group_id

    // Collapsed by default -- one assignment expanded at a time, same
    // interaction pattern as DeviceGroups::toggleExpanded(), for visual
    // consistency across the portal.
    public ?string $expandedAssignmentId = null;

    // Populated once per render() from the already-fetched $assignments
    // collection -- assignment id => policy's non_compliant_prefix (or
    // null). Not persisted, not a Livewire-tracked property in the
    // wire:model sense, just a request-scoped lookup cache.
    private array $assignmentPrefixes = [];

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); }
    }

    // REAL FEATURE 2026-08-13: the kill switch. Per-tenant, deliberately
    // does NOT touch any assignment's mode or trigger a revert -- it
    // only suspends FUTURE remediate/revert calls (checked once, in
    // PolicyRunner::run(), the single shared launcher every action funnels
    // through). Resuming picks back up exactly where things were.
    public function pauseEnforcement()
    {
        Perm::require('enforce_policies');
        DB::table('tenants')->where('id', $this->tenantId)->update([
            'policy_enforcement_paused_at' => now(),
            'policy_enforcement_paused_by' => session('auth_email'),
        ]);
        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'group_policy.enforcement_paused', 'success', null, []);
    }

    public function resumeEnforcement()
    {
        Perm::require('enforce_policies');
        DB::table('tenants')->where('id', $this->tenantId)->update([
            'policy_enforcement_paused_at' => null,
            'policy_enforcement_paused_by' => null,
        ]);
        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'group_policy.enforcement_resumed', 'success', null, []);
    }

    public function assignPolicy()
    {
        Perm::require('manage_policies');
        $this->validate([
            'assignPolicyId' => 'required|uuid',
            'assignGroupId' => 'required|uuid',
        ]);
        // REAL FIX 2026-08-23: this method's real logic was extracted
        // into App\Services\PolicyAssignmentService, so the External
        // API's PolicyAssignmentsController can reuse the EXACT same
        // real logic (same pattern as Scanner/ScansController) --
        // confirmed byte-for-byte behavior-preserving, including which
        // real form field each specific error attaches to.
        $result = app(\App\Services\PolicyAssignmentService::class)->assign(
            $this->tenantId, $this->assignPolicyId, $this->assignGroupId, $this->assignParameters, session('auth_email')
        );
        if (isset($result['error'])) {
            $this->addError($result['field'], __($result['error']));
            return;
        }
        $this->assignParameters = '';
        $this->reset(['assignPolicyId', 'assignGroupId']);
    }

    public function rerunCheck(string $assignmentId)
    {
        Perm::require('manage_policies');
        $assignment = DB::table('policy_assignments')
            ->where('id', $assignmentId)->where('tenant_id', $this->tenantId)->first();
        if (!$assignment) return;

        $this->runCheckNow($assignmentId, $assignment->device_group_id);
    }

    public function removeAssignment(string $assignmentId)
    {
        Perm::require('manage_policies');
        $assignment = DB::table('policy_assignments')
            ->where('id', $assignmentId)->where('tenant_id', $this->tenantId)->first();
        if (!$assignment) return;

        DB::table('policy_assignments')->where('id', $assignmentId)->delete();
        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'group_policy.removed', 'success', null, []);
    }

    public function toggleExpanded(string $assignmentId)
    {
        $this->expandedAssignmentId = $this->expandedAssignmentId === $assignmentId ? null : $assignmentId;
    }

    public function clearFilters()
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->groupFilter = '';
    }

    // Step 1 of the confirm gate: name the REAL count before anything
    // runs, per the design decision that a bare toggle is not acceptable
    // for an action that changes real devices.
    public function confirmEnableEnforcement(string $assignmentId)
    {
        Perm::require('enforce_policies');
        $this->confirmingEnforceId = $assignmentId;
        // REAL GENERALIZATION FIX 2026-08-12: was a hardcoded exact match
        // on 'firewall_disabled'. Each policy now declares its own
        // non_compliant_prefix; matched here via LIKE so a policy whose
        // result carries extra detail (e.g. 'updates_pending:7') still
        // counts as non-compliant, not just an exact string.
        $prefix = $this->nonCompliantPrefix($assignmentId);
        $this->confirmingEnforceCount = $prefix ? DB::table('policy_runs')
            ->where('policy_assignment_id', $assignmentId)
            ->where('status', 'success')
            ->where('result', 'like', $prefix . '%')
            ->count() : 0;
    }

    public function cancelEnableEnforcement()
    {
        $this->confirmingEnforceId = null;
        $this->confirmingEnforceCount = 0;
    }

    // Step 2: the real action. Only devices CURRENTLY showing
    // firewall_disabled are remediated -- a device already compliant is
    // left alone rather than re-triggering a no-op script against it,
    // which would just add unnecessary Fleet host_busy contention.
    public function enableEnforcement(string $assignmentId)
    {
        Perm::require('enforce_policies');
        $assignment = DB::table('policy_assignments')
            ->where('id', $assignmentId)->where('tenant_id', $this->tenantId)->first();
        if (!$assignment) return;

        $platformById = $this->platformMap();
        $prefix = $this->nonCompliantPrefix($assignmentId);
        $excluded = $this->excludedHostIds($assignmentId);
        $toRemediate = $prefix ? DB::table('policy_runs')
            ->where('policy_assignment_id', $assignmentId)
            ->where('status', 'success')
            ->where('result', 'like', $prefix . '%')
            ->whereNotIn('fleet_host_id', $excluded)
            ->get() : collect();

        foreach ($toRemediate as $run) {
            \App\Support\PolicyRunner::remediateDevice(
                $assignmentId,
                (int) $run->fleet_host_id,
                $run->hostname,
                $platformById[(int) $run->fleet_host_id] ?? $run->platform ?? '',
                session('auth_email')
            );
        }

        DB::table('policy_assignments')->where('id', $assignmentId)->update([
            'mode' => 'enforce', 'updated_at' => now(),
        ]);

        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'group_policy.enforce_enabled', 'success', null,
            ['assignment_id' => $assignmentId, 'devices_remediated' => $toRemediate->count()]);

        $this->confirmingEnforceId = null;
        $this->confirmingEnforceCount = 0;
    }

    public function confirmDisableEnforcement(string $assignmentId)
    {
        Perm::require('enforce_policies');
        $this->confirmingDisableId = $assignmentId;
    }

    public function cancelDisableEnforcement()
    {
        $this->confirmingDisableId = null;
    }

    // Only reverts devices where PRIOR_STATE was actually captured as
    // firewall_disabled -- i.e. we genuinely changed something. A device
    // that was already firewall_enabled before we ever touched it has no
    // prior_state indicating a change, and must never be turned off by
    // this action.
    public function disableEnforcement(string $assignmentId)
    {
        Perm::require('enforce_policies');
        $assignment = DB::table('policy_assignments')
            ->where('id', $assignmentId)->where('tenant_id', $this->tenantId)->first();
        if (!$assignment) return;

        $platformById = $this->platformMap();
        // REAL GENERALIZATION FIX 2026-08-12: was a hardcoded exact match
        // on prior_state = 'firewall_disabled'. prior_state is captured
        // VERBATIM from whatever the check found before remediation ran
        // (not compared against a declared pattern the way 'result' is)
        // -- so the correct generalized test is simply "was anything
        // recorded at all", meaning we genuinely changed this device.
        //
        // NOTE, deliberate: an EXCLUDED device is still reverted here if
        // it has real prior_state -- exclusion means "don't act on this
        // device going forward", not "abandon it in a state we changed
        // it to and never offer to put it back". Revert is the one
        // action that's always safe to still apply to an excluded
        // device, since it only ever restores what was there before.
        $toRevert = DB::table('policy_runs')
            ->where('policy_assignment_id', $assignmentId)
            ->whereNotNull('prior_state')
            ->get();

        foreach ($toRevert as $run) {
            \App\Support\PolicyRunner::revertDevice(
                $assignmentId,
                (int) $run->fleet_host_id,
                $run->hostname,
                $platformById[(int) $run->fleet_host_id] ?? $run->platform ?? '',
                session('auth_email')
            );
        }

        DB::table('policy_assignments')->where('id', $assignmentId)->update([
            'mode' => 'audit', 'updated_at' => now(),
        ]);

        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'group_policy.enforce_disabled', 'success', null,
            ['assignment_id' => $assignmentId, 'devices_reverted' => $toRevert->count()]);

        $this->confirmingDisableId = null;
    }

    // Real, current platform for every real device this tenant has --
    // same shape already proven in DeviceGroups::platformMap() earlier
    // today, fetched ONCE per call rather than per member (same N+1 fix
    // already applied there, not repeated here).
    private function platformMap(): array
    {
        $fleetSecret = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)->where('tool', 'fleet')
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

    // Single-assignment lookup, for the two call sites that only ever
    // have one assignment id in hand (confirmEnableEnforcement,
    // enableEnforcement) rather than the full render()-time collection.
    private function nonCompliantPrefix(string $assignmentId): ?string
    {
        return DB::table('policy_assignments')
            ->join('policies', 'policy_assignments.policy_id', '=', 'policies.id')
            ->where('policy_assignments.id', $assignmentId)
            ->value('policies.non_compliant_prefix');
    }

    // REAL FEATURE 2026-08-13: real device exclusions, scoped per
    // assignment. Motivated directly by a real incident this week (a
    // test assignment swept a real production device into a real
    // enforce action with no way to have excluded it in advance).
    // Returns the real, current list of excluded fleet_host_ids for one
    // assignment -- checked before EVERY device-touching action
    // (check, enforce, revert), never just at assignment time, so an
    // exclusion added after enforcement is already running still takes
    // effect on the very next action.
    private function excludedHostIds(string $assignmentId): array
    {
        return DB::table('policy_exclusions')
            ->where('policy_assignment_id', $assignmentId)
            ->pluck('fleet_host_id')->all();
    }

    public function excludeDevice(string $assignmentId, int $fleetHostId, string $hostname)
    {
        Perm::require('manage_policies');
        $assignment = DB::table('policy_assignments')
            ->where('id', $assignmentId)->where('tenant_id', $this->tenantId)->first();
        if (!$assignment) return;

        DB::table('policy_exclusions')->updateOrInsert(
            ['policy_assignment_id' => $assignmentId, 'fleet_host_id' => $fleetHostId],
            [
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantId,
                'hostname' => $hostname,
                'created_by' => session('auth_email'),
                'created_at' => now(), 'updated_at' => now(),
            ]
        );

        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'group_policy.device_excluded', 'success', null,
            ['assignment_id' => $assignmentId, 'fleet_host_id' => $fleetHostId, 'hostname' => $hostname]);
    }

    public function includeDevice(string $assignmentId, int $fleetHostId)
    {
        Perm::require('manage_policies');
        DB::table('policy_exclusions')
            ->where('policy_assignment_id', $assignmentId)
            ->where('fleet_host_id', $fleetHostId)
            ->delete();

        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'group_policy.device_included', 'success', null,
            ['assignment_id' => $assignmentId, 'fleet_host_id' => $fleetHostId]);
    }

    private function runCheckNow(string $assignmentId, string $groupId): void
    {
        $platformById = $this->platformMap();
        $excluded = $this->excludedHostIds($assignmentId);
        $members = DB::table('device_group_members')->where('device_group_id', $groupId)
            ->whereNotIn('fleet_host_id', $excluded)->get();

        foreach ($members as $m) {
            \App\Support\PolicyRunner::checkDevice(
                $assignmentId,
                (int) $m->fleet_host_id,
                $m->hostname,
                $platformById[(int) $m->fleet_host_id] ?? '',
                session('auth_email')
            );
        }
    }

    public function render()
    {
        $tenant = DB::table('tenants')->where('id', $this->tenantId)
            ->first(['policy_enforcement_paused_at', 'policy_enforcement_paused_by']);

        $policies = DB::table('policies')
            ->where(function ($q) {
                $q->whereNull('owner_tenant_id')->orWhere('owner_tenant_id', $this->tenantId);
            })
            ->orderBy('name')
            ->get();

        $groups = DB::table('device_groups')
            ->where('tenant_id', $this->tenantId)
            ->orderBy('name')
            ->get();

        $assignments = DB::table('policy_assignments')
            ->join('policies', 'policy_assignments.policy_id', '=', 'policies.id')
            ->join('device_groups', 'policy_assignments.device_group_id', '=', 'device_groups.id')
            ->where('policy_assignments.tenant_id', $this->tenantId)
            ->orderByDesc('policy_assignments.created_at')
            ->get([
                'policy_assignments.id',
                'policy_assignments.mode',
                'policy_assignments.created_at',
                'policies.name as policy_name',
                'policies.description as policy_description',
                'policies.reversibility as policy_reversibility',
                'policies.non_compliant_prefix as policy_non_compliant_prefix',
                'device_groups.id as group_id',
                'device_groups.name as group_name',
            ]);

        // Cheap lookup map, built from the SAME $assignments collection
        // already fetched above -- no extra query. Used by the
        // needs_attention filter closure below.
        $this->assignmentPrefixes = $assignments->pluck('policy_non_compliant_prefix', 'id')->all();

        // Real, current exclusions for every assignment shown, grouped
        // so the view can render an "(excluded)" tag and the
        // exclude/include button per device row without its own N+1
        // query per assignment -- same pattern as $runsByAssignment.
        $exclusionsByAssignment = DB::table('policy_exclusions')
            ->where('tenant_id', $this->tenantId)
            ->get()
            ->groupBy('policy_assignment_id')
            ->map(fn ($rows) => $rows->pluck('fleet_host_id')->all());

        // Real per-device results for every assignment shown, grouped so
        // the view can render "3 of 4 devices: firewall_enabled" without
        // its own N+1 query per assignment.
        $runsByAssignment = DB::table('policy_runs')
            ->where('tenant_id', $this->tenantId)
            ->get()
            ->groupBy('policy_assignment_id');

        // Filtered AFTER runsByAssignment is built, since statusFilter
        // depends on each assignment's own run results.
        $filtered = $assignments->filter(function ($a) use ($runsByAssignment) {
            if ($this->search !== '' && !str_contains(strtolower($a->policy_name), strtolower($this->search))) {
                return false;
            }
            if ($this->groupFilter !== '' && $a->group_id !== $this->groupFilter) {
                return false;
            }
            if ($this->statusFilter !== '') {
                $runs = $runsByAssignment->get($a->id, collect());
                $isEnforced = $a->mode === 'enforce';
                // REAL GENERALIZATION FIX 2026-08-12: was a hardcoded
                // exact match on 'firewall_disabled'. $a already carries
                // policy_reversibility from the join above; the prefix
                // needs its own lookup since it wasn't originally
                // selected -- kept as a property here rather than
                // re-querying, since $assignments is fetched once.
                $prefix = $this->assignmentPrefixes[$a->id] ?? null;
                $nonCompliant = $prefix ? $runs->where('status', 'success')
                    ->filter(fn ($r) => str_starts_with((string) $r->result, $prefix))->count() : 0;
                if ($this->statusFilter === 'enforced' && !$isEnforced) return false;
                if ($this->statusFilter === 'audit' && $isEnforced) return false;
                if ($this->statusFilter === 'needs_attention' && $nonCompliant === 0) return false;
            }
            return true;
        })->values();

        return view('livewire.portal.group-policies', [
            'policies' => $policies,
            'groups' => $groups,
            'assignments' => $filtered,
            'totalAssignmentCount' => $assignments->count(),
            'runsByAssignment' => $runsByAssignment,
            'exclusionsByAssignment' => $exclusionsByAssignment,
            'enforcementPausedAt' => $tenant->policy_enforcement_paused_at ?? null,
            'enforcementPausedBy' => $tenant->policy_enforcement_paused_by ?? null,
        ]);
    }
}
