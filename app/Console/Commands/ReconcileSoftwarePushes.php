<?php

namespace App\Console\Commands;

use App\Clients\FleetClient;
use App\Support\SoftwarePush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Real Phase B safety net. The original design was agreed as "immediate
 * trigger + periodic reconciliation" but only the immediate-trigger half was
 * ever built -- a device offline when software was assigned to its group had
 * no catch-up mechanism at all, and the platform reported nothing wrong.
 *
 * For every real (group member x assigned software) pair this decides, from
 * the real recorded state, whether a push is genuinely still owed:
 *
 *   no run at all -> push. The main real case: the device joined the group
 *       after the software was assigned, or was offline at assignment time.
 *
 *   pending       -> push. Set by SoftwarePush when Fleet returned 409
 *       host_busy (Fleet serialises script execution per host), or when the
 *       launch never happened. A real deferral, not a real failure.
 *
 *   running       -> LEAVE ALONE until genuinely stale. Fleet holds a script
 *       for an offline host and delivers it whenever that host next reports
 *       back -- confirmed directly on 2026-08-06, where a real install queued
 *       ~35 hours earlier was still legitimately pending. Queueing a second
 *       script would just make the device run both back-to-back on reconnect
 *       and download the same installer twice. After STALE_RUNNING_DAYS the
 *       run is aged out to 'failed' so it becomes eligible again on the next
 *       tick -- covering a decommissioned machine that will never report.
 *
 *   failed        -> push, while attempts remain. A genuinely broken install
 *       (bad silent-install command, unsupported OS, no disk space) must NOT
 *       retry forever every 15 minutes -- it would hammer the device and bury
 *       the real signal. After MAX_ATTEMPTS it is left for a human.
 *
 *   success       -> skip.
 *
 * DELIBERATE SCOPE LIMIT: "installed" here means "our push completed
 * successfully", judged from our own real install records -- NOT re-queried
 * from Fleet's own software inventory. Fleet's inventory refresh lag would
 * make a just-installed package look missing and cause a real reinstall loop.
 * The honest tradeoff: if someone manually uninstalls the software we will
 * not notice. Continuous enforcement of installed state is a genuinely
 * different, larger feature and is not smuggled in here.
 */
#[Signature('obaits:reconcile-software-pushes {--dry-run : Report what would be pushed without launching anything}')]
#[Description('Real Phase B safety net: re-push any assigned software a group member is genuinely still missing')]
class ReconcileSoftwarePushes extends Command
{
    /**
     * How long a run may sit at 'running' before it is treated as abandoned.
     * Confirmed against real data 2026-08-06: a legitimately-pending install
     * was still valid at ~35 hours, so anything shorter than a couple of days
     * would wrongly age out real, healthy runs. Seven days covers a laptop
     * away for a week while still freeing a decommissioned machine's pair.
     */
    public const STALE_RUNNING_DAYS = 7;

    public function handle(FleetClient $fleet): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN -- nothing will actually be pushed.');
        }

        $pushed = 0; $skipped = 0; $exhausted = 0; $agedOut = 0;

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $assignments = DB::table('software_group_assignments')
                ->where('tenant_id', $tenantId)->get();
            if ($assignments->isEmpty()) continue;

            $fleetSecret = DB::table('tenant_artifacts')
                ->where('tenant_id', $tenantId)->where('tool', 'fleet')
                ->where('artifact_type', 'enroll_secret')->value('external_id');
            if (!$fleetSecret) continue;

            // Fetched ONCE per tenant and reused. DeviceGroups' own
            // platformForHost() calls this per member inside the push loop,
            // which is a real N+1 against Fleet -- deliberately not repeated.
            try {
                $hosts = $fleet->hostsForTenant($fleetSecret);
            } catch (\Throwable $e) {
                $this->warn("Tenant {$tenantId}: could not reach Fleet, skipping.");
                continue;
            }

            $platformById = [];
            foreach ($hosts as $h) {
                $id = (int) ($h['id'] ?? 0);
                if ($id) $platformById[$id] = strtolower($h['platform'] ?? '');
            }

            foreach ($assignments as $assignment) {
                $members = DB::table('device_group_members')
                    ->where('device_group_id', $assignment->device_group_id)->get();

                foreach ($members as $member) {
                    $hostId = (int) $member->fleet_host_id;

                    // Device no longer in Fleet at all (deleted / unenrolled).
                    // Not a real failure -- there is simply nothing to push to.
                    if (!array_key_exists($hostId, $platformById)) {
                        $skipped++;
                        continue;
                    }

                    $run = DB::table('software_install_runs')
                        ->where('software_group_assignment_id', $assignment->id)
                        ->where('fleet_host_id', $hostId)
                        ->first();

                    if ($run && $run->status === 'success') { $skipped++; continue; }

                    if ($run && $run->status === 'running') {
                        $age = \Carbon\Carbon::parse($run->updated_at ?? $run->created_at);
                        if ($age->gt(now()->subDays(self::STALE_RUNNING_DAYS))) {
                            $skipped++;
                            continue; // legitimately still pending on the device
                        }

                        $agedOut++;
                        $this->warn("  {$member->hostname}: run stuck since {$age->toDateTimeString()} -- ageing out for retry.");
                        if (!$dryRun) {
                            DB::table('software_install_runs')->where('id', $run->id)->update([
                                'status' => 'failed',
                                'output' => 'No result from the device after ' . self::STALE_RUNNING_DAYS . ' days. Aged out for retry.',
                                'finished_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                        continue; // retried on the next tick, once genuinely failed
                    }

                    if ($run && $run->status === 'failed'
                        && (int) $run->attempts >= SoftwarePush::MAX_ATTEMPTS) {
                        $exhausted++;
                        $this->warn("  {$member->hostname}: {$run->attempts} failed attempts -- needs a human. Not retrying.");
                        continue;
                    }

                    // Genuinely owed: never attempted, deferred, or failed
                    // with attempts remaining.
                    $reason = !$run ? 'never attempted' : "previous status '{$run->status}'";
                    $this->info("  {$member->hostname}: pushing ({$reason}).");
                    $pushed++;

                    if ($dryRun) continue;

                    SoftwarePush::pushToDevice(
                        $assignment->id,
                        $hostId,
                        $member->hostname ?? (string) $hostId,
                        $platformById[$hostId],
                        'reconciler'
                    );
                }
            }
        }

        $this->line('');
        $this->info("Pushed: {$pushed}  Skipped: {$skipped}  Aged out: {$agedOut}  Exhausted: {$exhausted}");

        return self::SUCCESS;
    }
}
