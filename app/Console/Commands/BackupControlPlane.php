<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Support\Facades\Process;
use App\Support\Health\OperatorAlert;

/**
 * Back up the platform's OWN irreplaceable state.
 *
 * REAL GAP FOUND 2026-08-25: Obaits sells backup as a product while nothing
 * backed up its own control plane. If this host were lost, every tenant's
 * device inventory, monitoring history, incident cases, identity records and
 * the entire orchestrator database would be gone.
 *
 * Wraps ops/backup/control-plane-backup.sh rather than reimplementing it in
 * PHP: the work is six database dumps, which the shell does better. This exists
 * so the backup is SCHEDULED, appears in schedule:list, travels with the repo
 * to production and into the ISO, and -- most importantly -- ALERTS when it
 * fails.
 *
 * That last point is the whole reason this is a command and not a cron line. A
 * backup that fails silently is worse than no backup, because it is trusted.
 * The script already size-checks every dump and exits non-zero naming what
 * failed; this turns that into an operator alert.
 */
#[AsCommand(name: 'obaits:backup-control-plane', description: "Back up the platform's own databases")]
class BackupControlPlane extends Command
{
    protected $signature = 'obaits:backup-control-plane';
    protected $description = "Back up the platform's own databases";

    public function handle(): int
    {
        $script = base_path('ops/backup/control-plane-backup.sh');

        if (!is_file($script)) {
            $this->error("Backup script missing at {$script}");
            OperatorAlert::send('Control-plane backup could not run', [[
                'level' => 'critical',
                'area' => 'platform backup',
                'message' => "The backup script is missing at {$script}. No backup was taken.",
            ]]);
            return 1;
        }

        // The orchestrator dump needs the app's own DB password; the rest read
        // theirs from each container's environment.
        // NOT sudo -E: preserving the whole environment needs a SETENV tag in
        // the sudoers rule, and it passes far more than the one variable we
        // actually need. env(1) sets exactly DB_PASSWORD for the child and
        // nothing else, and requires no additional sudo permission.
        //
        // The value is passed as an argument to env, which is briefly visible
        // in ps -- acceptable here because it is the platform's OWN database
        // password on the platform's own host, not a tenant credential, and the
        // alternative (SETENV) grants strictly more.
        $result = Process::timeout(1800)
            ->run([
                'sudo', '-n', 'env',
                'DB_PASSWORD=' . (string) config('database.connections.pgsql.password'),
                'bash', $script,
            ]);

        $output = trim($result->output() . "\n" . $result->errorOutput());

        if (!$result->successful()) {
            $this->error($output);
            OperatorAlert::send('Control-plane backup FAILED', [[
                'level' => 'critical',
                'area' => 'platform backup',
                'message' => "One or more platform databases failed to back up. "
                    . "This is the platform's own data, not a tenant's. Output:\n" . $output,
            ]]);
            return 1;
        }

        $this->info($output);
        return 0;
    }
}
