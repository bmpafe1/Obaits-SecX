<?php
namespace App\Livewire\Operator;
 
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
 
#[Layout('components.layouts.operator')]
class TenantData extends Component
{
    public string $tenantId;
    public ?string $reason = null;      // must be given before any data is shown
    public bool $unlocked = false;
 
    public function mount(string $tenantId)
    {
        $this->tenantId = $tenantId;
    }
 
    // The operator must state WHY before any tenant data is displayed.
    public function unlock()
    {
        $reason = trim((string) $this->reason);
        if (strlen($reason) < 10) {
            session()->flash('op_err', __('Please give a clear reason (at least a sentence) for accessing this tenant\'s data.'));
            return;
        }
 
        $this->unlocked = true;
        \App\Support\OperatorAudit::log('operator.viewed_tenant_data', [
            'tenant_id' => $this->tenantId,
            'reason'    => $reason,
        ]);
    }
 
    public function render()
    {
        $tenant = DB::table('tenants')->where('id', $this->tenantId)->first();
 
        // Nothing loads until a reason is given and access is logged.
        $data = null;
        if ($this->unlocked) {
            $data = [
                'findings'  => DB::table('findings')->where('tenant_id', $this->tenantId)
                                 ->orderByDesc('criticality')->limit(100)->get(),
                'documents' => DB::table('tenant_documents')->where('tenant_id', $this->tenantId)
                                 ->orderByDesc('created_at')->limit(100)->get(),
                'scans'     => DB::table('scans')->where('tenant_id', $this->tenantId)
                                 ->orderByDesc('created_at')->limit(50)->get(),
            ];
        }
 
        return view('livewire.operator.tenant-data', compact('tenant', 'data'))
            ->layout('components.layouts.app');
    }
}
