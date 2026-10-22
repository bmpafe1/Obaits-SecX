<?php

namespace App\Console\Commands;

use App\Support\PolicyRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Real gap closed 2026-08-12: enforcement was a ONE-TIME SWEEP. Clicking
 * "Enforce" queried policy_runs for devices showing firewall_disabled AT
 * THAT EXACT MOMENT and remediated only those. Two real, silent gaps:
 *
 *   1. A device OFFLINE when Enforce was clicked has no run row (or a
 *      stale one) at that moment -- it's silently excluded from the
 *      sweep, and once the assignment is marked 'enforce', nothing ever
 *      goes back to catch it.
 *   2. A device ADDED to the group AFTER enforcement was already enabled
 *      has no run row at all -- nothing proactively checks or remediates
 *      it; it sits invisible until someone manually re-checks and
 *      re-triggers enforcement (which the UI doesn't even cleanly
 *      support without a Disable->Enable round-trip).
 *
 * This command closes both, on a schedule, for every assignment
 * currently in enforce mode -- same reconcile pattern already proven for
 * obaits:reconcile-software-pushes, same retry-cap discipline as
 * SoftwarePush.
 *
 * DELIBERATE, STATED SCOPE LIMIT: does NOT re-check devices that are
 * already compliant (phase=remediate, result=firewall_enabled) for
 * config DRIFT -- i.e. someone manually re-disabling the firewall after
 * enforcement ran. That is continuous-enforcement/drift-detection, a
 * genuinely different, larger feature than "catch what the one-time
 * sweep missed", and is not what this command was scoped to solve.
 *
 * DELIBERATE, STATED GENERALIZATION DEBT: the non-compliant value
 * ('firewall_disabled') is hardcoded here, matching the SAME hardcoding
 * already present throughout GroupPolicies.php itself -- there is still
 * only one real policy on the platform. Making "what counts as
 * non-compliant" a per-policy, data-driven concept is real design work
 * that belongs with the second policy, not bolted on here speculatively.
 */
#[Signature('obaits:reconcile-policy-enforcement')]
#[Description('Catch devices missed by enforcement\'s one-time sweep: offline-at-enable-time or added-after-enable devices')]
class ReconcilePolicyEnforcement extends Command
{
    public const MAX_ATTEMPTS = 3;


    public function handle(): int
    {
        // REAL GENERALIZATION FIX 2026-08-12: joined in the policy's own
        // declared non_compliant_prefix here, once, rather than a second
        // query per assignment inside the loop below.
        $assignments = DB::table('policy_assignments')
            ->join('policies', 'policy_assignments.policy_id', '=', 'policies.id')
            ->where('policy_assignments.mode', 'enforce')
            ->select('policy_assignments.*', 'policies.non_compliant_prefix')
            ->get();

        if ($assignments->isEmpty()) {
            $this->info('No assignments currently in enforce mode.');
            return self::SUCCESS;
        }

        $checked = 0; $remediated = 0; $retried = 0; $skipped = 0; $exhausted = 0;

        // Platform maps are real, per-tenant Fleet calls -- cached here so
        // a tenant with multiple enforced assignments doesn't refetch the
        // same host list repeatedly in one run.
        $platformMapsByTenant = [];

        foreach ($assignments as $assignment) {
            $platformById = $platformMapsByTenant[$assignment->tenant_id]
                ??= $this->platformMap($assignment->tenant_id);

            // REAL FEATURE 2026-08-13: excluded devices are the most
            // important place to guard in this ENTIRE command -- this is
            // the one code path that runs unattended, every 2 minutes,
            // with no human in the loop. An exclusion added mid-run must
            // take effect on the very next cycle, which it does, since
            // this query re-reads policy_exclusions fresh every time.
            $excludedHostIds = DB::table('policy_exclusions')
                ->where('policy_assignment_id', $assignment->id)
                ->pluck('fleet_host_id')->all();

            $members = DB::table('device_group_members')
                ->where('device_group_id', $assignment->device_group_id)
                ->whereNotIn('fleet_host_id', $excludedHostIds)
                ->get();
            foreach ($members as $member) {
                $hostId = (int) $member->fleet_host_id;
                $platform = $platformById[$hostId] ?? '';

                $run = DB::table('policy_runs')
                    ->where('policy_assignment_id', $assignment->id)
                    ->where('fleet_host_id', $hostId)
                    ->first();

                if (!$run) {
                    // Never checked at all -- new to the group, or was
                    // offline through every prior check attempt.
                    $this->info("  {$member->hostname}: never checked -- checking now.");
                    PolicyRunner::checkDevice($assignment->id, $hostId, $member->hostname, $platform, 'reconciler');
                    $checked++;
                    continue;
                }

                if (in_array($run->status, ['pending', 'running'], true)) {
                    $skipped++; // already in flight, do not queue a second script on the same host
                    continue;
                }

                if ($run->phase === 'check' && $run->status === 'success'
                    && $assignment->non_compliant_prefix
                    && str_starts_with((string) $run->result, $assignment->non_compliant_prefix)) {
                    // The exact gap this command exists for: a real,
                    // confirmed check result showing non-compliance that
                    // was never remediated -- because it either finished
                    // AFTER the one-time sweep already ran, or the device
                    // was offline at that moment.
                    $this->info("  {$member->hostname}: checked non-compliant, never remediated -- remediating now.");
                    PolicyRunner::remediateDevice($assignment->id, $hostId, $member->hostname, $platform, 'reconciler');
                    $remediated++;
                    continue;
                }

                if ($run->phase === 'remediate' && $run->status === 'failed') {
                    if ((int) $run->attempts >= self::MAX_ATTEMPTS) {
                        $exhausted++;
                        continue; // needs a human, matching SoftwarePush's own retry-cap philosophy
                    }
                    $this->warn("  {$member->hostname}: remediation previously failed ({$run->attempts} attempt(s)) -- retrying.");
                    PolicyRunner::remediateDevice($assignment->id, $hostId, $member->hostname, $platform, 'reconciler');
                    $retried++;
                    continue;
                }

                $skipped++; // already compliant, or a check failure not yet worth retrying automatically
            }
        }

        $this->line('');
        $this->info("Checked: {$checked}  Remediated: {$remediated}  Retried: {$retried}  Skipped: {$skipped}  Exhausted: {$exhausted}");

        return self::SUCCESS;
    }

    // Same shape already proven in GroupPolicies::platformMap() /
    // DeviceGroups::platformMap() -- fetched once, reused across every
    // member of a given tenant's assignments within this single run.
    private function platformMap(string $tenantId): array
    {
        $fleetSecret = DB::table('tenant_artifacts')
            ->where('tenant_id', $tenantId)->where('tool', 'fleet')
            ->where('artifact_type', 'enroll_secret')->value('external_id');
        if (!$fleetSecret) return [];

        try {
            $hosts = app(\App\Clients\FleetClient::class)->hostsForTenant($fleetSecret);
        } catch (\Throwable $e) {
            return [];
        }

        $map = [];
        foreach ($hosts as $h) {
            $id = (int) ($h['id'] ?? 0);
            if ($id) $map[$id] = PolicyRunner::normalizePlatform($h['platform'] ?? '');
        }
        return $map;
    }
}
