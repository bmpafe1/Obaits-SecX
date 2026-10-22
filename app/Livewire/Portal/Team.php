<?php
namespace App\Livewire\Portal;
 
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Perm;
 
#[Layout('components.layouts.portal')]
class Team extends Component
{
    public string $tenantId;
    public string $inviteEmail = '';
    public string $inviteFirst = '';
    public string $inviteLast = '';
    public string $inviteRole = 'analyst';
 
    public function mount()
    {
        $this->tenantId = session('tenant_id');
        Perm::require('manage_team');   // only admins reach this screen
    }
 
    public function invite()
    {
        Perm::require('manage_team');
        $this->validate([
            'inviteEmail' => 'required|email',
            'inviteFirst' => 'required|string|max:100',
            'inviteLast' => 'required|string|max:100',
            'inviteRole' => 'required|in:platform-admin,analyst,viewer',
        ]);
 
        // Create the user in Keycloak (in this tenant's group, with the role) via KeycloakClient.
        $tenant = DB::table('tenants')->where('id', $this->tenantId)->first();
        $kc = app(\App\Clients\KeycloakClient::class);

        $userId = $kc->createUser(
            $tenant->slug, $this->inviteEmail, $this->inviteFirst, $this->inviteLast, $this->inviteRole
        );

        // REAL BUG FOUND 2026-09-01: the account was created and NOTHING ever
        // let the person into it. The comment below said 'they set their
        // password via Keycloak', but sendSetPasswordEmail() -- which has
        // existed all along -- was never called, and the invitation email
        // carried no link. An invited colleague received 'please log in and set
        // your password' with no address to log in at and no credential to log
        // in with.
        //
        // Keycloak sends this itself with a signed, expiring token, which is
        // the right mechanism: the platform never handles the credential.
        try {
            $kc->sendSetPasswordEmail($kc->token(), $userId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Set-password email failed for ' . $this->inviteEmail . ': ' . $e->getMessage());
            session()->flash('team_error', __('The account was created, but we could not send the password email. Ask them to use “Forgot password” at the sign-in page.'));
        }
 
        // Record in tenant_users.
        DB::table('tenant_users')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'first_name' => $this->inviteFirst,
            'last_name' => $this->inviteLast,
            'email' => $this->inviteEmail,
            'role' => $this->inviteRole,
            'created_at' => now(), 'updated_at' => now(),
        ]);
 
        // Email the invitation (they set their password via Keycloak).
        \Illuminate\Support\Facades\Mail::to($this->inviteEmail)
            ->send(new \App\Mail\TeamInvite($this->inviteFirst, $tenant->organization_name, $this->inviteEmail));
 
        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'team.invited', 'success', 'keycloak',
            ['email' => $this->inviteEmail, 'role' => $this->inviteRole]);
 
        $this->reset(['inviteEmail','inviteFirst','inviteLast']);
        session()->flash('team_ok', __('Invitation sent.'));
    }
 
    public function remove(string $userId)
    {
        Perm::require('manage_team');
        $u = DB::table('tenant_users')->where('id',$userId)->where('tenant_id',$this->tenantId)->first();
        if ($u && $u->email !== session('auth_email')) {   // cannot remove yourself
            app(\App\Clients\KeycloakClient::class)->deleteUser($u->email);
            DB::table('tenant_users')->where('id',$userId)->delete();
            \App\Support\Audit::log($this->tenantId, session('auth_email'), 'team.removed', 'success', 'keycloak', ['email'=>$u->email]);
        }
    }
 
    public function render()
    {
        $members = DB::table('tenant_users')->where('tenant_id',$this->tenantId)->orderBy('created_at')->get();
        return view('livewire.portal.team', compact('members'));
    }
}
