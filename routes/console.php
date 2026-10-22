<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


Schedule::command('intel:kev')->dailyAt('03:00');
Schedule::command('intel:epss')->dailyAt('03:30');
// Sunday 02:00 — chosen to avoid the GVM feed-update window.
Schedule::command('scans:weekly')->weeklyOn(0, '02:00');
Schedule::command('artifacts:reconcile')->hourly();
Schedule::command('health:check')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('licences:fetch')->hourly();
Schedule::command('licences:enforce')->dailyAt('02:00');
// Trial expiry, grace and suspension. Added 2026-08-30 to close the gap
// where trial_ends_at was written and read by nothing -- a trial simply
// never ended, so no customer was ever asked to pay.
//
// Runs AFTER licences:enforce deliberately: both can set tenants.status,
// and a licence lockout should take precedence over a billing one rather
// than the two racing within the same minute.
Schedule::command('obaits:enforce-billing')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('licences:remind')->dailyAt('08:00');
Schedule::command('backups:enforce')->dailyAt('02:15');
Schedule::command('backups:remind')->dailyAt('08:15');
Schedule::command('backups:poll-runs')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('backups:dispatch-due')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('backups:poll-restores')->everyTwoMinutes()->withoutOverlapping();
// headscale:sync-node-cache, headscale:rotate-preauth-keys, and
// headscale:process-revocations all require root (each touches a
// tenant's headscale unix socket, 0750 headscale:headscale). REAL BUG
// FOUND 2026-07-27: this Schedule::command() list runs via schedule:run,
// which cron invokes as 'administrator' on this box — every single
// scheduled run of these three had been silently failing since the
// moment they were added. Moved to a SEPARATE root crontab entry
// instead (sudo crontab -l on the VPS) — this removal was itself
// attempted once already and never actually shipped (same recurring
// 'edited but not committed' pattern this session has hit before);
// confirmed this time via git diff before committing.
Schedule::command('role-setup:poll-runs')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('obaits:poll-software-installs')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('obaits:poll-policy-runs')->everyTwoMinutes()->withoutOverlapping();
// Added 2026-08-25. RunScan used to poll to completion inside the job and
// held a worker for the scan's whole duration -- a real scan over a tenant
// VPN ran two hours and was killed by the worker timeout, leaving completed
// findings uncollected in GVM. Launch and exit, collect here.
Schedule::command('obaits:poll-scans')->everyTwoMinutes()->withoutOverlapping();
// Added 2026-08-26. A pool member can be RUNNING, pass gvmd's own
// verify_scanner with a 200, and still reach nothing -- confirmed when ospd
// attached to a sidecar container that no longer existed and sat with an
// empty routing table. Container health checks cannot see that.
// Every ten minutes: often enough to catch a dead member before a scan is
// allocated to it, rare enough that the docker exec calls are not a burden.
Schedule::command('obaits:verify-scanner-pool')->everyTenMinutes()->withoutOverlapping();
// Device record drift. FOUR classes of orphaned record have been found in
// this codebase and every one was found by ACCIDENT, while investigating
// something else. Daily is enough -- drift accumulates over weeks, not
// minutes, and a device powered off overnight is not drift.
Schedule::command('obaits:check-device-drift')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('obaits:reconcile-policy-enforcement')->everyTwoMinutes()->withoutOverlapping();
// REMOVED FROM THE SCHEDULE 2026-08-23. This pushed the refresh script to
// every host, every hour, through Fleet -- which SERIALISES script
// execution per host. The pushes accumulated into a confirmed 240-item
// backlog across 3 hosts (oldest queued FIVE DAYS), starving genuine
// Group Policy checks of any chance to run at all. Refresh now runs from
// a device-side systemd timer / scheduled task installed at enrollment.
//
// The COMMAND still exists, deliberately: it remains genuinely useful as a
// manual, operator-invoked force-refresh across a fleet after a major
// threat event. It must NOT be put back on a schedule -- that is precisely
// what caused the starvation above.
Schedule::command('agents:reconcile')->hourly()->withoutOverlapping();
Schedule::command('jobs:alert-failures')->everyFifteenMinutes()->withoutOverlapping();
// The platform's OWN backup. Added 2026-08-25 after finding that Obaits
// sold backup as a product while backing up nothing of its own -- losing
// this host would have lost every tenant's data with no copy anywhere.
// 01:30 keeps it clear of the 02:00 enforcement work and the 03:00 feeds.
Schedule::command('obaits:backup-control-plane')->dailyAt('01:30')->withoutOverlapping();
// Daily product-analytics snapshot per tenant. Fleet host inventory and IRIS
// case counts are API calls with no local cache, so collecting once a day
// keeps the analytics page instant and working even when a service is down.
// A row per tenant per day is also a time series, which is what makes trend
// and dormancy answerable rather than only point-in-time counts.
// 02:15 -- after the backup, before the intel feeds.
Schedule::command('obaits:capture-snapshots')->dailyAt('02:15')->withoutOverlapping();
// The marketplace and training catalogues are CURATED and shown under our
// brand, which is the whole argument for curation over an open listing. A
// dead link is therefore exactly the failure curation exists to prevent, and
// external URLs decay quietly -- courses retire, companies move their sites.
// Weekly is enough: these change slowly, and hammering third-party sites
// daily to check they still exist would be discourteous.
Schedule::command('obaits:check-links')->weeklyOn(1, '04:00')->withoutOverlapping();
// feature_usage_seen exists ONLY to make distinct_users countable for the
// current day. It is not a per-person browsing history and must not become
// one, so anything older than a week is removed. The aggregate in
// feature_usage is what is actually kept.
Schedule::call(function () {
    \Illuminate\Support\Facades\DB::table('feature_usage_seen')
        ->where('used_on', '<', now()->subDays(7)->toDateString())->delete();
})->dailyAt('03:10');
// Daily, not hourly: this watches for a WEEK of silence, so checking more
// often would only produce the same alert repeatedly. Devices refresh
// themselves hourly via their own timer; this notices when they stop.
Schedule::command('obaits:alert-threat-intel-silence')->dailyAt('09:00');
Schedule::command('obaits:sync-network-interfaces')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('obaits:link-zabbix-templates')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('obaits:sync-package-mirror')->daily()->withoutOverlapping();
Schedule::command('obaits:auto-assign-device-groups')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('obaits:reconcile-software-pushes')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('obaits:process-device-removals')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('intel:cve-severity')->hourly()->withoutOverlapping();


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
