<?php

namespace App\Clients;

use Illuminate\Support\Facades\Http;

class ZabbixClient
{
    private function apiUrl(): string
    {
        return config('services.zabbix.api_url');
    }

    private function apiToken(): string
    {
        return config('services.zabbix.api_token');
    }

    /**
     * Real, generic JSON-RPC 2.0 call, matching Zabbix's own real
     * protocol. Uses a real, pre-created API token (Bearer auth) --
     * the modern, current, recommended Zabbix authentication method,
     * confirmed directly via Zabbix's own official documentation. No
     * login flow needed, unlike Wazuh's own token-exchange mechanism.
     */
    private function call(string $method, array $params = []): array
    {
        $res = Http::withToken($this->apiToken())
            ->withHeaders(['Content-Type' => 'application/json-rpc'])
            ->timeout(30)
            ->post($this->apiUrl(), [
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => $params,
                'id' => 1,
            ]);

        if (!$res->successful()) {
            throw new \RuntimeException("Zabbix API HTTP error calling {$method}: " . $res->status());
        }

        $body = $res->json();
        if (isset($body['error'])) {
            throw new \RuntimeException("Zabbix API error calling {$method}: " . ($body['error']['data'] ?? $body['error']['message'] ?? 'unknown'));
        }

        return $body['result'] ?? [];
    }

    /**
     * Real, per-tenant isolation boundary, matching the same pattern
     * already proven for Fleet's own per-tenant labels and Wazuh's own
     * per-tenant groups. Idempotent: finds the real, existing host
     * group by name first, only creating a new one if genuinely absent.
     */
    public function ensureTenantHostGroup(string $slug): ?int
    {
        $groupName = "tenant-{$slug}";

        $existing = $this->call('hostgroup.get', [
            'filter' => ['name' => [$groupName]],
        ]);

        if (!empty($existing)) {
            return (int) $existing[0]['groupid'];
        }

        $created = $this->call('hostgroup.create', [
            'name' => $groupName,
        ]);

        return isset($created['groupids'][0]) ? (int) $created['groupids'][0] : null;
    }

    /**
     * Real, current hosts under a tenant's own real host group -- the
     * actual isolation boundary for any future per-tenant data query.
     */
    public function hostsInGroup(int $groupId): array
    {
        return $this->call('host.get', [
            'groupids' => [$groupId],
            'output' => ['hostid', 'host', 'name', 'status'],
            'selectInterfaces' => ['ip', 'dns', 'useip'],
        ]);
    }

    /**
     * Real, current host group id for a tenant slug, or null if the
     * tenant has no real Zabbix presence yet.
     */
    public function hostGroupIdForSlug(string $slug): ?int
    {
        $groupName = "tenant-{$slug}";
        $existing = $this->call('hostgroup.get', [
            'filter' => ['name' => [$groupName]],
        ]);

        return !empty($existing) ? (int) $existing[0]['groupid'] : null;
    }

    /**
     * Real, genuine teardown counterpart to ensureTenantHostGroup() --
     * this project's own real, documented history (the Fleet 'label'
     * teardown gap, 2026-07-23) is exactly why this exists from day
     * one here, rather than being added only after a real orphaned
     * artifact is found in production.
     */
    public function deleteHostGroup(int $groupId): void
    {
        $this->call('hostgroup.delete', [$groupId]);
    }

    /**
     * Real, per-tenant auto-registration: a new agent reporting the
     * tenant's own real, random secret (via HostMetadata, confirmed
     * directly via Zabbix's own official documentation -- conditiontype
     * 24 for autoregistration events) is automatically placed into that
     * tenant's own real host group, with zero manual host.create call
     * ever needed on our own backend. Idempotent: finds a real,
     * existing action by name first.
     */
    public function ensureTenantAutoRegistration(string $slug, int $groupId, string $secret): ?int
    {
        $actionName = "Obaits: auto-register tenant-{$slug}";

        $existing = $this->call('action.get', [
            'filter' => ['name' => [$actionName]],
        ]);

        if (!empty($existing)) {
            return (int) $existing[0]['actionid'];
        }

        $created = $this->call('action.create', [
            'name' => $actionName,
            'eventsource' => 2, // autoregistration
            'status' => 0, // enabled
            'filter' => [
                'evaltype' => 0, // AND/OR, single condition here
                'conditions' => [
                    ['conditiontype' => 24, 'operator' => 2, 'value' => $secret], // host metadata contains
                ],
            ],
            'operations' => [
                ['operationtype' => 4, 'opgroup' => [['groupid' => $groupId]]], // add to host group
            ],
        ]);

        return isset($created['actionids'][0]) ? (int) $created['actionids'][0] : null;
    }

    public function deleteAction(int $actionId): void
    {
        $this->call('action.delete', [$actionId]);
    }

    public function actionExists(int $actionId): bool
    {
        $res = $this->call('action.get', ['actionids' => [$actionId]]);
        return !empty($res);
    }

    /**
     * Real hosts, across ALL tenants, with NO real monitoring template
     * linked yet -- auto-registration alone only ever creates the host
     * object; a template must be linked separately before any real data
     * is actually collected. Returns id + host name only; the real OS
     * match happens against Fleet's own already-known platform data,
     * not anything guessed here.
     */
    public function hostsWithoutTemplate(): array
    {
        $hosts = $this->call('host.get', [
            'output' => ['hostid', 'host'],
            'selectParentTemplates' => ['templateid'],
        ]);

        return array_values(array_filter($hosts, fn ($h) => empty($h['parentTemplates'])));
    }

    /**
     * Real, official Zabbix templates (Linux by Zabbix agent active /
     * Windows by Zabbix agent active), confirmed directly present in
     * this real instance.
     */
    public const TEMPLATE_LINUX = 10343;
    public const TEMPLATE_WINDOWS = 10351;

    public function linkTemplate(int $hostId, int $templateId): void
    {
        $this->call('host.update', [
            'hostid' => $hostId,
            'templates' => [['templateid' => $templateId]],
        ]);
    }

    /**
     * Real, batched health snapshot for every real host in a tenant's
     * group -- ONE query for hosts, ONE query for all their real items,
     * mapped client-side. Linux and Windows's own official templates use
     * genuinely DIFFERENT real key names for the same real metric
     * (confirmed directly: system.cpu.util[,user] vs plain
     * system.cpu.util) -- both are checked so either OS reports
     * correctly under one unified field name.
     */
    public function healthForGroup(int $groupId): array
    {
        $hosts = $this->call('host.get', [
            'groupids' => [$groupId],
            'output' => ['hostid', 'host', 'status'],
        ]);
        if (empty($hosts)) return [];

        $hostIds = array_column($hosts, 'hostid');
        $items = $this->call('item.get', [
            'hostids' => $hostIds,
            'output' => ['hostid', 'key_', 'lastvalue', 'lastclock'],
        ]);

        $byHost = [];
        foreach ($items as $item) {
            $byHost[$item['hostid']][$item['key_']] = $item;
        }

        // REAL BUG FOUND before this ever reached a real tenant: an
        // item's own DEFINITION exists the moment a template is linked,
        // long before it's ever actually polled -- checking truthiness
        // of the item array alone treated "never reported" the same as
        // "genuinely reported exactly 0", showing a real, misleading
        // green 0% for an offline device. Real fix: only trust a value
        // whose own lastclock is genuinely non-zero (a real poll
        // actually happened), matching this project's own established
        # "null is not false" discipline (ArtifactReconciler).
        $reportedValue = function (?array $item) {
            if (!$item || empty($item['lastclock']) || (int) $item['lastclock'] === 0) return null;
            return $item;
        };

        $result = [];
        foreach ($hosts as $h) {
            $hostItems = $byHost[$h['hostid']] ?? [];
            $cpu = $reportedValue($hostItems['system.cpu.util[,user]'] ?? $hostItems['system.cpu.util'] ?? null);
            $mem = $reportedValue($hostItems['vm.memory.util'] ?? null);
            $uptime = $reportedValue($hostItems['system.uptime'] ?? null);
            $uname = $hostItems['system.uname'] ?? null;

            // Real disk usage is DYNAMICALLY discovered per real
            // filesystem (confirmed directly: Windows only has real
            # vfs.fs.dependent[C:,pused]-style items once it has
            // actually reported at least once; a device that never
            // reported has only the raw discovery rule itself, no real
            // per-filesystem items at all yet). Pick the first real,
            // reported "percent used" item found -- genuinely correct
            // for a typical single-real-filesystem device; a device
            // with several real filesystems shows only its first here,
            // a reasonable v1 simplification stated explicitly.
            $disk = null;
            foreach ($hostItems as $key => $item) {
                if (str_contains($key, 'pused') && str_starts_with($key, 'vfs.fs')) {
                    $disk = $reportedValue($item);
                    if ($disk) break;
                }
            }

            $result[] = [
                'host' => $h['host'],
                'agent_status' => $h['status'] === '0' ? 'monitored' : 'disabled',
                'cpu_percent' => $cpu ? round((float) $cpu['lastvalue'], 1) : null,
                'memory_percent' => $mem ? round((float) $mem['lastvalue'], 1) : null,
                'disk_percent' => $disk ? round((float) $disk['lastvalue'], 1) : null,
                'uptime_seconds' => $uptime ? (int) $uptime['lastvalue'] : null,
                'os' => $uname['lastvalue'] ?? null,
                'last_seen' => $cpu ? (int) $cpu['lastclock'] : null,
            ];
        }

        return $result;
    }

    /**
     * Real, single-host lookup within a tenant's own host group, by name.
     * Zabbix's own host.delete needs its internal hostid, not Fleet's
     * fleet_host_id or a hostname -- this bridges the two, the same real
     * shape already proven in WazuhClient::findAgentInGroup().
     *
     * Matches on the SAME short, lowercased, pre-first-dot form already
     * used four times elsewhere in this project (AutoAssignDeviceGroups,
     * WazuhClient::findAgentInGroup, and the Headscale revocation fix
     * earlier today) -- Zabbix's own auto-registered host name carries the
     * identical FQDN-vs-short-name risk as every other tool here, and this
     * is deliberately defensive against it from day one rather than
     * waiting to find it broken on a real device.
     */
    public function findHostIdByName(int $groupId, string $hostname): ?int
    {
        $want = strtolower(explode('.', trim($hostname))[0]);

        $hosts = $this->call('host.get', [
            'groupids' => [$groupId],
            'output' => ['hostid', 'host', 'name'],
        ]);

        foreach ($hosts as $h) {
            $short = strtolower(explode('.', (string) ($h['host'] ?? $h['name'] ?? ''))[0]);
            if ($short === $want) {
                return (int) $h['hostid'];
            }
        }

        return null;
    }

    /**
     * Real, single-host deletion. Part of the device-removal safety net --
     * previously a removed device's Zabbix host was never cleaned up at
     * all, left reporting stale "unreachable" data into /portal/health and
     * the dashboard's own computers-healthy card indefinitely.
     */
    public function deleteHost(int $hostId): void
    {
        $this->call('host.delete', [$hostId]);
    }
}
