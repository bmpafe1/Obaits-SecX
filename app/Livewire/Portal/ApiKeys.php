<?php
namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Perm;

/**
 * Real, tenant-facing API key management. Each tenant gets one
 * real, automatically-provisioned default key the first time they
 * visit this page -- no setup required to start integrating. They
 * can also create additional, separately-named keys and revoke any
 * ONE of them independently, so a suspected-compromised key used by
 * one integration doesn't force every other real integration to be
 * broken and reconfigured at the same time.
 *
 * The real, full plaintext key is shown exactly once, at creation
 * time, and is never recoverable afterward -- matching this
 * project's own established credential-storage discipline (see the
 * api_keys migration's own real comment) and every real, standard
 * API-key convention (Stripe, GitHub, etc.).
 */
#[Layout('components.layouts.portal')]
class ApiKeys extends Component
{
    public string $tenantId;
    public string $newKeyName = '';
    public ?string $generatedKey = null;
    public ?string $generatedKeyName = null;

    public function mount()
    {
        Perm::require('manage_api_keys');
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) {
            redirect('/login');
            return;
        }

        // Auto-provision a real, default key the first time a tenant
        // visits this page, so there's zero setup friction to start
        // integrating -- matching Brian's own stated real requirement.
        $hasAny = DB::table('api_keys')->where('tenant_id', $this->tenantId)->exists();
        if (!$hasAny) {
            $this->createKeyInternal('Default');
        }
    }

    public function createKey()
    {
        Perm::require('manage_api_keys');
        $this->validate(['newKeyName' => 'required|string|max:100']);
        $this->createKeyInternal($this->newKeyName);
        $this->newKeyName = '';
    }

    private function createKeyInternal(string $name): void
    {
        $plaintext = 'obaits_live_' . bin2hex(random_bytes(32));
        DB::table('api_keys')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'name' => $name,
            'key_prefix' => substr($plaintext, 0, 20),
            'key_hash' => hash('sha256', $plaintext),
            'created_by' => session('tenant_user_id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->generatedKey = $plaintext;
        $this->generatedKeyName = $name;

        \App\Support\Audit::log($this->tenantId, session('tenant_user_id', 'unknown'), 'api_key.created', 'success', 'api_keys', [
            'name' => $name,
        ]);
    }

    public function revokeKey(string $id)
    {
        Perm::require('manage_api_keys');
        $key = DB::table('api_keys')
            ->where('id', $id)
            ->where('tenant_id', $this->tenantId)
            ->first();
        if (!$key) {
            return;
        }
        DB::table('api_keys')
            ->where('id', $id)
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        \App\Support\Audit::log($this->tenantId, session('tenant_user_id', 'unknown'), 'api_key.revoked', 'success', 'api_keys', [
            'name' => $key->name,
        ]);
    }

    public function dismissGeneratedKey()
    {
        $this->generatedKey = null;
        $this->generatedKeyName = null;
    }

    public function render()
    {
        $keys = DB::table('api_keys')
            ->where('tenant_id', $this->tenantId)
            ->orderByDesc('created_at')
            ->get();

        return view('livewire.portal.api-keys', ['keys' => $keys]);
    }
}
