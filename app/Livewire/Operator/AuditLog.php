<?php

namespace App\Livewire\Operator;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

#[Layout('components.layouts.operator')]
class AuditLog extends Component
{
    use WithPagination;

    public string $search = '';
    public string $actionFilter = '';

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingActionFilter()
    {
        $this->resetPage();
    }

    public function render()
    {
        // Distinct action types, for the filter dropdown.
        $actions = DB::table('operator_audit')
            ->select('action')->distinct()->orderBy('action')->pluck('action');

        $entries = DB::table('operator_audit')
            ->leftJoin('tenants', 'tenants.id', '=', 'operator_audit.target_tenant_id')
            ->select('operator_audit.*', 'tenants.organization_name')
            ->when($this->actionFilter, fn ($q) =>
                $q->where('operator_audit.action', $this->actionFilter))
            ->when($this->search, fn ($q) =>
                $q->where(function ($w) {
                    $w->where('operator_audit.actor', 'ilike', "%{$this->search}%")
                      ->orWhere('operator_audit.reason', 'ilike', "%{$this->search}%")
                      ->orWhere('tenants.organization_name', 'ilike', "%{$this->search}%");
                }))
            ->orderByDesc('operator_audit.created_at')
            ->paginate(50);

        return view('livewire.operator.audit', compact('entries', 'actions'))
            ->layout('components.layouts.app');
    }
}