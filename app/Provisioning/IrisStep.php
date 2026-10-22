<?php
namespace App\Provisioning;

use App\Clients\IrisClient;
use App\Support\Audit;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * Real IRIS provisioning step, mirroring ZabbixStep's exact real
 * pattern: idempotent (checks tenant_artifacts before creating), real
 * audit logging on success/failure. Creates one real IRIS "customer"
 * per tenant, using the tenant's own real organization_name.
 */
class IrisStep
{
    public function __construct(private IrisClient $iris) {}

    public function run(object $tenant): void
    {
        $existing = DB::table('tenant_artifacts')
            ->where('tenant_id', $tenant->id)
            ->where('tool', 'iris')
            ->where('artifact_type', 'customer')
            ->first();
        if ($existing) {
            return; // already provisioned, nothing to do
        }

        $result = $this->iris->createCustomer($tenant->organization_name);
        if (!$result || !$result['customer_id']) {
            Audit::log($tenant->id, 'system', 'tenant.provision.iris', 'failure', 'iris',
                ['reason' => 'customer creation returned no id']);
            return;
        }

        DB::table('tenant_artifacts')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'tool' => 'iris',
            'artifact_type' => 'customer',
            'external_id' => (string) $result['customer_id'],
            'metadata' => json_encode([
                'customer_name' => $tenant->organization_name,
                'client_uuid' => $result['client_uuid'],
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Audit::log($tenant->id, 'system', 'tenant.provision.iris', 'success', 'iris',
            ['customer_id' => $result['customer_id']]);
    }
}
