<?php
namespace App\Livewire;
 
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
 
#[Layout('components.layouts.app')]
class VerifyDomain extends Component
{
    public $tenant;
 
    public function mount()
    {
        $tenantId = session('pending_tenant');
        $this->tenant = DB::table('tenants')->where('id', $tenantId)->first();
        if (!$this->tenant || !$this->tenant->email_verified) {
            redirect('/register');
        }
    }
 
    public function check()
    {
        $domain = $this->tenant->primary_domain;
        $expected = $this->tenant->domain_verify_token;
 
        // Mock mode (dev): honor the same convention as Stage 0's verifier.
        $mode = config('services.domain_verification.mode', 'dns');
        $verified = false;
        if ($mode === 'mock') {
            $verified = str_ends_with($expected, '-VERIFIED');
        } else {
            $records = @dns_get_record('_obaits-challenge.' . $domain, DNS_TXT) ?: [];
            foreach ($records as $r) {
                if (($r['txt'] ?? '') === $expected) { $verified = true; break; }
            }
        }
 
        if (!$verified) {
            $this->addError('domain', __('DNS record not found yet. Add it and try again.'));
            return;
        }
 
        DB::table('tenants')->where('id', $this->tenant->id)->update([
            'domain_verified' => true,
            'status' => 'provisioning',
            'updated_at' => now(),
        ]);
 
        // NOW provision — both email and domain are proven.
        \App\Jobs\ProvisionTenant::dispatch($this->tenant->id);
 
        session()->forget('pending_tenant');
        return redirect('/welcome');
    }
 
    public function render()
    {
        return view('livewire.verify-domain');
    }
}

