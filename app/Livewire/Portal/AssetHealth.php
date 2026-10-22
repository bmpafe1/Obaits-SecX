<?php

namespace App\Livewire\Portal;

use Livewire\Component;
use Illuminate\Support\Facades\DB;

class AssetHealth extends Component
{
    public string $tenantId;

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { return redirect('/login'); }
    }

    public function render()
    {
        // Real, per-tenant isolation boundary -- same pattern as every
        // other tool integration: look up THIS tenant's own real Zabbix
        // host group id, never trust a group id passed any other way.
        $groupId = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)
            ->where('tool', 'zabbix')
            ->where('artifact_type', 'host_group')
            ->value('external_id');

        $devices = [];
        $reachable = true;

        if ($groupId) {
            try {
                $devices = app(\App\Clients\ZabbixClient::class)->healthForGroup((int) $groupId);
            } catch (\Throwable $e) {
                \Log::warning('AssetHealth page: healthForGroup failed', ['error' => $e->getMessage()]);
                $reachable = false;
            }
        }

        return view('livewire.portal.asset-health', [
            'devices'   => $devices,
            'hasGroup'  => (bool) $groupId,
            'reachable' => $reachable,
        ])->layout('components.layouts.portal');
    }
}
