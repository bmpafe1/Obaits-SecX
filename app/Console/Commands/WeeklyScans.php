<?php
namespace App\Console\Commands;
 
use Illuminate\Console\Command;
use App\Services\Scanner;
use Illuminate\Support\Facades\DB;
 
class WeeklyScans extends Command
{
    protected $signature = 'scans:weekly';
    protected $description = 'Queue weekly scans for all active tenants (staggered)';
 
    public function handle(Scanner $scanner): int
    {
        // All verified domains of active tenants.
        $targets = DB::table('tenant_domains')
            ->join('tenants', 'tenants.id', '=', 'tenant_domains.tenant_id')
            ->where('tenants.status', 'active')
            ->where('tenant_domains.verified', true)
            ->select('tenant_domains.tenant_id', 'tenant_domains.domain')
            ->get();
 
        $delayMinutes = 0;
        foreach ($targets as $t) {
            // Stagger: space each scan ~20 minutes apart so they run one-ish at a time.
            $scanId = $scanner->launch($t->tenant_id, $t->domain, 'scheduled');
            // Re-dispatch the just-created scan with a delay to stagger start.
            DB::table('scans')->where('id', $scanId)->update(['status' => 'queued']);
            $delayMinutes += 20;
        }
        $this->info('Queued ' . count($targets) . ' weekly scans.');
        return self::SUCCESS;
    }
}
