<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates a backup_jobs run: starts it via FleetScriptRunner + ResticScripts,
 * and (via pollActive(), meant to be called on a schedule) checks on it until done.
 *
 * KEY DESIGN POINT: the poll SCRIPT (ResticScripts::poll) completes in a second
 * or two — it only reads a PID file and a log tail. So checking on a run uses
 * FleetScriptRunner::runAndWait(), blocking briefly on that fast check, NEVER
 * on restic itself (which is backgrounded on the device and untouched by
 * this call's timeout). The actual backup_runs.id IS the deterministic
 * "run id" ResticScripts uses for its file names — no separate Fleet
 * execution_id needs tracking across ticks.
 *
 * V1 LIMITATION: assumes one Fleet host per backup job. backup_selections
 * technically allows folders across multiple hosts under one connection;
 * multi-host orchestration (one run per host? one row spanning several?) is
 * a real design decision, not something to guess at here. start() refuses
 * explicitly rather than silently picking one host if selections span more
 * than one.
 */
class BackupJobRunner
{
    /** How long to wait for the fast poll-check script itself to respond. */
    public const POLL_SCRIPT_TIMEOUT_SECONDS = 90;

    /**
     * Begin a backup run for a job. Returns ['ok' => bool, 'run_id' => ?string, 'error' => ?string].
     */
    public static function start(string $jobId): array
    {
        $job = DB::table('backup_jobs')->where('id', $jobId)->first();
        if (!$job) {
            return ['ok' => false, 'run_id' => null, 'error' => 'job_not_found'];
        }

        // Refuse to pile a new run on top of one still going.
        $alreadyRunning = DB::table('backup_runs')
            ->where('job_id', $jobId)->where('status', 'running')->exists();
        if ($alreadyRunning) {
            return ['ok' => false, 'run_id' => null, 'error' => 'run_already_active'];
        }

        $selections = DB::table('backup_selections')
            ->where('connection_id', $job->connection_id)
            ->where('enabled', true)
            ->get();

        if ($selections->isEmpty()) {
            return ['ok' => false, 'run_id' => null, 'error' => 'no_folders_selected'];
        }

        $hostIds = $selections->pluck('fleet_host_id')->unique();
        if ($hostIds->count() > 1) {
            // Deliberate refusal — see class docblock. Surfacing this loudly
            // now is cheaper than guessing wrong silently.
            return ['ok' => false, 'run_id' => null, 'error' => 'multi_host_not_supported'];
        }
        $fleetHostId = (int) $hostIds->first();

        // ISOLATION: confirm this host still belongs to the job's tenant,
        // even though it was presumably validated at selection-creation time.
        $ownedHosts = collect(FleetFolderBrowser::hosts($job->tenant_id))->pluck('id');
        if (!$ownedHosts->contains($fleetHostId)) {
            return ['ok' => false, 'run_id' => null, 'error' => 'host_not_owned_by_tenant'];
        }

        $paths = $selections->pluck('folder_path')->all();
        $runId = (string) Str::uuid();
        $portalUrl = config('services.obaits.portal_url');

        $script = ResticScripts::launchBackup($portalUrl, $paths, $runId);
        $launch = FleetScriptRunner::launch($fleetHostId, $script, 'backups:run', $job->tenant_id);

        $now = now();

        if (!$launch['ok']) {
            // host_busy is a real, expected condition (Fleet's per-host
            // serialisation) — do not record a backup_runs row for it at
            // all; the caller should simply try again next tick.
            if ($launch['error'] === 'host_busy') {
                return ['ok' => false, 'run_id' => null, 'error' => 'host_busy'];
            }

            DB::table('backup_jobs')->where('id', $jobId)->update([
                'last_status' => 'failure',
                'last_error'  => 'launch failed: ' . $launch['error'],
                'last_run_at' => $now,
                'updated_at'  => $now,
            ]);
            return ['ok' => false, 'run_id' => null, 'error' => $launch['error']];
        }

        DB::table('backup_runs')->insert([
            'id'          => $runId,
            'job_id'      => $jobId,
            'started_at'  => $now,
            'status'      => 'running',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        DB::table('backup_jobs')->where('id', $jobId)->update([
            'last_status' => 'running',
            'last_error'  => null,
            'last_run_at' => $now,
            'updated_at'  => $now,
        ]);

        return ['ok' => true, 'run_id' => $runId, 'error' => null];
    }

    /**
     * Check on every currently-running backup_runs row. Intended to be
     * called from a scheduled command every minute or two — safe to call
     * repeatedly; a run that isn't done yet is simply left as-is.
     */
    public static function pollActive(): void
    {
        $running = DB::table('backup_runs')->where('status', 'running')->get();

        foreach ($running as $run) {
            self::pollOne($run);
        }
    }

    private static function pollOne(object $run): void
    {
        $job = DB::table('backup_jobs')->where('id', $run->job_id)->first();
        if (!$job) {
            // Orphaned run row — the job itself is gone. Flag rather than
            // leave it silently stuck as "running" forever.
            self::markFailed($run->id, $run->job_id, 'parent job no longer exists');
            return;
        }

        $selections = DB::table('backup_selections')
            ->where('connection_id', $job->connection_id)
            ->where('enabled', true)
            ->pluck('fleet_host_id')->unique();

        if ($selections->count() !== 1) {
            // Selections changed shape since the run started (folder
            // reconfigured mid-backup). Don't guess which host — surface it.
            self::markFailed($run->id, $run->job_id, 'selection changed during run (host ambiguous)');
            return;
        }
        $fleetHostId = (int) $selections->first();

        $pollScript = ResticScripts::poll($run->id);
        $result = FleetScriptRunner::runAndWait(
            $fleetHostId, $pollScript, self::POLL_SCRIPT_TIMEOUT_SECONDS, 'backups:poll', $job->tenant_id
        );

        if (!$result['ok']) {
            // Host offline, busy, or unreachable this tick — NOT a failure.
            // The run stays 'running'; we simply try again next tick. This
            // mirrors what today's testing proved: a device going briefly
            // offline does not lose a script result, it just delays it.
            return;
        }

        $output = $result['output'];

        if (str_contains($output, 'OBAITS_STILL_RUNNING')) {
            return; // no state change — still going
        }

        if (str_contains($output, 'OBAITS_ERROR: no such run')) {
            self::markFailed($run->id, $run->job_id, 'run files missing on device (re-image? manual cleanup?)');
            return;
        }

        if (preg_match('/OBAITS_DONE exit_code=(\S+)/', $output, $m)) {
            $exitCode = $m[1];

            if ($exitCode === '0') {
                $snapshotId = null;
                $bytesAdded = null;
                if (preg_match('/"message_type":"summary".*"snapshot_id":"([a-f0-9]+)"/', $output, $sm)) {
                    $snapshotId = $sm[1];
                }
                if (preg_match('/"data_added":(\d+)/', $output, $bm)) {
                    $bytesAdded = (int) $bm[1];
                }
                self::markSuccess($run->id, $run->job_id, $snapshotId, $bytesAdded);
            } else {
                // exit_code non-zero or 'unknown' — capture the log tail as
                // the error; it's restic's own JSON/text output, genuinely
                // useful for diagnosing what went wrong.
                self::markFailed($run->id, $run->job_id, "restic exit_code={$exitCode}: " . substr($output, 0, 2000));
            }
            return;
        }

        // Output didn't match any known shape — don't silently leave this
        // running forever; flag it so a human looks rather than it hanging
        // indefinitely with no visibility.
        self::markFailed($run->id, $run->job_id, 'unrecognised poll output: ' . substr($output, 0, 500));
    }

    private static function markSuccess(string $runId, string $jobId, ?string $snapshotId, ?int $bytesAdded): void
    {
        $now = now();
        DB::table('backup_runs')->where('id', $runId)->update([
            'status'            => 'success',
            'finished_at'       => $now,
            'snapshot_id'       => $snapshotId,
            'bytes_transferred' => $bytesAdded,
            'updated_at'        => $now,
        ]);
        DB::table('backup_jobs')->where('id', $jobId)->update([
            'last_status' => 'success',
            'last_error'  => null,
            'updated_at'  => $now,
        ]);
    }

    private static function markFailed(string $runId, string $jobId, string $error): void
    {
        $now = now();
        DB::table('backup_runs')->where('id', $runId)->update([
            'status'      => 'failure',
            'finished_at' => $now,
            'error'       => $error,
            'updated_at'  => $now,
        ]);
        DB::table('backup_jobs')->where('id', $jobId)->update([
            'last_status' => 'failure',
            'last_error'  => $error,
            'updated_at'  => $now,
        ]);
    }
}
