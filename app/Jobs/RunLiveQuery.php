<?php
 
namespace App\Jobs;
 
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
 
class RunLiveQuery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
 
    public int $timeout = 120;
    public int $tries = 1;   // never re-run a query against someone's machines
 
    public function __construct(
        public string $runId,
        public string $tenantId,
        public array  $hostIds,
        public string $sql,
    ) {}
 
    public function handle(): void
    {
        $base  = rtrim(config('services.fleet.base_url'), '/');
        $token = config('services.fleet.api_token');
        $H = fn () => Http::withToken($token)->withoutVerifying();
 
        try {
            // Create the campaign. Targets are given as selected.hosts — a flat
            // host_ids array is rejected with "no hosts targeted" on this version.
            $res = $H()->timeout(30)->post($base . '/api/v1/fleet/queries/run', [
                'query'    => $this->sql,
                'selected' => ['hosts' => array_values($this->hostIds), 'labels' => [], 'teams' => []],
            ]);
 
            if (!$res->successful()) {
                $this->fail('fleet_http_' . $res->status());
                return;
            }
 
            $campaignId = $res->json('campaign.id');
            $targeted   = (int) ($res->json('campaign.Metrics.TotalHosts') ?? count($this->hostIds));
 
            DB::table('live_query_runs')->where('id', $this->runId)
                ->update(['targeted_hosts' => $targeted, 'updated_at' => now()]);
 
            // Poll the campaign report. Give hosts a window longer than their
            // distributed_interval (60s by default) — a machine only picks up
            // ad-hoc work on that cycle, so a shorter wait guarantees nothing.
            $deadline = time() + 90;
            $collected = [];
 
            while (time() < $deadline) {
                sleep(5);
                $rep = $H()->timeout(20)->get($base . '/api/v1/fleet/queries/' . $campaignId . '/report');
                if (!$rep->successful()) continue;
 
                $rows = $rep->json('results') ?? [];
                if (!empty($rows)) { $collected = $rows; break; }
            }
 
            DB::table('live_query_runs')->where('id', $this->runId)->update([
                'status'          => 'finished',
                'responded_hosts' => count($collected),
                'results'         => json_encode($collected),
                'updated_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            $this->fail('unreachable');
        }
    }
 
    private function fail(string $reason): void
    {
        DB::table('live_query_runs')->where('id', $this->runId)
            ->update(['status' => 'failed', 'error' => $reason, 'updated_at' => now()]);
    }
}
