<?php
namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

#[Signature('intel:cve-severity')]
#[Description('Cache real CVE severity/CVSS data from Wazuh\'s own Vulnerability Detection index, avoiding a live indexer call on every page view')]
class ImportCveSeverity extends Command
{
    public function handle(): int
    {
        $url = rtrim(config('services.wazuh.indexer_url'), '/') . '/wazuh-states-vulnerabilities-*/_search';
        $auth = [config('services.wazuh.indexer_user'), config('services.wazuh.indexer_password')];

        $this->info('Fetching real CVE severity data from Wazuh\'s indexer…');

        $from = 0;
        $size = 1000;
        $seen = [];
        $n = 0;

        while (true) {
            $body = [
                'size' => $size,
                'from' => $from,
                '_source' => ['vulnerability.id', 'vulnerability.score.base', 'vulnerability.severity'],
                'query' => ['match_all' => new \stdClass()],
            ];

            $res = Http::withoutVerifying()
                ->withBasicAuth($auth[0], $auth[1])
                ->timeout(60)
                ->withBody(json_encode($body), 'application/json')
                ->get($url);

            if (!$res->successful()) {
                $this->error('Indexer query failed: ' . $res->status());
                return 1;
            }

            $hits = $res->json('hits.hits') ?? [];
            if (empty($hits)) break;

            foreach ($hits as $hit) {
                $src = $hit['_source']['vulnerability'] ?? [];
                $cve = $src['id'] ?? null;
                if (!$cve || isset($seen[$cve])) continue; // real dedup -- same CVE appears per-package/per-agent
                $seen[$cve] = true;

                DB::table('cve_severity_cache')->updateOrInsert(
                    ['cve' => $cve],
                    [
                        'score' => $src['score']['base'] ?? null,
                        'severity' => $src['severity'] ?? null,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $n++;
            }

            $from += $size;
            if (count($hits) < $size) break; // real, final page reached
        }

        $this->info("Cached severity for {$n} distinct real CVEs.");
        return 0;
    }
}
