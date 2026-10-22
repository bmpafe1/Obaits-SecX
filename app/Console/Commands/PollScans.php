<?php

namespace App\Console\Commands;

use App\Support\Audit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Collect the real result of every launched scan.
 *
 * RunScan used to poll to completion inside the job, holding a worker for the
 * scan's whole duration. REAL FAILURE 2026-08-25: a scan of a real device over a
 * tenant VPN ran for two hours and was then killed by the worker timeout. GVM
 * had finished successfully -- the task reported Done and the findings were
 * there -- but nothing was alive to collect them. The row sat at 96% with zero
 * findings and needed hand recovery. A scan against localhost took six minutes,
 * so this only surfaced once scanning did something real.
 *
 * Same pattern as PollPolicyRuns and PollSoftwareInstalls: launch, then poll
 * from something restartable. A worker restart can no longer lose a scan, and a
 * slow target no longer ties up a worker.
 *
 * A poll error is "not yet known", never a failure -- same offline tolerance as
 * the other pollers.
 */
#[Signature('obaits:poll-scans')]
#[Description('Collect results for every running scan and record its real outcome')]
class PollScans extends Command
{
    /**
     * A scan still running after this long has almost certainly lost its task in
     * GVM. This is the FOURTH place on this platform to need staleness handling,
     * after policy runs, software installs and scanner allocation -- each time
     * discovered by a row sitting in 'running' forever. Built in from the start
     * here rather than after the next outage.
     *
     * Generous deliberately: a real scan over a relayed VPN connection took two
     * hours, and cutting off a slow but working scan would be worse than waiting.
     */
    private const STALE_AFTER_HOURS = 12;

    public function handle(): int
    {
        $running = DB::table('scans')
            ->where('status', 'running')
            ->whereNotNull('gvm_task_id')
            ->get();

        $this->info("Checking {$running->count()} running scan(s).");

        $gvm = app(\App\Clients\GvmClient::class);

        foreach ($running as $scan) {
            // Cancellation from the UI. The old in-job loop checked this on
            // every iteration; without it here the Cancel button would silently
            // stop working.
            if (DB::table('scans')->where('id', $scan->id)->value('status') === 'cancelled') {
                continue;
            }

            try {
                $st = $gvm->taskStatus($scan->gvm_task_id);
            } catch (\Throwable $e) {
                // Not yet known, not a failure.
                $this->warn("  {$scan->target}: poll error ({$e->getMessage()}) -- leaving as running.");
                $this->markStaleIfOld($scan);
                continue;
            }

            $status = $st['status'] ?? '';
            $progress = (int) ($st['progress'] ?? 0);

            if (!in_array($status, ['Done', 'Stopped', 'Interrupted'], true)) {
                DB::table('scans')->where('id', $scan->id)
                    ->update(['progress' => max(0, $progress), 'updated_at' => now()]);
                $this->line("  {$scan->target}: {$status} ({$progress}%)");
                $this->markStaleIfOld($scan);
                continue;
            }

            $this->collect($gvm, $scan, $status);
        }

        return self::SUCCESS;
    }

    private function collect(\App\Clients\GvmClient $gvm, object $scan, string $gvmStatus): void
    {
        $findings = $gvm->getResults($scan->gvm_task_id);

        foreach ($findings as $f) {
            DB::table('findings')->insert([
                'id' => (string) Str::uuid(),
                'scan_id' => $scan->id,
                'tenant_id' => $scan->tenant_id,
                'name' => $f['name'] ?? 'Unknown',
                'host' => $f['host'] ?? null,
                'port' => $f['port'] ?? null,
                'cve' => $f['cve'] ?? null,
                'severity' => is_numeric($f['severity'] ?? null) ? $f['severity'] : null,
                'threat' => $f['threat'] ?? null,
                'description' => $f['description'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        \App\Support\FindingScorer::scoreScan($scan->id);

        DB::table('scans')->where('id', $scan->id)->update([
            'status' => 'done', 'progress' => 100, 'finished_at' => now(), 'updated_at' => now(),
        ]);

        Audit::log($scan->tenant_id, 'system', 'scan.completed', 'success', 'gvm',
            ['target' => $scan->target, 'findings' => count($findings), 'gvm_status' => $gvmStatus]);

        $this->info("  {$scan->target}: {$gvmStatus} -- " . count($findings) . ' finding(s) stored.');

        $this->sendReport($scan);
    }

    /**
     * Guarded by report_sent_at, not by the status transition. A scheduled
     * command that emails customers must never be able to send twice, and a
     * crash between sending and updating status would otherwise resend on the
     * next tick two minutes later.
     */
    private function sendReport(object $scan): void
    {
        $fresh = DB::table('scans')->where('id', $scan->id)->first();
        if ($fresh->report_sent_at) {
            return;
        }

        DB::table('scans')->where('id', $scan->id)
            ->update(['report_sent_at' => now(), 'updated_at' => now()]);

        $admin = DB::table('tenant_users')->where('tenant_id', $scan->tenant_id)->first();
        if (!$admin) {
            Log::warning("Scan {$scan->id} completed but tenant {$scan->tenant_id} has no admin user to notify");
            Audit::log($scan->tenant_id, 'system', 'scan.no_recipient', 'failure', 'gvm', ['scan' => $scan->id]);
            return;
        }

        try {
            Mail::to($admin->email)->send(new \App\Mail\ScanReport($scan->id));
        } catch (\Throwable $e) {
            Log::warning("Scan report for {$scan->id} could not be sent: " . $e->getMessage());
            Audit::log($scan->tenant_id, 'system', 'scan.report_failed', 'failure', 'gvm',
                ['scan' => $scan->id, 'error' => $e->getMessage()]);
        }
    }

    private function markStaleIfOld(object $scan): void
    {
        $started = $scan->started_at ?? $scan->created_at;
        if (!$started) {
            return;
        }

        if (now()->diffInHours(\Carbon\Carbon::parse($started)) < self::STALE_AFTER_HOURS) {
            return;
        }

        DB::table('scans')->where('id', $scan->id)
            ->update(['status' => 'failed', 'updated_at' => now()]);

        Audit::log($scan->tenant_id, 'system', 'scan.stale', 'failure', 'gvm', [
            'target' => $scan->target,
            'started_at' => (string) $started,
            'note' => 'still running after ' . self::STALE_AFTER_HOURS . 'h; task presumed lost',
        ]);

        $this->warn("  {$scan->target}: stale after " . self::STALE_AFTER_HOURS . 'h -- marked failed.');
    }
}
