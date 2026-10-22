<?php
namespace App\Livewire\Operator;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Real, operator-facing API key management. Unlike the real, tenant
 * side (App\Livewire\Portal\ApiKeys), a key here is NOT auto-provisioned
 * -- it is genuinely, deliberately full-power and cross-tenant, matching
 * every real, human operator's own access (confirmed directly with
 * Brian before building; operators currently have no role/permission
 * granularity, so a key here is exactly as powerful as any operator's
 * own login). Deliberate creation only, never automatic, given the
 * real weight of what this key can do.
 *
 * REAL BUG FOUND AND FIXED 2026-08-23: without an explicit Layout
 * attribute, this route hit a genuine 500 error ("No hint path defined
 * for [layouts]") -- confirmed via real, isolated diagnostic testing
 * (a minimal component under multiple different real URL paths all
 * failed identically) that this is a real, structural gap: any NEW
 * operator page without an explicit Layout apparently risks the same
 * crash, even though existing pages (Dashboard, AuditLog, etc.) happen
 * not to hit it. Fixed with a genuine, dedicated, non-tenant-dependent
 * operator layout (see components/layouts/operator.blade.php) -- the
 * tenant portal's own layout was tried first and correctly rejected,
 * since it has a real, hard dependency on tenant session data
 * (NotificationBell) that doesn't exist in an operator context.
 */
#[Layout('components.layouts.operator')]
class ApiKeys extends Component
{
    public string $newKeyName = '';
    public ?string $generatedKey = null;
    public ?string $generatedKeyName = null;

    public function createKey()
    {
        $this->validate(['newKeyName' => 'required|string|max:100']);

        $operatorId = session('operator_id');
        $plaintext = 'obaits_op_' . bin2hex(random_bytes(32));

        DB::table('operator_api_keys')->insert([
            'id' => (string) Str::uuid(),
            'operator_id' => $operatorId,
            'name' => $this->newKeyName,
            'key_prefix' => substr($plaintext, 0, 20),
            'key_hash' => hash('sha256', $plaintext),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->generatedKey = $plaintext;
        $this->generatedKeyName = $this->newKeyName;
        $this->newKeyName = '';

        \App\Support\OperatorAudit::log('operator.api_key_created', [
            'detail' => ['name' => $this->generatedKeyName],
        ]);
    }

    public function revokeKey(string $id)
    {
        $key = DB::table('operator_api_keys')
            ->where('id', $id)
            ->where('operator_id', session('operator_id'))
            ->first();
        if (!$key) {
            return;
        }
        DB::table('operator_api_keys')
            ->where('id', $id)
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        \App\Support\OperatorAudit::log('operator.api_key_revoked', [
            'detail' => ['name' => $key->name],
        ]);
    }

    public function dismissGeneratedKey()
    {
        $this->generatedKey = null;
        $this->generatedKeyName = null;
    }

    public function render()
    {
        $keys = DB::table('operator_api_keys')
            ->where('operator_id', session('operator_id'))
            ->orderByDesc('created_at')
            ->get();

        return view('livewire.operator.api-keys', ['keys' => $keys]);
    }
}
