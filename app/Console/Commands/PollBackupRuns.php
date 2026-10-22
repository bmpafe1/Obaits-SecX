<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupJobRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backups:poll-runs')]
#[Description('Check on every currently-running backup job and record completion')]
class PollBackupRuns extends Command
{
    public function handle(): int
    {
        BackupJobRunner::pollActive();
        return self::SUCCESS;
    }
}
