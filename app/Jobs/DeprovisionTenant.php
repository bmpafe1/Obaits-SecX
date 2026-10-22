<?php
namespace App\Jobs;
 
use App\Services\TenantTeardown;
use App\Support\Audit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
 
class DeprovisionTenant implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
 
    public function __construct(public string $tenantId) {}
 
    public function handle(TenantTeardown $teardown): void
    {
        DB::table('tenants')->where('id', $this->tenantId)
            ->update(['status' => 'deprovisioning', 'updated_at' => now()]);
 
        $teardown->removeExternalArtifacts($this->tenantId, force: true);
 
        // Log BEFORE deleting the tenant row (the audit row references it).
        Audit::log($this->tenantId, 'system', 'tenant.deprovision', 'success', null, []);
 
        // Deleting the tenant cascades to its users, domains, assets, subscriptions.
        DB::table('tenants')->where('id', $this->tenantId)->delete();
    }
}

