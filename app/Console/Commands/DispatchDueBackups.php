<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupJobRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('backups:dispatch-due')]
#[Description('Start any backup_jobs whose next_run_at has passed')]
class DispatchDueBackups extends Command
{
    public function handle(): int
    {
        $now = now();

        // Jobs due to run: next_run_at passed (or never set — first run ever),
        // and not already sitting in a running state.
        $due = DB::table('backup_jobs as j')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('backup_runs as r')
                  ->whereColumn('r.job_id', 'j.id')->where('r.status', 'running');
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('j.next_run_at')->orWhere('j.next_run_at', '<=', $now);
            })
            ->select('j.*')
            ->get();

        foreach ($due as $job) {
            $result = BackupJobRunner::start($job->id);

            // host_busy is expected and silent — try again next tick.
            // Any other failure was already recorded on backup_jobs by start().

            if ($result['ok']) {
                DB::table('backup_jobs')->where('id', $job->id)->update([
                    'next_run_at' => self::nextRunFor($job->schedule, $now),
                    'updated_at'  => $now,
                ]);
            } elseif ($result['error'] !== 'host_busy' && $result['error'] !== 'no_folders_selected') {
                // Advance the schedule anyway on a hard failure, so a
                // persistently broken job doesn't retry every single tick
                // forever — it gets its normal cadence, and last_error on
                // backup_jobs already records why.
                DB::table('backup_jobs')->where('id', $job->id)->update([
                    'next_run_at' => self::nextRunFor($job->schedule, $now),
                    'updated_at'  => $now,
                ]);
            }
            // host_busy / no_folders_selected: leave next_run_at as-is,
            // retry on the very next tick rather than waiting a full cycle.
        }

        return self::SUCCESS;
    }

    /**
     * Supported cadences. 'hourly' is the shortest offered — Fleet
     * serialises one script per host at a time, and every run does real
     * setup work (credential fetch, repo check) before restic starts, so
     * anything tighter stops being a meaningful interval and starts being
     * a busy-loop. BackupJobRunner::start() already refuses to overlap a
     * still-running job, so a tight schedule is safe, just not "continuous".
     */
    public const SCHEDULES = ['hourly', 'every_6h', 'daily', 'weekly'];

    private static function nextRunFor(string $schedule, \Illuminate\Support\Carbon $from): \Illuminate\Support\Carbon
    {
        return match ($schedule) {
            'hourly'   => $from->copy()->addHour(),
            'every_6h' => $from->copy()->addHours(6),
            'weekly'   => $from->copy()->addWeek(),
            default    => $from->copy()->addDay(), // 'daily' and any unrecognised value
        };
    }
}
