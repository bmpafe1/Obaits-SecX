<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('risk:score {--all}')]
#[Description('Score findings with the deterministic risk engine (KEV + EPSS + CVSS + exposure)')]
class ScoreFindings extends Command
{
    public function handle(): int
    {
        $scans = DB::table('scans')->pluck('id');

        if ($scans->isEmpty()) {
            $this->info('No scans found — nothing to score.');
            return 0;
        }

        $this->info("Scoring findings across {$scans->count()} scan(s)…");

        $total = 0;
        foreach ($scans as $id) {
            $n = \App\Support\FindingScorer::scoreScan($id);
            $total += $n;
        }

        $this->info("Scored {$total} findings.");
        return 0;
    }
}