<?php
namespace App\Provisioning;
 
use App\Clients\CisoClient;
use App\Support\Audit;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
 
class CisoStep
{
    public function __construct(private CisoClient $ciso) {}
 
    public function run(object $tenant): void
    {
        $existing = DB::table('tenant_artifacts')
            ->where('tenant_id', $tenant->id)->where('tool', 'ciso_assistant')
            ->where('artifact_type', 'domain')->first();
 
        $folderId = $existing ? $existing->external_id : $this->ciso->ensureDomain($tenant->slug);
 
        if (!$existing) {
            DB::table('tenant_artifacts')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'tool' => 'ciso_assistant',
                'artifact_type' => 'domain',
                'external_id' => $folderId,
                'metadata' => json_encode(['slug' => $tenant->slug]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
 
        Audit::log($tenant->id, 'system', 'tenant.provision.ciso', 'success', 'ciso_assistant',
            ['domain_id' => $folderId]);
    }
}
