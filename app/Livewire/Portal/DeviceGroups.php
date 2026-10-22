<?php

namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Perm;

#[Layout('components.layouts.portal')]
class DeviceGroups extends Component
{
    public string $tenantId;
    public string $newGroupName = '';
    // Real Phase C: optional auto-assign rule, chosen at group creation
    // (or edited later). Matches Enroll.php's own real TIER1_GROUPS
    // taxonomy exactly.
    public string $newGroupAutoAssign = '';
    public const AUTO_ASSIGN_OPTIONS = [
        '' => 'None (manual assignment only)',
        'windows-client' => 'Windows workstations',
        'windows-dc' => 'Windows domain controllers',
        'linux-servers' => 'Linux servers',
        'linux-desktop' => 'Linux desktops',
        'macos-client' => 'macOS',
    ];
    public ?string $expandedGroupId = null;
    // Real, per-group device search/filter -- confirmed necessary by
    // Brian's own direct observation: a tenant with hundreds of real
    // devices should never have to scroll an unbounded checkbox list.
    public string $memberSearch = '';
    public string $memberPlatformFilter = '';

    // Real, Phase B: assigning software to a group and triggering the
    // real push.
    public string $assignSoftwareFileId = '';
    public string $assignInstallCommand = '';

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); }
    }

    public function createGroup()
    {
        Perm::require('manage_software');
        $this->validate(['newGroupName' => 'required|string|max:100']);

        $exists = DB::table('device_groups')
            ->where('tenant_id', $this->tenantId)
            ->where('name', $this->newGroupName)
            ->exists();
        if ($exists) {
            $this->addError('newGroupName', __('A group with this name already exists.'));
            return;
        }

        DB::table('device_groups')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'name' => $this->newGroupName,
            'auto_assign_wazuh_group' => $this->newGroupAutoAssign ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'device_group.created', 'success', null, ['name' => $this->newGroupName]);
        $this->reset(['newGroupName', 'newGroupAutoAssign']);
    }

    public function deleteGroup(string $groupId)
    {
        Perm::require('manage_software');
        $group = DB::table('device_groups')->where('id', $groupId)->where('tenant_id', $this->tenantId)->first();
        if (!$group) return;

        DB::table('device_groups')->where('id', $groupId)->where('tenant_id', $this->tenantId)->delete();
        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'device_group.deleted', 'success', null, ['name' => $group->name]);
    }

    public function toggleExpanded(string $groupId)
    {
        $this->expandedGroupId = $this->expandedGroupId === $groupId ? null : $groupId;
        $this->reset(['memberSearch', 'memberPlatformFilter']);
    }

    public function toggleMember(string $groupId, int $fleetHostId, string $hostname)
    {
        Perm::require('manage_software');
        $group = DB::table('device_groups')->where('id', $groupId)->where('tenant_id', $this->tenantId)->first();
        if (!$group) return;

        $existing = DB::table('device_group_members')
            ->where('device_group_id', $groupId)
            ->where('fleet_host_id', $fleetHostId)
            ->first();

        if ($existing) {
            DB::table('device_group_members')->where('id', $existing->id)->delete();
            \App\Support\Audit::log($this->tenantId, session('auth_email'), 'device_group.member_removed', 'success', null,
                ['group' => $group->name, 'hostname' => $hostname]);
        } else {
            DB::table('device_group_members')->insert([
                'id' => (string) Str::uuid(),
                'device_group_id' => $groupId,
                'tenant_id' => $this->tenantId,
                'fleet_host_id' => $fleetHostId,
                'hostname' => $hostname,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            \App\Support\Audit::log($this->tenantId, session('auth_email'), 'device_group.member_added', 'success', null,
                ['group' => $group->name, 'hostname' => $hostname]);
        }
    }

    public function updateAutoAssign(string $groupId, string $value)
    {
        \App\Support\Perm::require('manage_software');
        $group = DB::table('device_groups')->where('id', $groupId)->where('tenant_id', $this->tenantId)->first();
        if (!$group) return;

        DB::table('device_groups')->where('id', $groupId)->update([
            'auto_assign_wazuh_group' => $value ?: null,
            'updated_at' => now(),
        ]);
        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'device_group.auto_assign_updated', 'success', null,
            ['group' => $group->name, 'rule' => $value ?: 'none']);
    }

    public function assignSoftware(string $groupId)
    {
        \App\Support\Perm::require('manage_software');
        $group = DB::table('device_groups')->where('id', $groupId)->where('tenant_id', $this->tenantId)->first();
        if (!$group) return;

        $file = DB::table('software_files')
            ->where('id', $this->assignSoftwareFileId)
            ->where('tenant_id', $this->tenantId)
            ->where('scan_status', 'clean')
            ->first();
        if (!$file) {
            $this->addError('assignSoftwareFileId', __('Please choose a real, uploaded installer.'));
            return;
        }

        $isMsi = \App\Support\SoftwarePush::isMsi($file->original_name);
        if (!$isMsi && trim($this->assignInstallCommand) === '') {
            $this->addError('assignInstallCommand', __('This file is not an MSI, so a real, explicit silent-install command is required.'));
            return;
        }

        // REAL BUG FIXED 2026-08-06: this previously used updateOrInsert()
        // with 'id' inside the VALUES array, which genuinely OVERWRITES the
        // existing row's own primary key on a real re-assignment (the old
        // comment here claimed the opposite -- it did not hold).
        // software_install_runs carries a real foreign key to that column, so
        // Postgres rejects the update outright ("update or delete on table
        // violates foreign key constraint") the moment any real install run
        // exists for that pair. Harmless only while no runs existed; genuinely
        // reachable as of today, now that real runs are recorded and polled.
        // Exactly the same updateOrInsert misuse already fixed in
        // SoftwarePush::record() -- worth checking for anywhere else it appears.
        $existingAssignment = DB::table('software_group_assignments')
            ->where('device_group_id', $groupId)
            ->where('software_file_id', $file->id)
            ->first();

        if ($existingAssignment) {
            $realAssignmentId = $existingAssignment->id;
            DB::table('software_group_assignments')->where('id', $realAssignmentId)->update([
                'install_command' => $isMsi ? null : $this->assignInstallCommand,
                'created_by' => session('auth_email'),
                'updated_at' => now(),
            ]);
        } else {
            $realAssignmentId = (string) Str::uuid();
            DB::table('software_group_assignments')->insert([
                'id' => $realAssignmentId,
                'tenant_id' => $this->tenantId,
                'device_group_id' => $groupId,
                'software_file_id' => $file->id,
                'install_command' => $isMsi ? null : $this->assignInstallCommand,
                'created_by' => session('auth_email'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'software.group_assignment.created', 'success', null,
            ['group' => $group->name, 'file' => $file->original_name]);

        // Real, immediate push to every real, current member of this group.
        //
        // REAL N+1 FIXED 2026-08-06: platformForHost() was called once per
        // member INSIDE this loop, and each call fetched the tenant's entire
        // Fleet host list -- a group of 200 real devices meant 200 full Fleet
        // API calls for one assignment. Now fetched once and reused, matching
        // what ReconcileSoftwarePushes already does.
        $platformById = $this->platformMap();
        $members = DB::table('device_group_members')->where('device_group_id', $groupId)->get();
        foreach ($members as $m) {
            \App\Support\SoftwarePush::pushToDevice(
                $realAssignmentId,
                (int) $m->fleet_host_id,
                $m->hostname,
                $platformById[(int) $m->fleet_host_id] ?? '',
                session('auth_email')
            );
        }

        $this->reset(['assignSoftwareFileId', 'assignInstallCommand']);
    }

    public function removeSoftwareAssignment(string $assignmentId)
    {
        \App\Support\Perm::require('manage_software');
        $assignment = DB::table('software_group_assignments')->where('id', $assignmentId)->where('tenant_id', $this->tenantId)->first();
        if (!$assignment) return;

        DB::table('software_group_assignments')->where('id', $assignmentId)->delete();
        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'software.group_assignment.removed', 'success', null, []);
    }

    // Real, current platform for every real device this tenant has, looked up
    // fresh via Fleet rather than trusted from any stale, cached value, since
    // the real install script itself must be built correctly per real OS.
    // Fetched ONCE per caller -- see the N+1 note in assignSoftware().
    private function platformMap(): array
    {
        $fleetSecret = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)->where('tool', 'fleet')
            ->where('artifact_type', 'enroll_secret')->value('external_id');
        if (!$fleetSecret) return [];

        try {
            $hosts = app(\App\Clients\FleetClient::class)->hostsForTenant($fleetSecret);
        } catch (\Throwable $e) {
            // Real, honest degrade -- an empty map means pushToDevice receives
            // an empty platform and builds no script, which is recorded as a
            // real, visible failure rather than a silently wrong install.
            return [];
        }

        $map = [];
        foreach ($hosts as $h) {
            $id = (int) ($h['id'] ?? 0);
            if ($id) $map[$id] = \App\Support\PolicyRunner::normalizePlatform($h['platform'] ?? '');
        }
        return $map;
    }

    public function render()
    {
        // Real, currently enrolled devices for this tenant -- same real
        // Fleet-fetching pattern already proven in Assets.php, reused
        // directly rather than a risky shared-helper refactor right now.
        $devices = [];
        $fleetError = null;
        $fleetSecret = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)->where('tool', 'fleet')
            ->where('artifact_type', 'enroll_secret')->value('external_id');

        if ($fleetSecret) {
            try {
                $devices = app(\App\Clients\FleetClient::class)->hostsForTenant($fleetSecret);
            } catch (\Throwable $e) {
                $fleetError = __('Could not reach the device inventory. Group membership may be incomplete until this is resolved.');
            }
        }

        $groups = DB::table('device_groups')
            ->where('tenant_id', $this->tenantId)
            ->orderBy('name')
            ->get();

        $membersByGroup = DB::table('device_group_members')
            ->where('tenant_id', $this->tenantId)
            ->get()
            ->groupBy('device_group_id');

        // Real, tenant's own clean, uploaded installers -- only ever
        // 'clean' files may be offered for real assignment.
        $cleanFiles = DB::table('software_files')
            ->join('software_catalog', 'software_files.software_id', '=', 'software_catalog.id')
            ->where('software_files.tenant_id', $this->tenantId)
            ->where('software_files.scan_status', 'clean')
            ->orderBy('software_catalog.name')
            ->get(['software_files.id', 'software_files.original_name', 'software_files.version', 'software_catalog.name as software_name']);

        $assignmentsByGroup = DB::table('software_group_assignments')
            ->join('software_files', 'software_group_assignments.software_file_id', '=', 'software_files.id')
            ->join('software_catalog', 'software_files.software_id', '=', 'software_catalog.id')
            ->where('software_group_assignments.tenant_id', $this->tenantId)
            ->get([
                'software_group_assignments.id',
                'software_group_assignments.device_group_id',
                'software_catalog.name as software_name',
                'software_files.original_name',
            ])
            ->groupBy('device_group_id');

        return view('livewire.portal.device-groups', [
            'devices' => $devices,
            'fleetError' => $fleetError,
            'groups' => $groups,
            'membersByGroup' => $membersByGroup,
            'cleanFiles' => $cleanFiles,
            'assignmentsByGroup' => $assignmentsByGroup,
        ]);
    }
}
