<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class BuildAgentPackageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // fleetctl package can take a while

    public function __construct(public string $tenantSlug, public string $type = 'deb') {}

    public function handle(): void
    {
        Artisan::call('obaits:agent-package', [
            'tenant' => $this->tenantSlug,
            '--type' => $this->type,
        ]);
    }
}