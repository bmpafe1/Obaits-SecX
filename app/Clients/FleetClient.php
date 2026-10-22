<?php
namespace App\Clients;

use Illuminate\Support\Facades\Http;

class FleetClient
{
    private function baseUrl(): string
    {
        return rtrim(config('services.fleet.base_url'), '/');
    }

    private function token(): string
    {
        return config('services.fleet.api_token');
    }

    // Read the current global enroll-secret list.
    public function getEnrollSecrets(): array
    {
        $res = Http::withToken($this->token())
            ->withoutVerifying()
            ->get($this->baseUrl() . '/api/v1/fleet/spec/enroll_secret');
        return $res->json('spec.secrets') ?? [];
    }

    /**
     * Does this enroll secret still exist in Fleet?
     *
     * true  = Fleet answered, the secret is there
     * false = Fleet answered, the secret is GONE (drift)
     * null  = Fleet did not answer — we do NOT know
     *
     * We deliberately do NOT reuse getEnrollSecrets() here. That method returns []
     * on failure, which is indistinguishable from "Fleet has no secrets at all".
     * Collapsing those two cases would make a brief Fleet outage look like every
     * tenant losing their enrollment — the platform would cry wolf, and a platform
     * that cries wolf gets ignored precisely when the real drift happens.
     */
    public function enrollSecretExists(string $secret): ?bool
    {
        try {
            $res = Http::withToken($this->token())
                ->withoutVerifying()
                ->timeout(20)
                ->get($this->baseUrl() . '/api/v1/fleet/spec/enroll_secret');

            if (!$res->successful()) return null;      // could not tell

            // The 'spec' envelope is what proves Fleet answered properly. Fleet
            // returns {"spec":{"secrets":null}} when there are NO secrets — a
            // definite answer, not an unknown. Treating that null as "cannot tell"
            // made this method unable to detect the exact drift it exists to catch:
            // a secret recorded in our database that Fleet does not have.
            $spec = $res->json('spec');
            if (!is_array($spec) || !array_key_exists('secrets', $spec)) {
                return null;                            // unexpected shape — do not guess
            }

            $secrets = $spec['secrets'];
            if ($secrets === null) return false;        // Fleet answered: there are none
            if (!is_array($secrets)) return null;       // something else entirely

            return collect($secrets)->contains(fn ($s) => ($s['secret'] ?? null) === $secret);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // Add a tenant's secret without disturbing the others (read-modify-write).
    public function addEnrollSecret(string $secret): void
    {
        $current = $this->getEnrollSecrets();
        foreach ($current as $s) {
            if (($s['secret'] ?? null) === $secret) return;   // already present (idempotent)
        }
        $current[] = ['secret' => $secret];
        $resp = Http::withToken($this->token())->withoutVerifying()->post(
            $this->baseUrl() . '/api/v1/fleet/spec/enroll_secret',
            ['spec' => ['secrets' => $current]]
        );
        // Added 2026-07-22: this POST ran with an empty token on 21 Jul and the
        // "success" was hollow. Never let a spec write go unchecked again.
        if (!$resp->successful()) {
            throw new \RuntimeException(
                'Fleet enroll-secret add failed: HTTP ' . $resp->status() . ' ' . substr((string) $resp->body(), 0, 200)
            );
        }
    }

    // Remove a tenant's secret (used at deprovision / rollback).
    public function removeEnrollSecret(string $secret): void
    {
        $current = $this->getEnrollSecrets();
        $filtered = array_values(array_filter($current, fn ($s) => ($s['secret'] ?? null) !== $secret));
        $resp = Http::withToken($this->token())->withoutVerifying()->post(
            $this->baseUrl() . '/api/v1/fleet/spec/enroll_secret',
            ['spec' => ['secrets' => $filtered]]
        );
        if (!$resp->successful()) {
            throw new \RuntimeException(
                'Fleet enroll-secret remove failed: HTTP ' . $resp->status() . ' ' . substr((string) $resp->body(), 0, 200)
            );
        }
    }

    /**
     * Remove a single host from Fleet entirely (used for single-device
     * offboarding — NOT the same as removeEnrollSecret, which affects an
     * entire tenant's ability to enroll NEW devices).
     */
    public function deleteHost(int $hostId): void
    {
        $resp = Http::withToken($this->token())->withoutVerifying()
            ->delete($this->baseUrl() . "/api/v1/fleet/hosts/{$hostId}");
        // 404 = already gone, which is the goal of a delete.
        if ($resp->status() === 404) return;
        if (!$resp->successful()) {
            throw new \RuntimeException(
                'Fleet host delete failed: HTTP ' . $resp->status() . ' ' . substr((string) $resp->body(), 0, 200)
            );
        }
    }

    /**
     * Delete a Fleet label by id.
     *
     * KNOWN FLEET QUIRK (memory log, confirmed again 2026-07-23): DELETE at
     * /api/v1/fleet/labels/{id} silently does nothing and returns 200 — the
     * real path is /api/v1/fleet/labels/id/{id}. Using the wrong path is how
     * a tenant's label survived its own tenant's deprovision undetected.
     */
    public function deleteLabel(int $labelId): void
    {
        $resp = Http::withToken($this->token())->withoutVerifying()
            ->delete($this->baseUrl() . "/api/v1/fleet/labels/id/{$labelId}");
        if ($resp->status() === 404) return; // already gone
        if (!$resp->successful()) {
            throw new \RuntimeException(
                'Fleet label delete failed: HTTP ' . $resp->status() . ' ' . substr((string) $resp->body(), 0, 200)
            );
        }
        // Read-back: this project's hard-won rule is a 200 is not evidence,
        // and Fleet's label endpoints have lied about success before (PATCH
        // silently ignoring changes). Confirm the label is actually gone.
        $stillThere = Http::withToken($this->token())->withoutVerifying()
            ->get($this->baseUrl() . '/api/v1/fleet/labels')
            ->json('labels') ?? [];
        foreach ($stillThere as $l) {
            if ((int) ($l['id'] ?? 0) === $labelId) {
                throw new \RuntimeException("Fleet label $labelId still present after delete (HTTP 200 was not evidence)");
            }
        }
    }
    /**
     * Hosts belonging to this tenant — resolved via a per-tenant Fleet LABEL.
     *
     * Fleet Free does not return enroll_secret_name on hosts and has no Teams,
     * so tenant isolation is enforced by a label whose query matches a marker
     * file the install script drops on each machine: /etc/obaits/tenant-{uuid}.
     * The label is named tenant-{slug}; we derive the slug from the enroll secret
     * (format: tenant-{slug}-{random16}) so existing callers pass it unchanged.
     */
    public function hostsForTenant(string $tenantSecret): array
    {
        $slug = $this->slugFromSecret($tenantSecret);
        if (!$slug) return [];

        $labelId = $this->labelIdForSlug($slug);
        if (!$labelId) return [];   // no label yet = no attributed hosts

        $res = Http::withToken($this->token())
            ->withoutVerifying()
            ->get($this->baseUrl() . "/api/v1/fleet/labels/{$labelId}/hosts");

        return $res->json('hosts') ?? [];
    }

    // Extract the tenant slug from an enroll secret of the form tenant-{slug}-{random16}.
    private function slugFromSecret(string $secret): ?string
    {
        if (preg_match('/^tenant-(.+)-[A-Za-z0-9]{16}$/', $secret, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Find the Fleet label id for a tenant slug (label name = tenant-{slug}).
     *
     * Fleet's /labels/{name} lookup returns 405 on this server version, so we
     * list all labels and match the name ourselves. Slower but version-proof.
     */
    private function labelIdForSlug(string $slug): ?int
    {
        $res = Http::withToken($this->token())
            ->withoutVerifying()
            ->get($this->baseUrl() . '/api/v1/fleet/labels');
        if (!$res->successful()) return null;

        foreach ($res->json('labels') ?? [] as $l) {
            if (($l['name'] ?? null) === 'tenant-' . $slug) {
                return $l['id'] ?? null;
            }
        }
        return null;
    }

    /**
     * Create (idempotently) the per-tenant label. Called at provisioning.
     * The label's query matches the marker file the install script writes.
     */
    public function ensureTenantLabel(string $tenantId, string $slug): ?int
    {
        // Already exists?
        $existing = $this->labelIdForSlug($slug);
        if ($existing) return $existing;

        $res = Http::withToken($this->token())
            ->withoutVerifying()
            ->post($this->baseUrl() . '/api/v1/fleet/labels', [
                'name'        => 'tenant-' . $slug,
                'description' => 'Obaits tenant ' . $tenantId,
                'query'       => $this->tenantLabelQuery($tenantId),
            ]);

        return $res->successful() ? $res->json('label.id') : null;
    }

    // Aggregates installed software across this tenant's hosts.
    // Returns [ ['name','version','hosts_count'], ... ]. Reuses hostsForTenant scoping.
    public function softwareForTenant(string $tenantSecret): array
    {
        $token = $this->token();
        $hosts = $this->hostsForTenant($tenantSecret);
        $tally = [];

        foreach ($hosts as $h) {
            $hostId = $h['id'] ?? null;
            if (!$hostId) continue;

            $res = Http::withToken($token)
                ->withoutVerifying()
                ->get($this->baseUrl() . "/api/v1/fleet/hosts/{$hostId}/software");

            $software = $res->json('software') ?? [];

            // Fallback: some Fleet versions embed software under the host detail.
            if (empty($software)) {
                $detail = Http::withToken($token)->withoutVerifying()
                    ->get($this->baseUrl() . "/api/v1/fleet/hosts/{$hostId}")->json();
                $software = $detail['host']['software'] ?? [];
            }

            foreach ($software as $s) {
                $name = $s['name'] ?? '';
                $version = $s['version'] ?? '';
                if ($name === '') continue;

                $key = strtolower($name) . '|' . $version;
                if (!isset($tally[$key])) {
                    $tally[$key] = ['name' => $name, 'version' => $version, 'hosts_count' => 0];
                }
                $tally[$key]['hosts_count']++;
            }
        }

        return array_values($tally);
    }

    /**
     * Run a live osquery against a SPECIFIC set of host IDs and wait briefly for results.
     * The caller MUST pass only the tenant's own host IDs — this method never targets all hosts.
     *
     * Returns ['ok'=>bool, 'rows'=>[['host'=>..., 'columns'=>[...]], ...], 'error'=>?string].
     */
    public function runLiveQuery(array $hostIds, string $sql): array
    {
        $hostIds = array_values(array_filter(array_map('intval', $hostIds)));
        if (empty($hostIds)) {
            return ['ok' => false, 'rows' => [], 'error' => 'no_hosts'];
        }

        $token = $this->token();
        $base  = $this->baseUrl();
        $http  = fn () => Http::withToken($token)->withoutVerifying()->timeout(35)->asJson();

        // Fleet 4.88 requires a saved query id — it does not accept raw SQL
        // on /queries/run despite the request body needing 'query' at
        // creation time. Confirmed empirically 2026-07-25: raw `query` on
        // /queries/run returns "query_ids and host_ids are required".
        //
        // Fleet also requires host_ids to arrive alongside query_ids in a
        // JSON body even on a GET request — a bare query-string GET returns
        // "Expected JSON Body". Hence ->asJson()->send('GET', ...).
        $queryName = 'obaits-live-' . bin2hex(random_bytes(6));

        try {
            $created = $http()->post($base . '/api/v1/fleet/queries', [
                'name'  => $queryName,
                'query' => $sql,
            ]);

            if (!$created->successful()) {
                return ['ok' => false, 'rows' => [], 'error' => 'fleet_create_http_' . $created->status() . ': ' . substr($created->body(), 0, 300)];
            }

            $queryId = $created->json('query.id');
            if (!$queryId) {
                return ['ok' => false, 'rows' => [], 'error' => 'fleet_create_no_id'];
            }

            // Fleet's live-query wait window is ~25s. If the targeted host's
            // distributed_interval exceeds that, it can never respond in time
            // — this is a server-wide agent option (Settings > Organization
            // settings > Agent options), not something this call can control
            // per request.
            $res = $http()->send('GET', $base . '/api/v1/fleet/queries/run', [
                'json' => ['query_ids' => [$queryId], 'host_ids' => $hostIds],
            ]);

            if (!$res->successful()) {
                return ['ok' => false, 'rows' => [], 'error' => 'fleet_run_http_' . $res->status() . ': ' . substr($res->body(), 0, 300)];
            }

            $responded = $res->json('summary.responded_host_count') ?? 0;
            $results   = $res->json('live_query_results.0.results') ?? [];

            $out = [];
            foreach ($results as $hostResult) {
                $out[] = [
                    'host'    => 'host ' . ($hostResult['host_id'] ?? '?'),
                    'columns' => $hostResult['rows'] ?? [],
                    'error'   => $hostResult['error'] ?? null,
                ];
            }

            if ($responded === 0) {
                // Not a hard failure — the host may answer late relative to
                // this window. Distinguish from a transport failure so the
                // caller can say "no response yet" rather than "unreachable".
                return ['ok' => true, 'rows' => $out, 'error' => 'no_response'];
            }

            return ['ok' => true, 'rows' => $out, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'rows' => [], 'error' => 'unreachable'];
        } finally {
            // Best-effort cleanup — a leaked saved query is clutter, not a
            // security or cost problem, so failure here does not change the
            // return value above.
            if (!empty($queryId)) {
                try {
                    $http()->delete($base . '/api/v1/fleet/queries/id/' . $queryId);
                } catch (\Throwable $e) {
                    // ignored
                }
            }
        }
    }

    /**
     * Software across this tenant's hosts THAT HAS KNOWN VULNERABILITIES.
     *
     * Fleet's per-host /hosts/{id}/software returns a software-TITLE view with no
     * version or vulnerability fields. The CVE data lives on /software/versions,
     * which accepts a host_id filter — so we ask that endpoint once per host the
     * tenant owns. Isolation is unchanged: only hosts from hostsForTenant are read.
     *
     * Returns [ ['name','version','source','cves'=>[['cve','link'],...],
     *            'hosts'=>['brian-Laptop',...],'hosts_count','cve_count'], ... ]
     */
    public function vulnerableSoftwareForTenant(string $tenantSecret): array
    {
        // REAL, SERIOUS BUG FOUND 2026-08-07: this previously called
        // GET /api/v1/fleet/software/versions?host_id=X&vulnerable=true,
        // which does NOT actually filter by host_id on this Fleet
        // deployment -- confirmed directly, empirically, against two
        // different real hosts (host_id=1 and host_id=20) returning
        // byte-for-byte identical results, and against TWO DIFFERENT
        // TENANTS (AICEXPERTS, NOFIA-SA) both showing the exact same
        // "16566 total CVE instances" on their dashboards. Every tenant's
        // dashboard and /portal/vulnerable-software page had been
        // silently showing the same platform-wide, effectively unscoped
        // figure attributed as if it were specific to their own devices
        // -- not a genuine cross-tenant DATA leak (hostsForTenant() itself
        // remains correctly tenant-scoped, confirmed separately), but a
        // real accuracy failure serious enough to look identical to one.
        //
        // Real fix: GET /api/v1/fleet/hosts/{id}/software?vulnerable=true
        // -- confirmed directly to return genuinely different, correctly
        // host-specific results (three real hosts returned 38/7/31
        // distinct software items respectively). Its response shape
        // differs from the broken endpoint: vulnerabilities live under
        // installed_versions[].vulnerabilities as a FLAT ARRAY OF CVE ID
        // STRINGS, not an array of {cve, details_link} objects -- the
        // details_link is synthesised here instead, same as before.
        $token = $this->token();
        $hosts = $this->hostsForTenant($tenantSecret);   // ISOLATION: only this tenant's hosts
        $tally = [];
        foreach ($hosts as $h) {
            $hostId   = $h['id'] ?? null;
            $hostName = $h['hostname'] ?? ($h['display_name'] ?? ('host ' . $hostId));
            if (!$hostId) continue;
            $res = Http::withToken($token)->withoutVerifying()
                ->get($this->baseUrl() . '/api/v1/fleet/hosts/' . $hostId . '/software', [
                    'vulnerable' => 'true',
                ]);
            if (!$res->successful()) continue;
            foreach ($res->json('software') ?? [] as $s) {
                $name = $s['name'] ?? '';
                if ($name === '') continue;

                foreach (($s['installed_versions'] ?? []) as $iv) {
                    $vulns = $iv['vulnerabilities'] ?? null;
                    if (empty($vulns)) continue;

                    $version = $iv['version'] ?? '';
                    $key = strtolower($name) . '|' . $version;
                    if (!isset($tally[$key])) {
                        $tally[$key] = [
                            'name'    => $name,
                            'version' => $version,
                            'source'  => $s['source'] ?? '',
                            'cves'    => [],
                            'hosts'   => [],   // hostname => true, de-duped
                        ];
                    }

                    // Which machines run this vulnerable package -- now
                    // genuinely host-specific, since this loop only ever
                    // runs for the software actually installed on $hostId.
                    $tally[$key]['hosts'][$hostName] = true;

                    foreach ($vulns as $cve) {
                        if (!$cve) continue;
                        if (!isset($tally[$key]['cves'][$cve])) {
                            $tally[$key]['cves'][$cve] = [
                                'cve'  => $cve,
                                'link' => 'https://nvd.nist.gov/vuln/detail/' . $cve,
                            ];
                        }
                    }
                }
            }
        }
        $out = array_map(function ($row) {
            $row['cves']        = array_values($row['cves']);
            $row['cve_count']   = count($row['cves']);
            $row['hosts']       = array_keys($row['hosts']);
            $row['hosts_count'] = count($row['hosts']);
            return $row;
        }, array_values($tally));
        usort($out, fn ($a, $b) =>
            ($b['cve_count'] <=> $a['cve_count']) ?: ($b['hosts_count'] <=> $a['hosts_count']));
        return $out;
    }

    // The label query the CURRENT install scripts imply. One place, so provisioning
    // and the reconciler can never disagree about what "correct" means.
    public function tenantLabelQuery(string $tenantId): string
    {
        return "SELECT 1 FROM file WHERE path LIKE '/etc/obaits/tenant-{$tenantId}' "
             . "UNION SELECT 1 FROM file WHERE path LIKE "
             . "'C:\\ProgramData\\Obaits\\tenant-{$tenantId}';";
    }

    /**
     * present | missing | stale_query | unknown
     * unknown means Fleet did not answer — callers must NOT treat it as drift.
     */
    public function tenantLabelState(string $tenantId, string $slug): string
    {
        try {
            $res = Http::withToken($this->token())->withoutVerifying()->timeout(20)
                ->get($this->baseUrl() . '/api/v1/fleet/labels');
            if (!$res->successful()) return 'unknown';

            $labels = $res->json('labels');
            if (!is_array($labels)) return 'unknown';

            foreach ($labels as $l) {
                if (($l['name'] ?? null) === 'tenant-' . $slug) {
                    return trim((string) ($l['query'] ?? '')) === trim($this->tenantLabelQuery($tenantId))
                        ? 'present' : 'stale_query';
                }
            }
            return 'missing';
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }

    /**
     * Update the label's query.
     *
     * Fleet returns 200 for a query PATCH on a dynamic label and then IGNORES it —
     * the query is immutable once created. So we verify by reading back, and report
     * failure honestly rather than looping forever believing we fixed something.
     */
    public function updateTenantLabelQuery(string $tenantId, string $slug): bool
    {
        $id = $this->labelIdForSlug($slug);
        if (!$id) return false;

        Http::withToken($this->token())->withoutVerifying()
            ->patch($this->baseUrl() . '/api/v1/fleet/labels/' . $id, [
                'query' => $this->tenantLabelQuery($tenantId),
            ]);

        // Trust nothing. Read it back.
        return $this->tenantLabelState($tenantId, $slug) === 'present';
    }

    /**
     * Real, per-interface network addresses for every enrolled host,
     * via Fleet's SCHEDULED-query + query-report mechanism -- NOT
     * runLiveQuery() above, which is for ad-hoc, user-initiated,
     * best-effort queries (confirmed directly: its own live-query wait
     * window is genuinely unreliable, responded_host_count came back 0
     * even after waiting 26 real seconds for one specific host).
     *
     * REAL FINDING that motivated this entire mechanism: Fleet's own
     * cached host.public_ip field can be completely fabricated garbage
     * with ZERO correspondence to the real device -- confirmed directly
     * on a real device (mail.aicexperts.com): Fleet reported
     * public_ip=172.18.0.1, which is genuinely the Docker bridge
     * gateway of FLEET'S OWN CONTAINER on the VPS, not anything read
     * from the target device's real interfaces at all (primary_ip and
     * primary_mac were BOTH empty for this same host, meaning Fleet's
     * own local-interface collection never even populated).
     *
     * The real, correct mechanism: GET /api/v1/fleet/queries/{id}/report
     * returns ALREADY-CACHED, server-side stored results from a real,
     * SCHEDULED (not live) query -- confirmed directly: after setting a
     * real, non-zero interval and waiting for one real osquery config-
     * refresh cycle, this returned genuine, complete, per-interface data
     * for two different real devices, matching their own actual `ip a`
     * output exactly (including every real Docker bridge on a real
     * multi-NIC device) -- with ZERO live-query wait risk, since this
     * simply reads whatever Fleet already has cached.
     */
    public function ensureNetworkInterfaceQuery(): int
    {
        $token = $this->token();
        $base  = $this->baseUrl();
        $http  = fn () => Http::withToken($token)->withoutVerifying()->timeout(15);

        // Idempotent: find our own real, dedicated query by name first,
        // rather than create a new one every time this runs.
        $existing = $http()->get($base . '/api/v1/fleet/queries', ['query' => 'Obaits: real network interfaces']);
        foreach ($existing->json('queries') ?? [] as $q) {
            if (($q['name'] ?? null) === 'Obaits: real network interfaces') {
                return (int) $q['id'];
            }
        }

        $created = $http()->post($base . '/api/v1/fleet/queries', [
            'name'     => 'Obaits: real network interfaces',
            'query'    => 'SELECT interface, address FROM interface_addresses WHERE address NOT LIKE "127.%" AND address NOT LIKE "%:%";',
            'interval' => 3600,
            'logging'  => 'snapshot',
        ]);

        return (int) $created->json('query.id');
    }

    public function fetchNetworkInterfaceReport(int $queryId): array
    {
        $token = $this->token();
        $base  = $this->baseUrl();
        $res = Http::withToken($token)->withoutVerifying()->timeout(15)
            ->get($base . '/api/v1/fleet/queries/' . $queryId . '/report');

        if (!$res->successful()) {
            return [];
        }

        return $res->json('results') ?? [];
    }
}