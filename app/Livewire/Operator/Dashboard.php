<?php
namespace App\Livewire\Operator;
 
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
 
#[Layout('components.layouts.operator')]
class Dashboard extends Component
{
    public string $search = '';
 
    public function render()
    {
        $tenants = DB::table('tenants')
            ->when($this->search, fn ($q) => $q->where('organization_name', 'ilike', "%{$this->search}%")
                ->orWhere('primary_domain', 'ilike', "%{$this->search}%"))
            ->orderBy('organization_name')
            ->get()
            ->map(function ($t) {
                // Metadata counts only — never the data itself.
                $t->users     = DB::table('tenant_users')->where('tenant_id', $t->id)->count();
                $t->findings  = DB::table('findings')->where('tenant_id', $t->id)->count();
                $t->documents = DB::table('tenant_documents')->where('tenant_id', $t->id)->count();
                return $t;
            });
 
        return view('livewire.operator.dashboard', compact('tenants'))
            ->layout('components.layouts.app');
    }
 
    public function suspend(string $tenantId)
    {
        DB::table('tenants')->where('id', $tenantId)->update(['status' => 'suspended', 'updated_at' => now()]);
        \App\Support\OperatorAudit::log('operator.tenant_suspended', ['tenant_id' => $tenantId]);
        session()->flash('op_ok', __('Tenant suspended.'));
    }
 
    public function reactivate(string $tenantId)
    {
        DB::table('tenants')->where('id', $tenantId)->update(['status' => 'active', 'updated_at' => now()]);
        \App\Support\OperatorAudit::log('operator.tenant_reactivated', ['tenant_id' => $tenantId]);
        session()->flash('op_ok', __('Tenant reactivated.'));
    }
}
