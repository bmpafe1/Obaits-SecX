<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

#[Signature('intel:kev')]
#[Description('Import the CISA Known Exploited Vulnerabilities catalogue')]
class ImportKev extends Command
{
    public function handle(): int
    {
        $url = 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json';
        $this->info('Fetching KEV…');

        $res = Http::timeout(120)->get($url);
        if (!$res->successful()) {
            $this->error('KEV fetch failed: ' . $res->status());
            return 1;
        }

        $items = $res->json('vulnerabilities') ?? [];
        $this->info('KEV entries: ' . count($items));

        $n = 0;
        foreach ($items as $v) {
            $cve = $v['cveID'] ?? null;
            if (!$cve) continue;

            DB::table('kev_entries')->updateOrInsert(
                ['cve' => $cve],
                [
                    'vendor' => $v['vendorProject'] ?? null,
                    'product' => $v['product'] ?? null,
                    'name' => $v['vulnerabilityName'] ?? null,
                    'required_action' => $v['requiredAction'] ?? null,
                    'due_date' => $v['dueDate'] ?? null,
                    'ransomware' => (($v['knownRansomwareCampaignUse'] ?? '') === 'Known'),
                    'date_added' => $v['dateAdded'] ?? null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $n++;
        }

        $this->info("Imported/updated {$n} KEV entries.");
        return 0;
    }
}