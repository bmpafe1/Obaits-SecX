<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupRestoreRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backups:poll-restores')]
#[Description('Check on every currently-running restore and record completion')]
class PollBackupRestores extends Command
{
    public function handle(): int
    {
        BackupRestoreRunner::pollActive();
        return self::SUCCESS;
    }
}
