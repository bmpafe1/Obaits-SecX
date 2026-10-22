<?php

namespace App\Console\Commands;

use App\Support\Backup\FleetScriptRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * REAL FEATURE 2026-08-15: baseline platform protection, deliberately
 * independent of the Group Policies engine entirely (no
 * policy_assignments/policy_runs involvement) -- confirmed with Brian
 * this is meant to be automatic, on every enrolled device, regardless of
 * whether a tenant has ever configured anything. The tenant-facing IP
 * Block Group Policy (ops/policies/ip-block/) remains completely
 * separate and unaffected -- this is an ADDITIONAL layer beneath it, not
 * a replacement.
 *
 * NO LONGER SCHEDULED as of 2026-08-23 -- this is now a MANUAL force-refresh.
 * Devices refresh themselves hourly via a systemd timer / scheduled task
 * installed at enrollment. This command previously ran hourly and pushed a
 * script to every host through Fleet, which SERIALISES script execution per
 * host: the pushes accumulated into a confirmed 240-item backlog across 3
 * hosts, the oldest queued five days, starving genuine Group Policy checks.
 * Do not put it back on a schedule.
 *
 * IMPORTANT, learned the hard way: FleetScriptRunner's own docblock states
 * that a second script on a busy host returns 409 host_busy. Confirmed
 * empirically 2026-08-23 that Fleet 4.88 instead QUEUES it and returns 202,
 * so the host_busy deferral path below never fires and provides no backpressure
 * whatsoever. Any future per-host push loop must check the host's own upcoming
 * queue (/api/v1/fleet/hosts/{id}/activities/upcoming) rather than relying on it.
 *
 * Uses FleetScriptRunner::launch() directly (fire-and-forget, matching
 * its own documented guidance that runAndWait() is only for short
 * commands from a job that can afford to block -- iterating every
 * device across every tenant sequentially would be exactly that
 * mistake). The trigger sent to Fleet is a tiny fetch-and-run command,
 * NOT the real refresh script itself -- the real script lives at
 * /enroll/threat-intel-refresh.sh and is fetched by the device, the same
 * trigger-fetch-execute pattern used everywhere else in this project to
 * stay well under Fleet's real, confirmed 10,000-character
 * script_contents limit.
 *
 * Linux only for this first rollout -- Windows Firewall's ipset
 * equivalent (a genuinely different mechanism, not a port) is real,
 * separate follow-up work.
 */
#[Signature('obaits:refresh-threat-intel {--force : Skip the confirmation prompt}')]
#[Description('Refresh the Spamhaus DROP threat-intel block list on every enrolled Linux device, across every tenant')]
class RefreshThreatIntel extends Command
{
    public function handle(): int
    {
        // MANUAL, OPERATOR-INVOKED ONLY as of 2026-08-23 -- see this class's
        // own docblock and routes/console.php. Confirmation is deliberate: on a
        // large fleet this enqueues one Fleet script per host, through a channel
        // Fleet serialises per host, which is exactly what caused a real
        // 240-item backlog. Fine as a considered one-off after a major threat
        // event; never something to fire absent-mindedly or on a schedule.
        if (!$this->option('force') && !$this->confirm(
            'This pushes an immediate refresh to EVERY enrolled device via Fleet, which serialises script execution per host. Devices already refresh hourly on their own timer. Continue?'
        )) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        $portalUrl = config('app.url');
        // REAL FEATURE 2026-08-17: Windows now covered too -- trigger
        // built per-platform, real Windows Firewall/hosts-file script
        // at ops/platform/threat-intel-refresh.ps1, same trigger-fetch-
        // execute pattern as every other real script this project uses.
        $triggerLinux = "#!/bin/bash\ncurl -sS \"{$portalUrl}/enroll/threat-intel-refresh.sh\" | sudo bash\n";
        $triggerWindows = "\$s = (Invoke-WebRequest -Uri \"{$portalUrl}/enroll/threat-intel-refresh.ps1\" -UseBasicParsing).Content; Invoke-Expression \$s\n";

        $tenants = DB::table('tenants')->where('status', 'active')->get();
        $launched = 0; $busy = 0; $failed = 0; $skipped = 0;
        foreach ($tenants as $tenant) {
            $fleetSecret = DB::table('tenant_artifacts')
                ->where('tenant_id', $tenant->id)->where('tool', 'fleet')
                ->where('artifact_type', 'enroll_secret')->value('external_id');
            if (!$fleetSecret) { $skipped++; continue; }
            try {
                $hosts = app(\App\Clients\FleetClient::class)->hostsForTenant($fleetSecret);
            } catch (\Throwable $e) {
                $skipped++;
                continue;
            }
            foreach ($hosts as $h) {
                $hostId = (int) ($h['id'] ?? 0);
                if (!$hostId) continue;
                $platform = \App\Support\PolicyRunner::normalizePlatform($h['platform'] ?? '');
                $trigger = $platform === 'windows' ? $triggerWindows : $triggerLinux;
                $result = FleetScriptRunner::launch($hostId, $trigger, 'system', $tenant->id);
                if ($result['ok']) {
                    $launched++;
                } elseif ($result['error'] === 'host_busy') {
                    $busy++;
                } else {
                    $failed++;
                    $this->warn("  {$h['hostname']}: launch failed -- {$result['error']}");
                }
            }
        }
        $this->info("Threat-intel refresh triggered. Launched: {$launched}  Busy (retry next cycle): {$busy}  Failed: {$failed}  Skipped (no Fleet secret): {$skipped}");
        return self::SUCCESS;
    }
}
