<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Support\Health\OperatorAlert;

/**
 * Alert on devices whose baseline threat-intel protection has gone stale.
 *
 * REAL BACKGROUND 2026-08-23: threat-intel refresh moved from a portal-pushed
 * Fleet script onto a device-side timer/scheduled task, because the push was
 * building an unbounded per-host queue in Fleet and starving Group Policy
 * checks. That move is correct, but it hands the schedule to the device -- and
 * a device-side timer that quietly stops working is invisible from the portal.
 * This command is what makes that failure visible.
 *
 * ABSENCE is the signal, deliberately. It fires on last_success_at, not on
 * reported failures: a device whose timer never fires at all cannot report its
 * own failure, and that is the exact failure mode most worth catching. It is
 * also why no forged health report can suppress this alert -- the check is
 * database-side and asks when a device last genuinely succeeded.
 *
 * Same first-run discipline as jobs:alert-failures: adopt current state
 * silently on the first run rather than alerting on every pre-existing gap at
 * once. The first thing a new alert does should not be to cry wolf.
 */
#[AsCommand(name: 'obaits:alert-threat-intel-silence', description: 'Alert on devices whose threat-intel refresh has gone silent')]
class AlertThreatIntelSilence extends Command
{
    protected $signature = 'obaits:alert-threat-intel-silence {--dry-run}';
    protected $description = 'Alert on devices whose threat-intel refresh has gone silent';

    private const FIRST_RUN = 'threat-intel.alert-silence.initialised';

    /** A week of nothing but failures, per Brian's explicit choice 2026-08-23. */
    private const SILENT_DAYS = 7;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subDays(self::SILENT_DAYS);

        // Devices that have never succeeded are only counted once their row has
        // existed longer than the window -- otherwise a device enrolled this
        // morning would alert immediately, before it has had any real chance.
        $silent = DB::table('threat_intel_device_reports')
            ->where(function ($q) use ($cutoff) {
                $q->where('last_success_at', '<', $cutoff)
                  ->orWhere(function ($q2) use ($cutoff) {
                      $q2->whereNull('last_success_at')->where('created_at', '<', $cutoff);
                  });
            })
            ->orderBy('tenant_id')
            ->get();

        if (!Cache::get(self::FIRST_RUN)) {
            if (!$dry) Cache::forever(self::FIRST_RUN, true);
            $this->info('first run -- adopting current state, ' . $silent->count() . ' device(s) already silent, no alerts sent');
            return 0;
        }

        if ($silent->isEmpty()) {
            $this->info('no silent devices');
            return 0;
        }

        // 'slug', not 'name' -- confirmed live 2026-08-23 that the tenants
        // table has no name column at all. pluck('name') would have returned
        // nulls and every alert would have named no tenant.
        $tenantNames = DB::table('tenants')->pluck('slug', 'id');
        $problems = [];

        foreach ($silent as $d) {
            $tenant = $tenantNames[$d->tenant_id] ?? $d->tenant_id;
            $last = $d->last_success_at
                ? 'last succeeded ' . \Carbon\Carbon::parse($d->last_success_at)->diffForHumans()
                : 'has NEVER successfully refreshed';
            $detail = $d->last_error ? ' Last error: ' . mb_strimwidth($d->last_error, 0, 200, '...') : '';

            $problems[] = [
                'level'   => 'critical',
                'area'    => 'threat-intel protection',
                'message' => $tenant . ' / ' . $d->hostname . ': ' . $last
                           . ' -- this device is running on a stale block list.' . $detail,
            ];

            $this->warn("[{$tenant}] {$d->hostname}: {$last}");
        }

        if (!$dry) {
            OperatorAlert::send(
                $silent->count() . ' device(s) with stale threat-intel protection',
                $problems
            );
        }

        $this->info(($dry ? '[dry-run] ' : '') . 'reported ' . $silent->count() . ' silent device(s)');
        return 0;
    }
}
