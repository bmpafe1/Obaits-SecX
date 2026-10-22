<?php
namespace App\Console\Commands;

use App\Clients\WazuhClient;
use App\Support\PackageMirror;
use Illuminate\Console\Command;

class SyncPackageMirror extends Command
{
    protected $signature = 'obaits:sync-package-mirror';
    protected $description = 'Proactively fetch and cache the real, current version of every self-hosted Windows install-time dependency (restic, Tailscale, Wazuh agent, Zabbix agent), so real enrollments serve from our own mirror instead of hitting an external URL live';

    public function handle(WazuhClient $wazuh): int
    {
        $wazuhVersion = null;
        try {
            // REAL BUG FOUND before this ever reached a real device: the
            // real Windows MSI URL genuinely requires a "-1" package-
            // revision suffix (confirmed directly: the bare version
            // alone returns 403) -- Enroll.php's own real, already-
            // working code already knew this (it appends "-1" itself
            // when building the install command); this new command had
            // simply never matched that same real convention yet.
            $rawVersion = $wazuh->managerVersion();
            $wazuhVersion = $rawVersion ? "{$rawVersion}-1" : null;
        } catch (\Throwable $e) {
            $this->warn('Could not resolve the real, current Wazuh manager version: ' . $e->getMessage());
        }

        foreach (PackageMirror::TOOLS as $tool) {
            $isWazuh = in_array($tool, ['wazuh-agent', 'wazuh-agent-linux'], true);
            $version = $isWazuh ? $wazuhVersion : null;
            if ($isWazuh && !$version) {
                $this->warn("Skipping {$tool}: no real manager version available.");
                continue;
            }

            // Real, honest "already have the current one" check, not a
            // blind re-download every run.
            $existing = PackageMirror::cachedPath($tool, $version);
            $alwaysRefetch = in_array($tool, ['zabbix-agent', 'zabbix-agent-linux'], true);
            if ($existing && !$alwaysRefetch) {
                // zabbix-agent's own "latest" alias is a genuinely moving
                // target with no real version string of its own to check
                // against -- always re-fetch it, matching how the CDN
                // itself works. Every other tool's cache key already
                // encodes its own real version, so an existing file
                // genuinely means "already current."
                $this->info("{$tool}: already cached, real version confirmed current.");
                continue;
            }

            $this->info("{$tool}: fetching real, current version...");
            $path = PackageMirror::fetchAndCache($tool, $version);
            if ($path) {
                $this->info("{$tool}: cached at {$path}");
            } else {
                $this->error("{$tool}: real fetch failed -- will retry next run, and the serving route itself will still fall back to a live fetch on request.");
            }
        }

        return 0;
    }
}
