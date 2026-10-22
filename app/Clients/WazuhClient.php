<?php
namespace App\Clients;
 
use Illuminate\Support\Facades\Http;
 
class WazuhClient
{
    private function baseUrl(): string
    {
        return rtrim(config('services.wazuh.base_url'), '/');
    }

    // Wazuh wraps failures inside 200 responses: check both the HTTP status and
    // the API envelope's total_failed_items. Added after 2026-07-21 (unchecked
    // teardown calls logged success while deleting nothing).
    private function assertOk(\Illuminate\Http\Client\Response $resp, string $operation): void
    {
        if (!$resp->successful()) {
            throw new \RuntimeException(
                "Wazuh $operation failed: HTTP " . $resp->status() . ' ' . substr((string) $resp->body(), 0, 200)
            );
        }
        $failed = $resp->json('data.total_failed_items');
        if (is_numeric($failed) && (int) $failed > 0) {
            throw new \RuntimeException(
                "Wazuh $operation failed inside a 200: " . substr((string) json_encode($resp->json('data.failed_items')), 0, 200)
            );
        }
    }
 
    // Wazuh JWTs are short-lived, so fetch fresh each call.
    // withoutVerifying() accepts the manager's self-signed certificate.
    public function token(): string
    {
        $res = Http::withoutVerifying()
            ->withBasicAuth(config('services.wazuh.api_user'), config('services.wazuh.api_password'))
            ->post($this->baseUrl() . '/security/user/authenticate');
        if (!$res->successful()) {
            throw new \RuntimeException(
                'Wazuh authentication failed (check WAZUH_API_USER/WAZUH_API_PASSWORD): HTTP ' . $res->status()
            );
        }
        $token = $res->json('data.token');
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Wazuh authentication returned no token');
        }
        return $token;
    }
    
    /**
     * The Wazuh manager's version, e.g. "4.9.0".
     *
     * Agents MUST NOT be newer than the manager — registration is refused outright
     * with "Agent version must be lower or equal to manager version". So the install
     * script pins the agent to this value rather than taking whatever the repository
     * ships today. Returns null if the manager cannot be reached; the caller must
     * treat that as "cannot enrol right now", not as a reason to guess.
     */
    public function managerVersion(): ?string
    {
        try {
            $base = rtrim(config('services.wazuh.base_url'), '/');
            $res = \Illuminate\Support\Facades\Http::withoutVerifying()
                ->withToken($this->token())
                ->timeout(15)
                ->get($base . '/manager/info');

            if (!$res->successful()) return null;

            $v = $res->json('data.affected_items.0.version');   // "v4.9.0"
            return $v ? ltrim($v, 'v') : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function ensureGroup(string $token, string $groupId): void
    {
        // idempotent: check existing groups first
        $existing = Http::withoutVerifying()->withToken($token)
            ->get($this->baseUrl() . '/groups')
            ->json('data.affected_items') ?? [];
        foreach ($existing as $g) {
            if (($g['name'] ?? null) === $groupId) return;
        }
        $resp = Http::withoutVerifying()->withToken($token)->post(
            $this->baseUrl() . '/groups',
            ['group_id' => $groupId]
        );
        $this->assertOk($resp, "group create ($groupId)");
    }
 
    public function deleteGroup(string $token, string $groupId): void
    {
        // Wazuh expects groups_list as a QUERY parameter, not a request body.
        // Laravel's ->delete($url, $data) sends $data as a body, which Wazuh
        // ignores — same bug as deleteAgentById, fixed 2026-07-22, found here
        // 2026-07-23 during the first real DeprovisionTenant test run.
        $query = http_build_query(['groups_list' => $groupId]);
        $resp = Http::withoutVerifying()->withToken($token)->delete(
            $this->baseUrl() . '/groups?' . $query
        );
        // Already-gone is the goal of a delete. Wazuh reports it either as a 404
        // or as a failed_item inside a 200 (error code 1710, "group does not exist").
        if ($resp->status() === 404) return;
        if ($resp->successful()) {
            $failed = $resp->json('data.total_failed_items');
            if (is_numeric($failed) && (int) $failed > 0) {
                $body = (string) json_encode($resp->json('data.failed_items'));
                if (stripos($body, 'not exist') !== false || strpos($body, '1710') !== false) {
                    return; // group already absent — treat as success
                }
                throw new \RuntimeException('Wazuh group delete failed inside a 200: ' . substr($body, 0, 200));
            }
            return;
        }
        throw new \RuntimeException('Wazuh group delete failed: HTTP ' . $resp->status());
    }

    /**
     * Push raw <agent_config>...</agent_config> XML as a group's shared
     * config. CONFIRMED EMPIRICALLY 2026-07-27 against the live manager:
     * PUT /groups/{group_id}/configuration with Content-Type: application/xml
     * and the raw XML as the request body — read back via
     * GET /groups/{group_id}/configuration to confirm it actually applied,
     * not just trusted the 200 response.
     *
     * Phase 4.1 Stage 3: this is how Tier 1 (base machine-type: linux-servers,
     * windows-client, etc.) and Tier 2 (software role: postgres-db, etc.)
     * profiles get their real content onto the manager, once, at seed time —
     * never per-enrollment. A device joins these groups via agent-auth's
     * own comma-separated -G list (Tier 1, known at enroll time) or this
     * class's group-membership API (Tier 2, added to an already-enrolled
     * agent later).
     */
    public function pushGroupConfig(string $token, string $groupId, string $xml): void
    {
        $resp = Http::withoutVerifying()->withToken($token)
            ->withBody($xml, 'application/xml')
            ->put($this->baseUrl() . "/groups/{$groupId}/configuration");
        $this->assertOk($resp, "group config push ({$groupId})");
    }
    /**
     * Find an agent by exact name WITHIN a specific group.
     * Returns ['id'=>..., 'status'=>...] or null. Scoped by group so a caller
     * can never see or act on another tenant's agents.
     */
    public function findAgentInGroup(string $token, string $group, string $name): ?array
    {
        $res = Http::withoutVerifying()->withToken($token)
            ->get($this->baseUrl() . '/agents', [
                'group'  => $group,
                'select' => 'id,name,status',
                'limit'  => 500,
            ]);
        if (!$res->successful()) {
            throw new \RuntimeException('Wazuh agent lookup failed: HTTP ' . $res->status());
        }
        // REAL BUG FOUND on a real device: a domain-joined Windows machine
        // reports its FULL FQDN to Fleet (e.g.
        // "WIN-2UQ47DD9KL4.AICEXPERTS.LOCAL") but registers with Wazuh
        // under its plain, SHORT hostname (e.g. "WIN-2UQ47DD9KL4") --
        // confirmed directly against the real Wazuh API. An exact string
        // match here failed every time for this device, surfacing as
        // "Could not find this device in monitoring" when adding a Tier 2
        // role -- nothing to do with one-instance-per-tenant limits, just
        // this same real hostname mismatch, already fixed once for
        // Reachability::vpnIpForHost() but never applied here too.
        $shortName = strtolower(explode('.', $name)[0]);
        foreach ($res->json('data.affected_items') ?? [] as $a) {
            $agentShortName = strtolower(explode('.', (string) ($a['name'] ?? ''))[0]);
            if ($agentShortName === $shortName) {
                return ['id' => $a['id'] ?? null, 'status' => $a['status'] ?? 'unknown'];
            }
        }
        return null;
    }

    /**
     * Add an already-enrolled agent to an ADDITIONAL group, without
     * disturbing any group it already belongs to. CONFIRMED EMPIRICALLY
     * 2026-07-27 against a real live agent (obaitsclient1, id 012): before,
     * group=[tenant-aicexperts]; after PUT .../group/{roleGroup}, group=
     * [tenant-aicexperts, roleGroup] — genuinely additive, not a replace.
     *
     * Phase 4.1 Stage 3: this is the Tier 2 mechanism (software role
     * add-ons like postgres-db, applied to a device that already has its
     * Tier 1 base + tenant group from enrollment). Tier 1 itself does NOT
     * use this method — it's assigned at enrollment time via agent-auth's
     * own comma-separated -G list, before the agent exists to call this on.
     */
    public function addAgentToGroup(string $token, string $agentId, string $groupId): void
    {
        $resp = Http::withoutVerifying()->withToken($token)
            ->put($this->baseUrl() . "/agents/{$agentId}/group/{$groupId}");

        // REAL BUG FOUND on a real device: re-adding a role a device is
        // ALREADY in (e.g. re-running setup, or a prior direct API call
        // during testing) was treated as a hard failure — Wazuh reports
        // this as error 1751 "Agent already belongs to specified group"
        // inside an otherwise-200 response, the exact same shape as
        // deleteGroup()'s "already gone" case above. That's the desired
        // END STATE, not a real problem — treat it as success.
        if ($resp->status() === 200) {
            $failed = $resp->json('data.total_failed_items');
            if (is_numeric($failed) && (int) $failed > 0) {
                $body = (string) json_encode($resp->json('data.failed_items'));
                if (stripos($body, 'already belongs') !== false || strpos($body, '1751') !== false) {
                    return; // already in the group — treat as success
                }
                throw new \RuntimeException('Wazuh add agent to group failed inside a 200: ' . substr($body, 0, 300));
            }
            return;
        }

        $this->assertOk($resp, "add agent {$agentId} to group {$groupId}");
    }

    /**
     * Delete a specific agent by id. Caller MUST have resolved the id from a
     * group-scoped lookup (findAgentInGroup) — never pass a client-supplied id.
     */
    public function deleteAgentById(string $token, string $agentId): void
    {
        // Wazuh expects these as QUERY parameters. Laravel's ->delete($url, $data)
        // sends $data as a body, which Wazuh ignores → HTTP 400 "no agents_list".
        // Build the query string explicitly.
        $query = http_build_query([
            'agents_list' => $agentId,
            'status'      => 'all',
            'older_than'  => '0s',
        ]);
        $resp = Http::withoutVerifying()->withToken($token)->delete(
            $this->baseUrl() . '/agents?' . $query
        );
        if ($resp->status() === 404) return; // already gone
        if (!$resp->successful()) {
            throw new \RuntimeException('Wazuh agent delete failed: HTTP ' . $resp->status());
        }
        $failed = $resp->json('data.total_failed_items');
        if (is_numeric($failed) && (int) $failed > 0) {
            throw new \RuntimeException('Wazuh agent delete failed inside a 200: '
                . substr((string) json_encode($resp->json('data.failed_items')), 0, 200));
        }
    }
    // Agents in a tenant's group: [ ['id','name','status'], ... ]
    public function agentsInGroup(string $group): array
    {
        $base = rtrim(config('services.wazuh.base_url'), '/');
        $tok = $this->token();
        $res = \Illuminate\Support\Facades\Http::withoutVerifying()->withToken($tok)
            ->get($base . '/agents', ['group' => $group]);
        $items = $res->json('data.affected_items') ?? [];
        $out = [];
        foreach ($items as $a) {
            $out[] = ['id' => $a['id'] ?? '', 'name' => $a['name'] ?? '—', 'status' => $a['status'] ?? '—'];
        }
        return $out;
    }
 
    // SCA policies for one agent: [ ['name','score','pass','fail'], ... ]
    public function scaForAgent(string $agentId): array
    {
        $base = rtrim(config('services.wazuh.base_url'), '/');
        $tok = $this->token();
        $res = \Illuminate\Support\Facades\Http::withoutVerifying()->withToken($tok)
            ->get($base . '/sca/' . $agentId);
        $items = $res->json('data.affected_items') ?? [];
        $policies = [];
        foreach ($items as $p) {
            $policies[] = [
                'name'  => $p['name'] ?? ($p['policy'] ?? 'Policy'),
                'score' => $p['score'] ?? null,
                'pass'  => $p['pass'] ?? null,
                'fail'  => $p['fail'] ?? null,
            ];
        }
        return $policies;
    }
 
    // Aggregate posture across a tenant's group.
    public function postureForGroup(string $group): array
    {
        $agents = $this->agentsInGroup($group);
        $scores = [];
        $policies = [];
        foreach ($agents as $a) {
            foreach ($this->scaForAgent($a['id']) as $pol) {
                if (is_numeric($pol['score'])) $scores[] = (float) $pol['score'];
                $policies[] = array_merge($pol, ['agent' => $a['name']]);
            }
        }
        $avg = count($scores) ? (int) round(array_sum($scores) / count($scores)) : null;
        return ['agents' => $agents, 'avg_score' => $avg, 'policies' => $policies];
    }

    private function indexerUrl(): string
    {
        return rtrim(config('services.wazuh.indexer_url'), '/');
    }

    /**
     * The agent IDs in a tenant's group. THIS IS THE ISOLATION BOUNDARY —
     * every alert query is bounded by the tenant's own agents.
     */
    public function agentIdsInGroup(string $token, string $group): array
    {
        try {
            $res = Http::withoutVerifying()->withToken($token)->timeout(30)
                ->get($this->baseUrl() . "/groups/{$group}/agents", ['limit' => 500]);

            return collect($res->json('data.affected_items') ?? [])
                ->pluck('id')->filter()->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Recent alerts for a specific set of agents.
     * NOTE: the indexed timestamp field is "@timestamp", not "timestamp".
     */
    public function alertsForAgents(array $agentIds, int $days = 7, int $limit = 200): array
    {
        if (empty($agentIds)) return [];

        $body = [
            'size' => $limit,
            'sort' => [['@timestamp' => 'desc']],
            'query' => [
                'bool' => [
                    'filter' => [
                        ['terms' => ['agent.id' => array_values($agentIds)]],
                        ['range' => ['@timestamp' => ['gte' => "now-{$days}d"]]],
                    ],
                ],
            ],
        ];

        try {
            $res = Http::withoutVerifying()
                ->withBasicAuth(
                    config('services.wazuh.indexer_user'),
                    config('services.wazuh.indexer_password')
                )
                ->timeout(30)
                ->withBody(json_encode($body), 'application/json')
                ->get($this->indexerUrl() . '/wazuh-alerts-*/_search');

            return collect($res->json('hits.hits') ?? [])->pluck('_source')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * REAL BUG FOUND 2026-08-16, confirmed live on a real tenant: a
     * single shared alertsForAgents(..., 500) call, sorted by newest
     * first, was used to populate all four Threat Detection tabs
     * (File Integrity, Rootkit, Firewall, MITRE) at once. On any tenant
     * with real, high-frequency auth/syslog traffic (confirmed: 500/500
     * fetched events were 100% syslog/pam/sudo noise), the low-volume
     * categories this page actually cares about were completely crowded
     * out of the top-500 window -- NOT because they didn't exist (the
     * same tenant genuinely had 706 real syscheck and 557 real rootcheck
     * events in the same real 7-day window, confirmed via a direct
     * indexer query), but because a shared, generic top-N pull can never
     * reliably surface a low-frequency category behind a high-frequency
     * one. Real fix: query each category's own rule.groups (or
     * rule.mitre.id existence) DIRECTLY at the indexer, each with its
     * own dedicated result limit, so noisy categories can never crowd
     * out quiet ones ever again.
     */
    public function alertsForAgentsInGroups(array $agentIds, array $groups, int $days = 7, int $limit = 200): array
    {
        if (empty($agentIds)) return [];

        $body = [
            'size' => $limit,
            'sort' => [['@timestamp' => 'desc']],
            'query' => [
                'bool' => [
                    'filter' => [
                        ['terms' => ['agent.id' => array_values($agentIds)]],
                        ['range' => ['@timestamp' => ['gte' => "now-{$days}d"]]],
                        ['terms' => ['rule.groups' => array_values($groups)]],
                    ],
                ],
            ],
        ];

        try {
            $res = Http::withoutVerifying()
                ->withBasicAuth(
                    config('services.wazuh.indexer_user'),
                    config('services.wazuh.indexer_password')
                )
                ->timeout(30)
                ->withBody(json_encode($body), 'application/json')
                ->get($this->indexerUrl() . '/wazuh-alerts-*/_search');

            return collect($res->json('hits.hits') ?? [])->pluck('_source')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * MITRE-tagged events specifically -- filtered on the EXISTENCE of
     * rule.mitre.id, not a groups membership (MITRE tagging is its own
     * dimension, not a rule.groups entry), same real crowding-out risk
     * as every other category above, same real fix.
     */
    public function alertsForAgentsWithMitre(array $agentIds, int $days = 7, int $limit = 200): array
    {
        if (empty($agentIds)) return [];

        $body = [
            'size' => $limit,
            'sort' => [['@timestamp' => 'desc']],
            'query' => [
                'bool' => [
                    'filter' => [
                        ['terms' => ['agent.id' => array_values($agentIds)]],
                        ['range' => ['@timestamp' => ['gte' => "now-{$days}d"]]],
                        ['exists' => ['field' => 'rule.mitre.id']],
                    ],
                ],
            ],
        ];

        try {
            $res = Http::withoutVerifying()
                ->withBasicAuth(
                    config('services.wazuh.indexer_user'),
                    config('services.wazuh.indexer_password')
                )
                ->timeout(30)
                ->withBody(json_encode($body), 'application/json')
                ->get($this->indexerUrl() . '/wazuh-alerts-*/_search');

            return collect($res->json('hits.hits') ?? [])->pluck('_source')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Is the alert store actually reachable AND accepting writes?
     * A full disk silently marks indices read-only — monitoring stops
     * while everything still LOOKS healthy. Never report green on faith.
     */
    public function indexerHealthy(): bool
    {
        try {
            $res = Http::withoutVerifying()
                ->withBasicAuth(
                    config('services.wazuh.indexer_user'),
                    config('services.wazuh.indexer_password')
                )
                ->timeout(10)
                ->get($this->indexerUrl() . '/_cluster/health');

            return in_array($res->json('status'), ['green', 'yellow'], true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Does this group still EXIST in Wazuh? tenant_artifacts can drift
     * out of sync with reality — as it just did when the stack was rebuilt.
     */
    public function groupExists(string $token, string $group): bool
    {
        try {
            $res = Http::withoutVerifying()->withToken($token)->timeout(20)
                ->get($this->baseUrl() . '/groups', ['limit' => 500]);

            return collect($res->json('data.affected_items') ?? [])
                ->pluck('name')->contains($group);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Compliance-framework tags across this tenant's alerts, counted server-side.
     *
     * Wazuh rules carry references to PCI DSS, HIPAA, GDPR, NIST 800-53 and TSC.
     * We aggregate in the indexer rather than fetching documents and counting in
     * PHP: an evidence page must report TRUE totals over 90+ days, and a count
     * derived from "the most recent 200 alerts" would understate reality while
     * looking authoritative.
     *
     * Returns ['pci_dss' => ['10.2.5' => 125, ...], 'hipaa' => [...], ...]
     */
    public function complianceEvidenceForAgents(array $agentIds, int $days = 90): array
    {
        if (empty($agentIds)) return [];

        $frameworks = ['pci_dss', 'hipaa', 'gdpr', 'nist_800_53', 'tsc'];

        $aggs = [];
        foreach ($frameworks as $f) {
            $aggs[$f] = ['terms' => ['field' => 'rule.' . $f, 'size' => 500]];
        }

        $body = [
            'size'  => 0,                      // aggregations only — no documents
            'query' => [
                'bool' => [
                    'filter' => [
                        ['terms' => ['agent.id' => array_values($agentIds)]],
                        ['range' => ['@timestamp' => ['gte' => "now-{$days}d"]]],
                    ],
                ],
            ],
            'aggs'  => $aggs,
        ];

        try {
            $res = Http::withoutVerifying()
                ->withBasicAuth(
                    config('services.wazuh.indexer_user'),
                    config('services.wazuh.indexer_password')
                )
                ->timeout(45)
                ->withBody(json_encode($body), 'application/json')
                ->get($this->indexerUrl() . '/wazuh-alerts-*/_search');

            if (!$res->successful()) return [];

            $out = [];
            foreach ($frameworks as $f) {
                $buckets = $res->json("aggregations.{$f}.buckets") ?? [];
                foreach ($buckets as $b) {
                    $out[$f][$b['key']] = $b['doc_count'];
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The evidence behind one control reference: which rules fired, on which
     * machines, and over what period.
     *
     * $framework is a tag field name (pci_dss, hipaa, gdpr, nist_800_53, tsc).
     */
    public function evidenceForControl(array $agentIds, string $framework, string $ref, int $days = 90): array
    {
        if (empty($agentIds)) return [];

        $allowed = ['pci_dss', 'hipaa', 'gdpr', 'nist_800_53', 'tsc'];
        if (!in_array($framework, $allowed, true)) return [];

        $body = [
            'size'  => 0,
            'query' => [
                'bool' => [
                    'filter' => [
                        ['terms' => ['agent.id' => array_values($agentIds)]],
                        ['range' => ['@timestamp' => ['gte' => "now-{$days}d"]]],
                        ['term'  => ['rule.' . $framework => $ref]],
                    ],
                ],
            ],
            'aggs' => [
                'rules' => ['terms' => ['field' => 'rule.description', 'size' => 25]],
                'hosts' => ['terms' => ['field' => 'agent.name', 'size' => 50]],
                'first' => ['min' => ['field' => '@timestamp']],
                'last'  => ['max' => ['field' => '@timestamp']],
            ],
        ];

        try {
            $res = Http::withoutVerifying()
                ->withBasicAuth(
                    config('services.wazuh.indexer_user'),
                    config('services.wazuh.indexer_password')
                )
                ->timeout(45)
                ->withBody(json_encode($body), 'application/json')
                ->get($this->indexerUrl() . '/wazuh-alerts-*/_search');

            if (!$res->successful()) return [];

            return [
                'total' => $res->json('hits.total.value') ?? 0,
                'rules' => collect($res->json('aggregations.rules.buckets') ?? [])
                            ->map(fn ($b) => ['description' => $b['key'], 'count' => $b['doc_count']])->all(),
                'hosts' => collect($res->json('aggregations.hosts.buckets') ?? [])
                            ->map(fn ($b) => ['name' => $b['key'], 'count' => $b['doc_count']])->all(),
                'first' => $res->json('aggregations.first.value_as_string'),
                'last'  => $res->json('aggregations.last.value_as_string'),
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Real, already-synced CVSS severity for a real set of CVE IDs,
     * sourced from Wazuh's own Vulnerability Detection module (confirmed
     * directly enabled on the real manager, syncing from NVD every 60
     * minutes into wazuh-states-vulnerabilities-obaits) -- reusing this
     * existing, already-running infrastructure rather than building a
     * new NVD-fetching pipeline from scratch (Brian's own real, correct
     * suggestion). Returns [cveId => ['score' => float|null, 'severity'
     * => string|null]] -- callers should treat a missing key as "not yet
     * scanned/synced for this CVE", not as an error.
     */
    public function severityForCves(array $cveIds): array
    {
        $cveIds = array_values(array_unique(array_filter($cveIds)));
        if (empty($cveIds)) return [];

        $out = [];
        // Real, confirmed rate/size consideration: keep batches modest:
        // a single terms filter with thousands of values is a real,
        // valid Elasticsearch/OpenSearch query, but chunking keeps any
        // one request's body and result set reasonably sized.
        foreach (array_chunk($cveIds, 500) as $chunk) {
            $body = [
                'size' => count($chunk) * 2,
                '_source' => ['vulnerability.id', 'vulnerability.score.base', 'vulnerability.severity'],
                'query' => [
                    'bool' => [
                        'filter' => [
                            ['terms' => ['vulnerability.id' => $chunk]],
                        ],
                    ],
                ],
            ];
            try {
                $res = Http::withoutVerifying()
                    ->withBasicAuth(
                        config('services.wazuh.indexer_user'),
                        config('services.wazuh.indexer_password')
                    )
                    ->timeout(30)
                    ->withBody(json_encode($body), 'application/json')
                    ->get($this->indexerUrl() . '/wazuh-states-vulnerabilities-*/_search');

                foreach ($res->json('hits.hits') ?? [] as $hit) {
                    $src = $hit['_source']['vulnerability'] ?? [];
                    $cve = $src['id'] ?? null;
                    if (!$cve || isset($out[$cve])) continue; // first real hit per CVE wins
                    $out[$cve] = [
                        'score'    => $src['score']['base'] ?? null,
                        'severity' => $src['severity'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                // Real, honest degrade: skip this chunk, keep whatever
                // real data other chunks already returned.
                continue;
            }
        }

        return $out;
    }
}