<?php

namespace App\Support\Backup;

use App\Clients\FleetClient;
use Illuminate\Support\Facades\DB;

/**
 * Folder browsing for backup selection, via Fleet's osquery live query.
 *
 * ISOLATION: FleetClient::runLiveQuery() does NO tenant scoping — it runs
 * against whatever host IDs it is handed. Every entry point here therefore
 * resolves the tenant's own hosts first and refuses any host id outside
 * that set. Do not call runLiveQuery() for backup features directly.
 *
 * osquery's file table does not recurse: a constraint on `directory` returns
 * that directory's immediate children only. Browsing is therefore one level
 * per call, driving a click-to-expand tree rather than a full crawl. This is
 * deliberate — a full crawl of a real machine would be enormous and slow.
 */
class FleetFolderBrowser
{
    /** Live queries need the agent online; Fleet's own timeout is 35s. */
    public const UNREACHABLE = 'unreachable';

    /**
     * The tenant's enrolled hosts, normalised to what the picker needs.
     * Returns [ ['id' => int, 'hostname' => string, 'platform' => string,
     *            'online' => bool], ... ]
     */
    public static function hosts(string $tenantId): array
    {
        $secret = self::enrollSecret($tenantId);
        if (!$secret) {
            return [];
        }

        $hosts = app(FleetClient::class)->hostsForTenant($secret);

        $out = [];
        foreach ($hosts as $h) {
            if (empty($h['id'])) {
                continue;
            }
            $out[] = [
                'id'       => (int) $h['id'],
                'hostname' => $h['hostname'] ?? ($h['display_name'] ?? ('host ' . $h['id'])),
                'platform' => strtolower($h['platform'] ?? ''),
                'online'   => ($h['status'] ?? '') === 'online',
            ];
        }

        return $out;
    }

    /**
     * Sensible starting directories for a host, by platform. There is no
     * universal root: Windows has drive letters, Unix has one tree.
     */
    public static function rootsFor(string $platform): array
    {
        $platform = strtolower($platform);

        if (str_contains($platform, 'windows')) {
            return ['C:\\Users', 'C:\\ProgramData'];
        }

        if (str_contains($platform, 'darwin') || str_contains($platform, 'macos')) {
            return ['/Users', '/opt', '/srv'];
        }

        // Linux and anything else Unix-like.
        return ['/home', '/root', '/srv', '/opt', '/var'];
    }

    /**
     * Immediate subdirectories of $path on one of the tenant's own hosts.
     *
     * Returns ['ok' => bool, 'folders' => [ ['path','name'], ... ],
     *          'error' => ?string]
     */
    public static function listFolders(string $tenantId, int $fleetHostId, string $path, ?string $actor = null): array
    {
        // --- ISOLATION: the host must belong to this tenant. ---
        $hosts = self::hosts($tenantId);
        $match = null;
        foreach ($hosts as $h) {
            if ($h['id'] === $fleetHostId) {
                $match = $h;
                break;
            }
        }

        if (!$match) {
            // Deliberately not "host not found" — do not confirm or deny the
            // existence of a host id belonging to another tenant.
            return ['ok' => false, 'folders' => [], 'error' => 'not_your_host'];
        }

        $clean = self::sanitisePath($path);
        if ($clean === null) {
            return ['ok' => false, 'folders' => [], 'error' => 'invalid_path'];
        }

        $sql = "SELECT path, filename FROM file WHERE directory = '{$clean}' AND type = 'directory';";

        \App\Support\Audit::log(
            $tenantId,
            $actor,
            'backup.folder_browse',
            'list',
            'fleet',
            ['host_id' => $fleetHostId, 'host' => $match['hostname'], 'path' => $clean]
        );

        $r = app(FleetClient::class)->runLiveQuery([$fleetHostId], $sql);

        if (!$r['ok']) {
            return ['ok' => false, 'folders' => [], 'error' => $r['error'] ?? self::UNREACHABLE];
        }

        $folders = [];
        foreach ($r['rows'] as $hostResult) {
            foreach (($hostResult['columns'] ?? []) as $row) {
                $p = $row['path'] ?? null;
                if (!$p) {
                    continue;
                }
                $name = $row['filename'] ?? basename($p);
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $folders[$p] = ['path' => $p, 'name' => $name];
            }
        }

        $folders = array_values($folders);
        usort($folders, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        // An online agent with an unreadable or non-existent path returns zero
        // rows, which is indistinguishable from an empty directory. The caller
        // should say "no subfolders found" rather than asserting it is empty.
        return ['ok' => true, 'folders' => $folders, 'error' => null];
    }

    /**
     * Reject anything that could break out of the quoted SQL literal or chain
     * a second statement. osquery is read-only and LiveQuery.php already
     * permits arbitrary SELECTs against a tenant's own hosts, so this is
     * defence in depth rather than the only barrier — but a path arriving
     * from a URL or form must never be interpolated unchecked.
     *
     * Returns the cleaned path, or null if it must be refused.
     */
    private static function sanitisePath(string $path): ?string
    {
        $path = trim($path);

        if ($path === '' || strlen($path) > 512) {
            return null;
        }

        // No quotes, semicolons, backticks, comment markers or control chars.
        if (preg_match('/[\'"`;]|--|\x00|[\x01-\x1F]/', $path)) {
            return null;
        }

        // Must look like an absolute path: unix /... or windows X:\...
        if (!preg_match('#^(/|[A-Za-z]:\\\\)#', $path)) {
            return null;
        }

        // No traversal segments — callers should pass resolved paths.
        if (str_contains($path, '..')) {
            return null;
        }

        // Trailing separator would make osquery's directory match miss.
        if (strlen($path) > 3) {
            $path = rtrim($path, '/\\');
        }

        return $path;
    }

    private static function enrollSecret(string $tenantId): ?string
    {
        return DB::table('tenant_artifacts')
            ->where('tenant_id', $tenantId)          // ISOLATION
            ->where('tool', 'fleet')
            ->where('artifact_type', 'enroll_secret')
            ->value('external_id');
    }
}
