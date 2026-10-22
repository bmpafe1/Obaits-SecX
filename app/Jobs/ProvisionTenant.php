<?php
namespace App\Jobs;
 
use App\Provisioning\KeycloakStep;
use App\Services\TenantTeardown;
use App\Support\Audit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
 
class ProvisionTenant implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public array $backoff = [10, 30]; // wait 10s before 2nd try, 30s before 3rd

    public function __construct(public string $tenantId) {}
 
    public function handle(): void
    {
        $tenant = DB::table('tenants')->where('id', $this->tenantId)->first();
        if (!$tenant) return;
 
        try {
            app(KeycloakStep::class)->run($tenant);
            app(\App\Provisioning\FleetStep::class)->run($tenant);
            app(\App\Provisioning\WazuhStep::class)->run($tenant);
            app(\App\Provisioning\ZabbixStep::class)->run($tenant);
            app(\App\Provisioning\CisoStep::class)->run($tenant);
            app(\App\Provisioning\IrisStep::class)->run($tenant);
            DB::table('tenants')->where('id', $tenant->id)
                ->update(['status' => 'active', 'updated_at' => now()]);
            Audit::log($tenant->id, 'system', 'tenant.provision', 'success', null, []);
        } catch (\Throwable $e) {
            Audit::log($tenant->id, 'system', 'tenant.provision.rolled_back', 'failure', null, ['error' => $e->getMessage()]);
            DB::table('tenants')->where('id', $tenant->id)
                ->update(['status' => 'failed', 'updated_at' => now()]);
            app(TenantTeardown::class)->removeExternalArtifacts($tenant->id);
            throw $e;
        }
    }
}
