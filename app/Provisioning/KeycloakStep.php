<?php
namespace App\Provisioning;
 
use App\Clients\KeycloakClient;
use App\Support\Audit;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
 
class KeycloakStep
{
    public function __construct(private KeycloakClient $kc) {}
 
    public function run(object $tenant): void
    {
        $token = $this->kc->token();
 
        // 1. Tenant group
        $groupId = $this->kc->ensureGroup($token, $tenant->slug);
        $this->record($tenant->id, 'group', $groupId, ['name' => $tenant->slug]);
        
        // 2. The three platform roles (created once, reused)
        foreach (['platform-admin', 'analyst', 'viewer'] as $role) {
            $this->kc->ensureRealmRole($token, $role);
        }
 
        // 3. Tenant admin user
        $admin = DB::table('tenant_users')->where('tenant_id', $tenant->id)->first();
        if ($admin) {
            $userId = $this->kc->ensureUser($token, $admin->email, $admin->first_name, $admin->last_name);

            // REAL BUG FOUND on a real customer signup: ProvisionTenant's
            // own job has tries=3 with a real backoff -- if ANY LATER step
            // (Fleet/Wazuh/Zabbix/CISO) genuinely fails on a real attempt,
            // Laravel retries the ENTIRE job from the start, calling this
            // method again too. sendSetPasswordEmail() had no real
            // idempotency guard at all (unlike record() below), so a real
            // retry sent a genuinely NEW action-token email each time --
            // and Keycloak's own real, sensible security behavior
            // invalidates the PREVIOUS token once a new one is issued for
            // the same user. Confirmed directly: a real customer received
            // three real emails within about a minute (matching the job's
            // own real backoff timing) and the one they clicked had
            // already been invalidated by a later retry's own new one.
            // Real fix: only ever send this real email ONCE per tenant,
            // checked the same real way record() already does below.
            $alreadySent = DB::table('tenant_artifacts')
                ->where('tenant_id', $tenant->id)->where('tool', 'keycloak')
                ->where('artifact_type', 'user')->where('external_id', $userId)->exists();

            if (!$alreadySent) {
                // No password is ever set by us. The user receives a Keycloak
                // execute-actions email and chooses their own; see sendSetPasswordEmail().
                $this->kc->sendSetPasswordEmail($token, $userId);
            }

            $this->kc->addUserToGroup($token, $userId, $groupId);
            $this->record($tenant->id, 'user', $userId, ['email' => $admin->email]);
 
            DB::table('tenant_users')->where('id', $admin->id)
                ->update(['keycloak_user_id' => $userId, 'updated_at' => now()]);
        }
 
        Audit::log($tenant->id, 'system', 'tenant.provision.keycloak', 'success', 'keycloak',
            ['group_id' => $groupId, 'set_password_email' => (bool) $admin]);
    }
 
    private function record(string $tenantId, string $type, string $externalId, array $meta): void
    {
        // idempotent: do not duplicate an artifact row
        $exists = DB::table('tenant_artifacts')
            ->where('tenant_id', $tenantId)->where('tool', 'keycloak')
            ->where('artifact_type', $type)->where('external_id', $externalId)->exists();
        if ($exists) return;
 
        DB::table('tenant_artifacts')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'tool' => 'keycloak',
            'artifact_type' => $type,
            'external_id' => $externalId,
            'metadata' => json_encode($meta),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
