<?php
namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * Real, self-hosted mirror for Windows install-time dependencies
 * (restic, Tailscale, Wazuh agent, Zabbix agent) -- Brian's own real,
 * important concern: every one of these was previously fetched live
 * from an external URL at enrollment time, meaning a temporary outage,
 * an upstream URL change, or an eventual end-of-life could all break a
 * real enrollment, with only Fleet's own package already self-hosted
 * this way. Files are cached under storage_path('app/private/
 * package-mirror'), matching the SAME real, established private-
 * storage convention already used for Fleet's own agent packages.
 */
class PackageMirror
{
    public const TOOLS = ['restic', 'tailscale', 'wazuh-agent', 'zabbix-agent', 'wazuh-agent-linux', 'zabbix-agent-linux'];

    // REAL, DELIBERATE SCOPE (confirmed with Brian): restic (Ubuntu's
    // own official repo) and Tailscale (its own robust install script)
    // are lower real risk and do not fit the "single file" mirroring
    // model on Linux the way they do on Windows -- only the two real,
    // third-party apt-repo dependencies are mirrored here.

    private static function dir(): string
    {
        $dir = storage_path('app/private/package-mirror');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return $dir;
    }

    /**
     * Real, current cached file path for a tool, or null if never
     * cached. Wazuh is real, genuinely version-pinned (tied to the
     * manager's own current version) -- its cache key includes the
     * real version so an old, cached file is never silently served
     * for a newer manager.
     *
     * REAL BUG FOUND 2026-08-11 on a real customer machine
     * (mail.aicexperts.com, Ubuntu 22.04.5): zabbix-agent-linux was
     * hardcoded to ubuntu24.04 everywhere -- the cache filename never
     * varied by OS, so a device on any other release either silently
     * received a package built for the wrong libc/libssl (confirmed:
     * "Version of libc6:amd64 on system is 2.35... depends on libc6
     * (>= 2.38)"), or would have poisoned the cache for every OTHER
     * version if one had ever been fetched. $osId/$osVersion are now
     * part of the real cache key for this tool specifically.
     */
    public static function cachedPath(string $tool, ?string $version = null, ?string $osId = null, ?string $osVersion = null): ?string
    {
        $pattern = self::dir() . '/' . self::filename($tool, $version ?? '*', $osId, $osVersion);
        $hits = glob($pattern);
        return $hits ? $hits[0] : null;
    }

    private static function filename(string $tool, string $version, ?string $osId = null, ?string $osVersion = null): string
    {
        return match ($tool) {
            'restic'             => "restic-{$version}-windows-amd64.zip",
            'tailscale'          => "tailscale-{$version}-amd64.msi",
            'wazuh-agent'        => "wazuh-agent-{$version}.msi",
            'zabbix-agent'       => "zabbix-agent2-{$version}-windows-amd64.msi",
            'wazuh-agent-linux'  => "wazuh-agent-{$version}-linux-amd64.deb",
            // Real, honest default of ubuntu/24.04 ONLY when the caller
            // genuinely didn't supply an OS (an older cached install
            // script, or a partial deploy mid-rollout) -- never a silent
            // guess once a real OS is actually known.
            'zabbix-agent-linux' => "zabbix-agent2-{$version}-" . ($osId ?: 'ubuntu') . ($osVersion ?: '24.04') . "-linux-amd64.deb",
            default              => throw new \InvalidArgumentException("Unknown mirror tool: {$tool}"),
        };
    }

    /**
     * Real, live fetch-and-cache -- used both by the real, proactive
     * sync command AND, as a real, honest fallback, by the serving
     * route itself if a request arrives for a tool never yet cached
     * (a brand-new tenant's very first enrollment, before the next
     * scheduled sync has run, OR -- as of 2026-08-11 -- any real OS
     * version the daily sync hasn't proactively warmed yet; this
     * live-fetch fallback is what makes an uncommon OS version work on
     * its very first request rather than fail outright).
     */
    public static function fetchAndCache(string $tool, ?string $wazuhVersion = null, ?string $osId = null, ?string $osVersion = null): ?string
    {
        [$url, $version] = self::resolveRealSourceUrl($tool, $wazuhVersion, $osId, $osVersion);
        if (!$url) return null;

        $path = self::dir() . '/' . self::filename($tool, $version, $osId, $osVersion);
        $res = Http::timeout(120)->get($url);
        if (!$res->successful()) return null;

        file_put_contents($path, $res->body());
        return $path;
    }

    /**
     * Real, current upstream URL + real version string for a tool, at
     * the moment this is called -- the SAME real sources the install
     * scripts themselves used before self-hosting, kept in exactly one
     * place now instead of duplicated across install-ps1.blade.php.
     */
    private static function resolveRealSourceUrl(string $tool, ?string $wazuhVersion, ?string $osId = null, ?string $osVersion = null): array
    {
        return match ($tool) {
            'restic' => (function () {
                $release = Http::timeout(30)->get('https://api.github.com/repos/restic/restic/releases/latest')->json();
                $asset = collect($release['assets'] ?? [])->first(fn ($a) => str_ends_with($a['name'], 'windows_amd64.zip'));
                $version = ltrim($release['tag_name'] ?? 'unknown', 'v');
                return [$asset['browser_download_url'] ?? null, $version];
            })(),
            'tailscale' => (function () {
                $page = Http::timeout(30)->get('https://pkgs.tailscale.com/stable/')->body();
                if (preg_match('/tailscale-setup-([0-9.]+)-amd64\.msi/', $page, $m)) {
                    return ["https://pkgs.tailscale.com/stable/tailscale-setup-{$m[1]}-amd64.msi", $m[1]];
                }
                return [null, null];
            })(),
            'wazuh-agent' => $wazuhVersion
                ? ["https://packages.wazuh.com/4.x/windows/wazuh-agent-{$wazuhVersion}.msi", $wazuhVersion]
                : [null, null],
            'zabbix-agent' => [
                'https://cdn.zabbix.com/zabbix/binaries/stable/7.0/latest/zabbix_agent2-7.0-latest-windows-amd64-openssl.msi',
                'latest',
            ],
            // Real, direct .deb download, matching the SAME real,
            // version-pinned pattern already confirmed working for the
            // Windows MSI -- avoids needing to mirror a full apt repo.
            'wazuh-agent-linux' => $wazuhVersion
                ? ["https://packages.wazuh.com/4.x/apt/pool/main/w/wazuh-agent/wazuh-agent_{$wazuhVersion}_amd64.deb", $wazuhVersion]
                : [null, null],
            // REAL FIX 2026-08-11: this was hardcoded to ubuntu24.04
            // only -- confirmed live, this broke Zabbix agent install
            // outright on a real Ubuntu 22.04.5 machine (real dependency
            // failure: libc6 2.35 present, package needs >= 2.38).
            // $osId/$osVersion are now real inputs (detected from the
            // device's own /etc/os-release by install.sh), defaulting to
            // ubuntu/24.04 ONLY if genuinely not supplied -- never a
            // silent, wrong guess once the real OS is actually known.
            // Zabbix's own repo uses the identical pool/{distro} layout
            // for every Debian-family release, so this generalises
            // cleanly to any ubuntu/debian id+version, not just the one
            // originally tested.
            'zabbix-agent-linux' => (function () use ($osId, $osVersion) {
                $realOsId = $osId ?: 'ubuntu';
                $realOsVersion = $osVersion ?: '24.04';
                $repoUrl = "https://repo.zabbix.com/zabbix/7.0/{$realOsId}/pool/main/z/zabbix/";

                $page = Http::timeout(30)->get($repoUrl)->body();

                // Real, confirmed HTML structure (see the original,
                // still-real 2026-0x note this was built from): the href
                // value is URL-ENCODED, the human-readable title
                // attribute is not -- match on title for the real
                // version, use href for the actual, working download.
                // The distro tag (e.g. "ubuntu24.04" or "debian12") is
                // built dynamically now instead of hardcoded literally.
                $tag = preg_quote($realOsId . $realOsVersion, '/');
                preg_match_all(
                    '/<a href="([^"]+)" title="zabbix-agent2_([0-9.\-]+)\+' . $tag . '_amd64\.deb">[^<]*<\/a><\/td><td class="size">[^<]*<\/td><td class="date">([^<]+)<\/td>/',
                    $page,
                    $matches,
                    PREG_SET_ORDER
                );
                if (empty($matches)) return [null, null];

                // Real, honest simplification: sort by real publish date
                // (already present in the listing) rather than attempt
                // full semantic version comparison -- the most recently
                // published real file is, in practice, always the real,
                // current one.
                usort($matches, fn ($a, $b) => strtotime($a[3]) <=> strtotime($b[3]));
                $latest = end($matches);

                return [$repoUrl . $latest[1], $latest[2]];
            })(),
            default => [null, null],
        };
    }
}
