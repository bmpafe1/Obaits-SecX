<?php
namespace App\Livewire;
 
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
 
#[Layout('components.layouts.app')]
class VerifyEmail extends Component
{
    public string $code = '';
 
    public function verify()
    {
        $tenantId = session('pending_tenant');
        $tenant = DB::table('tenants')->where('id', $tenantId)->first();
        if (!$tenant) { return redirect('/register'); }
 
        if ($this->code !== $tenant->email_verify_code) {
            $this->addError('code', __('Incorrect code.'));
            return;
        }
 
        DB::table('tenants')->where('id', $tenantId)->update([
            'email_verified' => true,
            'status' => 'provisioning',
            'updated_at' => now(),
        ]);
 
        // Provision the workspace now — email is proven, which is enough to create the tenant's tools.
        \App\Jobs\ProvisionTenant::dispatch($tenantId);
 
        session()->forget('pending_tenant');
        return redirect('/welcome');

    }
 
    public function render()
    {
        return view('livewire.verify-email');
    }
}
