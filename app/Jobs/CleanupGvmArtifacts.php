<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupGvmArtifacts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public ?string $taskId,
        public ?string $targetId
    ) {}

    public function handle(\App\Clients\GvmClient $gvm): void
    {
        try {
            if (!empty($this->taskId)) {
                $gvm->deleteTask($this->taskId);
            }
            if (!empty($this->targetId)) {
                $gvm->deleteTarget($this->targetId);
            }
        } catch (\Throwable $e) {
            Log::warning("GVM cleanup failed (task {$this->taskId}, target {$this->targetId}): ".$e->getMessage());
        }
    }
}