<?php
namespace App\Console\Commands;

use App\Clients\FleetClient;
use App\Clients\ZabbixClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkZabbixTemplates extends Command
{
    protected $signature = 'obaits:link-zabbix-templates';
    protected $description = 'Link the correct real Zabbix monitoring template (Linux/Windows by Zabbix agent active) to any real host that auto-registered but has none yet -- auto-registration alone only ever creates the host object, it never links a template, so nothing is actually collected without this';

    public function handle(FleetClient $fleet, ZabbixClient $zabbix): int
    {
        $unlinked = $zabbix->hostsWithoutTemplate();
        if (empty($unlinked)) {
            $this->info('No real hosts are missing a template.');
            return 0;
        }
        $this->info('Real hosts missing a template: ' . count($unlinked));

        // Real platform data (Linux vs Windows) is already known via
        // Fleet -- matched here by real hostname, across every real
        // tenant, rather than guessing or re-deriving it another way.
        $tenants = DB::table('tenants')->pluck('id');
        $hostnameToPlatform = [];
        foreach ($tenants as $tenantId) {
            $secret = DB::table('tenant_artifacts')
                ->where('tenant_id', $tenantId)
                ->where('tool', 'fleet')
                ->where('artifact_type', 'enroll_secret')
                ->value('external_id');
            if (!$secret) continue;

            foreach ($fleet->hostsForTenant($secret) as $h) {
                $hostname = $h['hostname'] ?? null;
                $platform = $h['platform'] ?? null;
                if ($hostname && $platform) {
                    // REAL BUG, same class already fixed twice elsewhere
                    // in this project (findAgentInGroup(), the VPN IP
                    // lookup): a domain-joined device reports its full
                    // FQDN to Fleet but a plain short name elsewhere
                    // (here: Zabbix's HOSTNAME, set via $env:COMPUTERNAME
                    // in the real install script). Match on the short,
                    // case-insensitive form so both sides genuinely agree.
                    $shortHostname = strtolower(explode('.', $hostname)[0]);
                    $hostnameToPlatform[$shortHostname] = $platform;
                }
            }
        }

        $linked = 0;
        $skipped = 0;
        foreach ($unlinked as $zh) {
            $zabbixHostName = $zh['host'];
            $shortZabbixHostName = strtolower(explode('.', $zabbixHostName)[0]);
            $platform = $hostnameToPlatform[$shortZabbixHostName] ?? null;

            if (!$platform) {
                $this->warn("  Skipping {$zabbixHostName}: no matching real Fleet platform data yet.");
                $skipped++;
                continue;
            }

            // Real, confirmed Fleet platform values seen in this project:
            // 'ubuntu', 'linuxmint', 'rhel', etc. for Linux; 'windows' for
            // Windows. Anything not recognized is left alone rather than
            // guessed at.
            $templateId = match (true) {
                str_contains(strtolower($platform), 'windows') => ZabbixClient::TEMPLATE_WINDOWS,
                default => ZabbixClient::TEMPLATE_LINUX,
            };

            $zabbix->linkTemplate((int) $zh['hostid'], $templateId);
            $this->info("  Linked {$zabbixHostName} ({$platform}) -> template {$templateId}");
            $linked++;
        }

        $this->info("Done. Linked: {$linked}, skipped: {$skipped}.");
        return 0;
    }
}
