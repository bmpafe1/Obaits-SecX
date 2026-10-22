<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

#[Signature('intel:epss')]
#[Description('Import EPSS exploitation-probability scores (full daily CSV)')]
class ImportEpss extends Command
{
    public function handle(): int
    {
        // Yesterday's file is reliably published; today's may not exist yet.
        $date = now()->subDay()->format('Y-m-d');
        $url = "https://epss.empiricalsecurity.com/epss_scores-{$date}.csv.gz";
        $this->info("Fetching EPSS for {$date}…");

        $res = Http::timeout(300)->get($url);
        if (!$res->successful()) {
            $this->error('EPSS fetch failed: ' . $res->status() . ' — try an earlier date if not yet published.');
            return 1;
        }

        $csv = gzdecode($res->body());
        if ($csv === false) {
            $this->error('Could not decompress the EPSS file.');
            return 1;
        }

        $rows = explode("\n", $csv);
        $batch = [];
        $n = 0;

        foreach ($rows as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;      // model-version comment
            if (str_starts_with($line, 'cve,')) continue;        // header

            [$cve, $epss, $pct] = array_pad(explode(',', $line), 3, null);
            if (!$cve || !is_numeric($epss)) continue;

            $batch[] = [
                'cve' => $cve,
                'epss' => (float) $epss,
                'percentile' => is_numeric($pct) ? (float) $pct : null,
                'score_date' => $date,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $n++;

            if (count($batch) >= 1000) {
                DB::table('epss_scores')->upsert($batch, ['cve'], ['epss', 'percentile', 'score_date', 'updated_at']);
                $batch = [];
            }
        }

        if ($batch) {
            DB::table('epss_scores')->upsert($batch, ['cve'], ['epss', 'percentile', 'score_date', 'updated_at']);
        }

        $this->info("Imported {$n} EPSS scores.");
        return 0;
    }
}