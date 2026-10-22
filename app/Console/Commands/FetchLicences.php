<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('licences:fetch')]
#[Description('Fetch the licence master file from Dropbox and refresh the licence cache.')]
class FetchLicences extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(\App\Support\Licensing\DropboxLicenceFetcher $fetcher): int
    {
        $r = $fetcher->run();
        $this->info($r['ok']
            ? "Licences updated: {$r['applied']} applied, {$r['skipped']} skipped."
            : "Fetch failed: {$r['error']} (last-known-good retained).");
        return self::SUCCESS;
    }
}