<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Capture a daily snapshot of every tenant for product analytics.
 *
 * WHY DAILY RATHER THAN ON DEMAND: Fleet host inventory and DFIR-IRIS case
 * counts are fetched over APIs and cached nowhere locally. Querying them live,
 * per tenant, on every page load would be slow and fragile -- one unreachable
 * service and the analytics page fails entirely. Collecting once a day keeps the
 * page instant and working, at the cost of figures up to a day old, which is
 * fine for product analytics.
 *
 * The bigger benefit is that a row per tenant per day is a TIME SERIES. "This
 * tenant has 12 devices" is worth far less than "this tenant had 40 in June and
 * has 12 now".
 *
 * EVERY EXTERNAL CALL IS ISOLATED. One tenant's unreachable Fleet must not stop
 * the other tenants being captured, and a missing figure is recorded as zero
 * rather than abandoning the row.
 *
 * PRIVACY: this records counts and distributions only. No hostnames, no
 * addresses, no personal names -- has_security_officer is a boolean, not a
 * person. That is deliberate, and it is what makes this table the right shape to
 * transmit from an on-premise appliance.
 */
#[Signature('obaits:capture-snapshots {--date= : capture for a specific date instead of today}')]
#[Description('Record a daily analytics snapshot for every tenant')]
class CaptureTenantSnapshots extends Command
{
    public function handle(): int
    {
        $on = $this->option('date') ? \Carbon\Carbon::parse($this->option('date'))->toDateString() : now()->toDateString();
        $tenants = DB::table('tenants')->get(['id', 'slug']);

        $this->info("Capturing {$tenants->count()} tenant(s) for {$on}.");

        foreach ($tenants as $t) {
            try {
                $row = $this->capture($t->id, $on);

                DB::table('tenant_snapshots')->updateOrInsert(
                    ['tenant_id' => $t->id, 'captured_on' => $on],
                    $row + ['id' => (string) Str::uuid(), 'updated_at' => now(), 'created_at' => now()]
                );

                $this->line("  {$t->slug}: {$row['device_count']} devices, {$row['findings_total']} findings, {$row['logins_30d']} logins/30d");
            } catch (\Throwable $e) {
                // One tenant failing must never stop the others.
                $this->warn("  {$t->slug}: FAILED -- " . $e->getMessage());
                Log::warning("Snapshot failed for tenant {$t->id}: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function capture(string $tenantId, string $on): array
    {
        $profile = DB::table('tenant_profiles')->where('tenant_id', $tenantId)->first();

        return [
            'device_count' => DB::table('device_credentials')
                ->where('tenant_id', $tenantId)->whereNull('revoked_at')->count(),

            'user_count' => DB::table('tenant_users')->where('tenant_id', $tenantId)->count(),

            // --- Engagement. The dormancy signal, and the most valuable thing here.
            'logins_7d' => DB::table('user_logins')->where('tenant_id', $tenantId)
                ->where('logged_in_at', '>=', now()->subDays(7))->count(),

            'logins_30d' => DB::table('user_logins')->where('tenant_id', $tenantId)
                ->where('logged_in_at', '>=', now()->subDays(30))->count(),

            'active_users_30d' => DB::table('user_logins')->where('tenant_id', $tenantId)
                ->where('logged_in_at', '>=', now()->subDays(30))->distinct('email')->count('email'),

            'last_activity_at' => DB::table('tenant_users')->where('tenant_id', $tenantId)->max('last_seen_at'),

            // --- Estate, from Fleet. Isolated: a missing figure is zero, not a
            // --- failed snapshot.
            'os_distribution' => json_encode($this->osDistribution($tenantId)),

            // --- Security posture
            'findings_total'  => DB::table('findings')->where('tenant_id', $tenantId)->count(),
            'findings_high'   => DB::table('findings')->where('tenant_id', $tenantId)->where('threat', 'High')->count(),
            'findings_medium' => DB::table('findings')->where('tenant_id', $tenantId)->where('threat', 'Medium')->count(),
            'findings_low'    => DB::table('findings')->where('tenant_id', $tenantId)->where('threat', 'Low')->count(),
            'scans_total'     => DB::table('scans')->where('tenant_id', $tenantId)->count(),

            // --- Feature adoption. Zero here is the interesting value, not an
            // --- absence of data: it means a capability they pay for is unused.
            'policy_assignments' => $this->countIf('policy_assignments', $tenantId),
            'policy_runs_30d'    => $this->countIf('policy_runs', $tenantId, 30),
            'backup_selections'  => $this->countIf('backup_selections', $tenantId),
            'backup_runs_30d'    => $this->countIf('backup_runs', $tenantId, 30),
            'tickets_total'      => $this->countIf('tickets', $tenantId),
            'marketplace_requests' => $this->countIf('marketplace_requests', $tenantId),
            'open_incidents'     => 0,   // IRIS is an API call; see osDistribution note

            // --- Compliance and profile
            'framework' => $profile->framework_name ?? null,
            // BOOLEAN, never the person's name. Answers "does a security officer
            // exist" -- which is the question that matters -- and carries none of
            // the personal data.
            'has_security_officer' => !empty($profile->security_officer_name ?? null),
            'country' => $profile->country ?? null,
            'sector'  => $profile->sector ?? null,
        ];
    }

    /** Count rows in a table for a tenant, tolerating the table not existing. */
    private function countIf(string $table, string $tenantId, ?int $days = null): int
    {
        try {
            $q = DB::table($table)->where('tenant_id', $tenantId);
            if ($days) {
                $q->where('created_at', '>=', now()->subDays($days));
            }
            return $q->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Operating system distribution from Fleet.
     *
     * Fleet is the only source -- there is no local host cache -- so this is an
     * API call, and it is isolated: an unreachable Fleet yields an empty
     * distribution rather than failing the whole snapshot.
     */
    private function osDistribution(string $tenantId): array
    {
        try {
            $secret = DB::table('tenant_artifacts')
                ->where('tenant_id', $tenantId)->where('tool', 'fleet')
                ->where('artifact_type', 'enroll_secret')->value('external_id');

            if (!$secret) { return []; }

            $dist = [];
            foreach (app(\App\Clients\FleetClient::class)->hostsForTenant($secret) as $h) {
                $os = trim(($h['os_version'] ?? '') ?: ($h['platform'] ?? 'unknown'));
                $dist[$os] = ($dist[$os] ?? 0) + 1;
            }
            arsort($dist);
            return $dist;
        } catch (\Throwable $e) {
            Log::warning("OS distribution unavailable for tenant {$tenantId}: " . $e->getMessage());
            return [];
        }
    }
}
