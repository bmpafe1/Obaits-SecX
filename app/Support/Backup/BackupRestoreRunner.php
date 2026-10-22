<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Async restore lifecycle, mirroring BackupJobRunner but for restores.
 *
 * WHY THIS EXISTS: restoreForeground() (still present, unused by the portal
 * now) blocked a web request for up to the restic run's full duration. A
 * real Livewire click was killed by the web server's own request timeout
 * before ever reporting success or failure to the tenant — confirmed
 * directly during testing 2026-07-25. Restore now launches in the
 * background and is polled the same way a backup is.
 */
class BackupRestoreRunner
{
    public const POLL_SCRIPT_TIMEOUT_SECONDS = 90;

    public static function start(string $tenantId, int $fleetHostId, string $snapshotId, ?string $backupRunId, ?string $actor): array
    {
        $runId = (string) Str::uuid();
        $portalUrl = config('services.obaits.portal_url');
        $dest = '/tmp/obaits-restore-' . substr($runId, 0, 8);

        $script = ResticScripts::launchRestore($portalUrl, $snapshotId, $dest, $runId);
        $launch = FleetScriptRunner::launch($fleetHostId, $script, $actor, $tenantId);

        if (!$launch['ok']) {
            return ['ok' => false, 'restore_id' => null, 'destination_path' => null, 'error' => $launch['error']];
        }

        $now = now();
        DB::table('backup_restores')->insert([
            'id' => $runId,
            'tenant_id' => $tenantId,
            'run_id' => $backupRunId,
            'fleet_host_id' => $fleetHostId,
            'snapshot_id' => $snapshotId,
            'destination_path' => $dest,
            'status' => 'running',
            'started_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return ['ok' => true, 'restore_id' => $runId, 'destination_path' => $dest, 'error' => null];
    }

    public static function pollActive(): void
    {
        foreach (DB::table('backup_restores')->where('status', 'running')->get() as $r) {
            self::pollOne($r);
        }
    }

    private static function pollOne(object $r): void
    {
        $pollScript = ResticScripts::poll($r->id);
        $result = FleetScriptRunner::runAndWait(
            $r->fleet_host_id, $pollScript, self::POLL_SCRIPT_TIMEOUT_SECONDS, 'backups:poll-restores', $r->tenant_id
        );

        if (!$result['ok']) return; // host offline/busy this tick — retry next tick

        $output = $result['output'];
        if (str_contains($output, 'OBAITS_STILL_RUNNING')) return;

        if (preg_match('/OBAITS_DONE exit_code=(\S+)/', $output, $m)) {
            $ok = $m[1] === '0';
            $now = now();
            DB::table('backup_restores')->where('id', $r->id)->update([
                'status' => $ok ? 'success' : 'failure',
                'error' => $ok ? null : substr($output, 0, 2000),
                'finished_at' => $now,
                'updated_at' => $now,
            ]);

            \App\Support\Notifications::raiseAndEmail($r->tenant_id,
                $ok ? __('Restore complete') : __('Restore failed'), [
                    'level' => $ok ? 'info' : 'warning',
                    'body'  => $ok
                        ? __('Your files are ready at :path on the device. Look inside that folder for your original folder structure — for example, a file that was at /home/you/Documents/file.txt will now be at :path/home/you/Documents/file.txt. Ask your IT contact to move it from there if you need it back in its original place.', ['path' => $r->destination_path])
                        : __('The restore could not be completed. Please try again or contact support.'),
                    'link'  => '/portal/backups',
                ]);
            return;
        }

        DB::table('backup_restores')->where('id', $r->id)->update([
            'status' => 'failure', 'error' => 'unrecognised poll output',
            'finished_at' => now(), 'updated_at' => now(),
        ]);
    }
}
