<?php
namespace App\Provisioning;
 
use App\Clients\WazuhClient;
use App\Support\Audit;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
 
class WazuhStep
{
    public function __construct(private WazuhClient $wazuh) {}
 
    public function run(object $tenant): void
    {
        $groupId = 'tenant-' . $tenant->slug;
        $token = $this->wazuh->token();
 
        $this->wazuh->ensureGroup($token, $groupId);
 
        $exists = DB::table('tenant_artifacts')
            ->where('tenant_id', $tenant->id)->where('tool', 'wazuh')
            ->where('artifact_type', 'agent_group')->where('external_id', $groupId)->exists();
        if (!$exists) {
            DB::table('tenant_artifacts')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'tool' => 'wazuh',
                'artifact_type' => 'agent_group',
                'external_id' => $groupId,
                'metadata' => json_encode(['slug' => $tenant->slug]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
 
        Audit::log($tenant->id, 'system', 'tenant.provision.wazuh', 'success', 'wazuh',
            ['agent_group' => $groupId]);
    }
}
