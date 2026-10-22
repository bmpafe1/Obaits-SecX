<?php

namespace App\Console\Commands;

use App\Support\Backup\FleetScriptRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * REAL GAP FOUND 2026-08-06: fleet_execution_id was added to
 * software_install_runs specifically so a launched install's real result
 * could be polled -- but no poller was ever built. Every software install
 * ever pushed (including the real, confirmed-working 7-Zip and figlet runs
 * on live devices) sat at status='running' permanently. The installs
 * genuinely succeeded on the machines; the platform never found out.
 *
 * The reconciliation safety net depends entirely on telling 'succeeded'
 * apart from 'failed' apart from 'still legitimately pending'. Without this
 * poller those three collapse into one value.
 *
 * Deliberately a direct structural copy of PollRoleSetupRuns -- same
 * offline tolerance, same treatment of a poll error as "not yet known"
 * rather than "failed".
 */
#[Signature('obaits:poll-software-installs')]
#[Description('Check on every currently-running software install script and record its real completion result')]
class PollSoftwareInstalls extends Command
{
    public function handle(): int
    {
        $running = DB::table('software_install_runs')->where('status', 'running')->get();

        $this->info("Checking {$running->count()} running install(s).");

        foreach ($running as $run) {
            if (!$run->fleet_execution_id) {
                continue; // the launch itself already failed and was recorded as such
            }

            $result = FleetScriptRunner::poll($run->fleet_execution_id);

            if ($result['error']) {
                // A poll error is NOT the same as the script failing -- leave it
                // running and retry next tick, matching the same real
                // offline-tolerance already proven for backup and role-setup runs.
                $this->warn("  {$run->hostname}: poll error ({$result['error']}) -- leaving as running.");
                continue;
            }

            if ($result['pending']) {
                // Normal and expected: Fleet holds the script until the host
                // next reports back. Confirmed directly against Fleet 4.88.0.
                $this->line("  {$run->hostname}: still pending on the device.");
                continue;
            }

            $ok = $result['exit_code'] === 0;

            DB::table('software_install_runs')->where('id', $run->id)->update([
                'status'      => $ok ? 'success' : 'failed',
                'output'      => mb_substr((string) ($result['output'] ?? ''), 0, 60000),
                'finished_at' => now(),
                'updated_at'  => now(),
            ]);

            if ($ok) {
                $this->info("  {$run->hostname}: SUCCESS.");
                continue;
            }

            $this->warn("  {$run->hostname}: FAILED (exit {$result['exit_code']}).");

            \App\Support\Health\OperatorAlert::send('Software install script failed', [
                ['level' => 'warning', 'area' => 'software',
                 'message' => "tenant={$run->tenant_id} host={$run->hostname} exit_code={$result['exit_code']}"],
            ]);
        }

        return self::SUCCESS;
    }
}
