<?php

namespace App\Console\Commands;

use App\Support\Backup\FleetScriptRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('role-setup:poll-runs')]
#[Description('Check on every currently-running role setup script and record completion')]
class PollRoleSetupRuns extends Command
{
    public function handle(): int
    {
        $running = DB::table('role_setup_runs')->where('status', 'running')->get();

        foreach ($running as $run) {
            if (!$run->fleet_execution_id) {
                continue; // launch itself already failed and was recorded as such
            }

            $result = FleetScriptRunner::poll($run->fleet_execution_id);

            if ($result['error']) {
                // A poll error is NOT the same as the script failing — leave
                // it running and retry next tick, matching the same
                // offline-tolerance already proven for backup runs.
                continue;
            }

            if ($result['pending']) {
                continue;
            }

            DB::table('role_setup_runs')->where('id', $run->id)->update([
                'status' => $result['exit_code'] === 0 ? 'success' : 'failed',
                'output' => $result['output'],
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            if ($result['exit_code'] !== 0) {
                \App\Support\Health\OperatorAlert::send('Role setup script failed', [
                    ['level' => 'warning', 'area' => 'wazuh',
                     'message' => "tenant={$run->tenant_id} host={$run->hostname} role={$run->role_group} exit_code={$result['exit_code']}"],
                ]);
            }
        }

        return self::SUCCESS;
    }
}
