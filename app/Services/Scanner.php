<?php
namespace App\Services;
 
use App\Jobs\RunScan;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
 
class Scanner
{
    public function launch(string $tenantId, string $target, string $mode = 'on_demand'): string
    {
        $scanId = (string) Str::uuid();
        DB::table('scans')->insert([
            'id' => $scanId, 'tenant_id' => $tenantId, 'target' => $target,
            'mode' => $mode, 'status' => 'queued',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Audit::log($tenantId, 'system', 'scan.queued', 'success', 'gvm', ['target' => $target, 'mode' => $mode]);
        RunScan::dispatch($scanId);
        return $scanId;
    }

       public function launchWeb(string $tenantId, string $targetUrl, bool $activeScan = false, string $mode = 'on_demand'): string
    {
        $scanId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('scans')->insert([
            'id' => $scanId,
            'tenant_id' => $tenantId,
            'target' => $targetUrl,
            'mode' => $mode,
            'scan_type' => 'web',
            'status' => 'queued',
            'progress' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \App\Jobs\RunWebScan::dispatch($scanId, $activeScan);
        return $scanId;
    }

}
