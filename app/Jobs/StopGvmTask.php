<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StopGvmTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public string $taskId) {}

    public function handle(\App\Clients\GvmClient $gvm): void
    {
        try {
            $gvm->stopTask($this->taskId);
        } catch (\Throwable $e) {
            Log::warning("GVM stop failed (task {$this->taskId}): ".$e->getMessage());
        }
    }
}
