<?php

namespace App\Console\Commands;

use App\Support\Backup\FleetScriptRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Near-copy of PollSoftwareInstalls, same reasoning: fleet_execution_id
 * exists specifically so a launched run's real result can be polled.
 * Same offline tolerance -- a poll error is "not yet known", never
 * treated as failure.
 */
#[Signature('obaits:poll-policy-runs')]
#[Description('Check on every currently-running policy check and record its real completion result')]
class PollPolicyRuns extends Command
{
    public function handle(): int
    {
        $running = DB::table('policy_runs')->where('status', 'running')->get();

        $this->info("Checking {$running->count()} running policy run(s).");

        foreach ($running as $run) {
            if (!$run->fleet_execution_id) {
                continue;
            }

            $result = FleetScriptRunner::poll($run->fleet_execution_id);

            if ($result['error']) {
                $this->warn("  {$run->hostname}: poll error ({$result['error']}) -- leaving as running.");
                continue;
            }

            if ($result['pending']) {
                $this->line("  {$run->hostname}: still pending on the device.");
                continue;
            }

            $ok = $result['exit_code'] === 0;

            DB::table('policy_runs')->where('id', $run->id)->update([
                'status' => $ok ? 'success' : 'failed',
                'output' => mb_substr((string) ($result['output'] ?? ''), 0, 60000),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            if ($ok) {
                $this->info("  {$run->hostname}: SUCCESS (check ran; see result field for findings).");
            } else {
                $this->warn("  {$run->hostname}: FAILED (exit {$result['exit_code']}).");
            }
        }

        return self::SUCCESS;
    }
}
