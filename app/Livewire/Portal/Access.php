<?php
namespace App\Livewire\Portal;
 
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
 
#[Layout('components.layouts.portal')]
class Access extends Component
{
    public string $tenantId;
    public ?string $groupId = null;
    // Directory (AD/LDAP) connection form
    public string $vendor = 'ad';
    public string $connection_url = '';
    public string $bind_dn = '';
    public string $bind_credential = '';
    public string $users_dn = '';
    public string $username_attr = '';
 
    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); }
 
        $this->groupId = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)
            ->where('tool', 'keycloak')
            ->where('artifact_type', 'group')     // CONFIRMED schema
            ->value('external_id');
    }
 
    public function render()
    {
        $users = [];
 
        if ($this->groupId) {
            // AUTHENTICATION facts from Keycloak: who exists, enabled, when added.
            try {
                $users = app(\App\Clients\KeycloakClient::class)->accessForGroup($this->groupId);
            } catch (\Throwable $e) {
                $users = [];
            }
 
            // AUTHORIZATION from tenant_users: the platform role (our source of truth).
            $roleByEmail = DB::table('tenant_users')
                ->where('tenant_id', $this->tenantId)     // ISOLATION
                ->pluck('role', 'email')
                ->toArray();
 
            foreach ($users as &$u) {
                $r = $roleByEmail[$u['email']] ?? null;
                $u['roles'] = $r ? [$r] : [];
            }
            unset($u);
        }
 
        return view('livewire.portal.access', compact('users'));
    }

    public function connectDirectory()
    {
        \App\Support\Perm::require('manage_team');   // admins only
        $this->validate([
            'connection_url' => 'required|string',
            'bind_dn' => 'required|string',
            'bind_credential' => 'required|string',
            'users_dn' => 'required|string',
        ]);
 
        $slug = DB::table('tenants')->where('id', $this->tenantId)->value('slug');
        $kc = app(\App\Clients\KeycloakClient::class);
        $ok = $kc->addLdapFederation($kc->token(), $slug, [
            'vendor' => $this->vendor,
            'connection_url' => $this->connection_url,
            'bind_dn' => $this->bind_dn,
            'bind_credential' => $this->bind_credential,
            'users_dn' => $this->users_dn,
            'username_attr' => $this->username_attr ?: 'sAMAccountName',
        ]);
 
        \App\Support\Audit::log($this->tenantId, session('auth_email'),
            'directory.connected', $ok ? 'success' : 'failure', 'keycloak', ['url' => $this->connection_url]);
 
        session()->flash('acc_ok', $ok
            ? __('Directory connected. Your staff can now sign in with their work accounts.')
            : __('Could not connect to the directory. Check the details and try again.'));
    }

}
