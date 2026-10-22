<?php
namespace App\Livewire\Portal;
 
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
 
#[Layout('components.layouts.portal')]
class Domains extends Component
{
    public string $tenantId;   // in a real portal this comes from the logged-in session
    public string $newDomain = '';
 
    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); }
    }
 
    public function addDomain()
    {
        \App\Support\Perm::require('manage_domains');
        $domain = \App\Support\DomainHelper::fromWebsite($this->newDomain);
        if (!$domain) { $this->addError('newDomain', __('Enter a valid domain.')); return; }
 
        $exists = DB::table('tenant_domains')
            ->where('tenant_id', $this->tenantId)->where('domain', $domain)->exists();
        if ($exists) { $this->addError('newDomain', __('That domain is already added.')); return; }
 
        DB::table('tenant_domains')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'domain' => $domain,
            'verification_token' => 'obaits-verify=' . Str::random(40),
            'verified' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->newDomain = '';
    }
 
    public function verify(string $domainId)
    {
        \App\Support\Perm::require('manage_domains');
        $row = DB::table('tenant_domains')->where('id', $domainId)
            ->where('tenant_id', $this->tenantId)->first();
        if (!$row) return;
 
        $mode = config('services.domain_verification.mode', 'dns');
        $ok = false;
        if ($mode === 'mock') {
            $ok = str_ends_with($row->verification_token, '-VERIFIED');
        } else {
            $records = @dns_get_record('_obaits-challenge.' . $row->domain, DNS_TXT) ?: [];
            foreach ($records as $r) {
                if (($r['txt'] ?? '') === $row->verification_token) { $ok = true; break; }
            }
        }
 
        if ($ok) {
            DB::table('tenant_domains')->where('id', $domainId)
                ->update(['verified' => true, 'verified_at' => now(), 'updated_at' => now()]);
            \App\Support\Audit::log($this->tenantId, 'system', 'domain.verified', 'success', null, ['domain' => $row->domain]);
        } else {
            $this->addError('verify_' . $domainId, __('We could not find the record yet. DNS can take a few minutes.'));
        }
    }
 
    public function render()
    {
        $domains = DB::table('tenant_domains')->where('tenant_id', $this->tenantId)
            ->orderByDesc('created_at')->get();
        return view('livewire.portal.domains', ['domains' => $domains]);
    }
}
