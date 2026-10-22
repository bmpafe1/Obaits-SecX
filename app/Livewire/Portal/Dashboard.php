<?php
namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Layout('components.layouts.portal')]
class Dashboard extends Component
{
    public function render()
    {
        $tenantId = session('tenant_id');
        $tenant = DB::table('tenants')->where('id', $tenantId)->first();

        // ---- Findings by severity ----
        $sev = DB::table('findings')->where('tenant_id', $tenantId)
            ->selectRaw('criticality, count(*) c')->groupBy('criticality')
            ->pluck('c', 'criticality');

        $severity = [
            'critical' => (int) ($sev[5] ?? 0),
            'high'     => (int) ($sev[4] ?? 0),
            'medium'   => (int) ($sev[3] ?? 0),
            'low'      => (int) ($sev[2] ?? 0),
            'info'     => (int) ($sev[1] ?? 0),
        ];
        $urgent = $severity['critical'] + $severity['high'];

        // ---- Actively exploited (KEV) — honest: 0 until the engine writes KEV markers ----
        $kev = 0;
        foreach (DB::table('findings')->where('tenant_id', $tenantId)
                    ->whereNotNull('risk_factors')->pluck('risk_factors') as $json) {
            foreach ((json_decode($json, true) ?: []) as $f) {
                $hay = strtolower(($f['signal'] ?? '') . ' ' . ($f['source'] ?? '') . ' ' . ($f['value'] ?? ''));
                if (str_contains($hay, 'kev') || str_contains($hay, 'known exploited')) { $kev++; break; }
            }
        }

        // ---- Top risky hosts (active-scan findings only, so far) ----
        $topHosts = DB::table('findings')->where('tenant_id', $tenantId)
            ->whereNotNull('host')->where('host', '!=', '')
            ->selectRaw('host, count(*) total, sum(case when criticality >= 4 then 1 else 0 end) urgent')
            ->groupBy('host')->orderByDesc('urgent')->orderByDesc('total')
            ->limit(5)->get();

        // ---- Real, Wazuh/Fleet-reported OS-package vulnerabilities ----------
        // REAL GAP FOUND directly by Brian: the dashboard's own severity and
        // at-risk cards only ever reflected active-SCAN findings (the
        // 'findings' table), completely blind to the real, substantial
        // vulnerability data already shown on /portal/vulnerable-software
        // (Wazuh's own agent-reported installed software, cross-referenced
        // against cve_severity_cache) -- a tenant with genuinely zero scan
        // findings but thousands of real, known CVEs saw a falsely
        // reassuring "no urgent findings", the exact kind of false comfort
        // this project's own Coverage panel philosophy already exists to
        // avoid elsewhere. Both real sources are now combined, matching the
        // same real logic and cve_severity_cache lookup already proven in
        // VulnerableSoftware.php.
        $vulnFleetSecret = DB::table('tenant_artifacts')
            ->where('tenant_id', $tenantId)->where('tool', 'fleet')
            ->where('artifact_type', 'enroll_secret')->value('external_id');

        $vulnHostTally = [];   // hostname => ['total' => n, 'urgent' => n]
        if ($vulnFleetSecret) {
            try {
                $vulnItems = app(\App\Clients\FleetClient::class)->vulnerableSoftwareForTenant($vulnFleetSecret);

                $allCves = [];
                foreach ($vulnItems as $item) {
                    foreach ($item['cves'] as $c) { $allCves[] = $c['cve']; }
                }
                $sevByCve = [];
                if (!empty($allCves)) {
                    foreach (DB::table('cve_severity_cache')->whereIn('cve', array_unique($allCves))->get(['cve', 'severity']) as $r) {
                        $sevByCve[$r->cve] = strtolower((string) $r->severity);
                    }
                }

                foreach ($vulnItems as $item) {
                    $itemCritical = 0; $itemHigh = 0; $itemOther = 0;
                    foreach ($item['cves'] as $c) {
                        match ($sevByCve[$c['cve']] ?? null) {
                            'critical' => $itemCritical++,
                            'high' => $itemHigh++,
                            default => $itemOther++,
                        };
                    }
                    $severity['critical'] += $itemCritical;
                    $severity['high']     += $itemHigh;
                    $severity['medium']   += $itemOther;   // real, honest bucket: not critical/high, severity unresolved or lower

                    foreach ($item['hosts'] as $hostname) {
                        $vulnHostTally[$hostname] ??= ['total' => 0, 'urgent' => 0];
                        $vulnHostTally[$hostname]['total']  += count($item['cves']);
                        $vulnHostTally[$hostname]['urgent'] += $itemCritical + $itemHigh;
                    }
                }
            } catch (\Throwable $e) {
                // Real, honest degrade: dashboard still shows real scan
                // findings even if Fleet is briefly unreachable.
            }
        }

        $urgent = $severity['critical'] + $severity['high'];

        // Real, combined "most at-risk systems": merge scan-based findings
        // per host with vulnerability-based counts per host, rather than
        // showing two separate, confusing lists.
        $combinedHosts = [];
        foreach ($topHosts as $h) {
            $combinedHosts[$h->host] = ['host' => $h->host, 'total' => $h->total, 'urgent' => $h->urgent];
        }
        foreach ($vulnHostTally as $hostname => $counts) {
            $combinedHosts[$hostname] ??= ['host' => $hostname, 'total' => 0, 'urgent' => 0];
            $combinedHosts[$hostname]['total']  += $counts['total'];
            $combinedHosts[$hostname]['urgent'] += $counts['urgent'];
        }
        // Cast back to objects: the existing blade view uses $h->host
        // (object) syntax, matching DB::table()->get()'s own real
        // return shape -- must stay consistent after this real merge.
        $topHosts = collect(array_values($combinedHosts))
            ->map(fn ($h) => (object) $h)
            ->sortByDesc('urgent')->sortByDesc('total')->take(5)->values();

        // ---- Compliance progress PER FRAMEWORK (multi-framework, first-class) ----
        $frameworks = [];
        foreach (DB::table('tenant_frameworks')->where('tenant_id', $tenantId)->get() as $fw) {
            $docKeys = DB::table('document_control_map')
                ->where('framework_id', $fw->framework_id)
                ->distinct()->pluck('doc_key');

            $required = $docKeys->count();

            $statuses = DB::table('tenant_documents')
                ->where('tenant_id', $tenantId)
                ->whereIn('doc_key', $docKeys)
                ->selectRaw('doc_key, max(status) status')
                ->groupBy('doc_key')->pluck('status', 'doc_key');

            $final   = $statuses->filter(fn ($s) => $s === 'final')->count();
            $partial = $statuses->filter(fn ($s) => $s === 'draft')->count();

            $frameworks[] = [
                'name'       => $fw->framework_name,
                'required'   => $required,
                'final'      => $final,
                'partial'    => $partial,
                'notStarted' => max(0, $required - $final - $partial),
                'percent'    => $required > 0 ? (int) round(($final / $required) * 100) : 0,
            ];
        }

        // ---- Coverage: what is ACTUALLY protected ----
        $domainsTotal   = DB::table('tenant_domains')->where('tenant_id', $tenantId)->count();
        $domainsScanned = DB::table('scans')->where('tenant_id', $tenantId)
            ->where('status', 'done')->distinct()->count('target');
        $monitored = DB::table('tenant_artifacts')->where('tenant_id', $tenantId)
            ->where('tool', 'wazuh')->where('artifact_type', 'agent_group')->exists();
        $wafTotal = Schema::hasTable('waf_deployments')
            ? DB::table('waf_deployments')->where('tenant_id', $tenantId)->count() : 0;
        $wafBlocking = Schema::hasTable('waf_deployments')
            ? DB::table('waf_deployments')->where('tenant_id', $tenantId)->where('mode', 'blocking')->count() : 0;

        $coverage = [
            'domainsTotal'   => $domainsTotal,
            'domainsScanned' => min($domainsScanned, $domainsTotal),
            'wafTotal'       => $wafTotal,
            'wafBlocking'    => $wafBlocking,
        ];

        // ---- Incidents ----
        $openIncidents = 0; $avgHours = null;
        if (Schema::hasTable('tickets')) {
            $openIncidents = DB::table('tickets')->where('tenant_id', $tenantId)
                ->where('is_incident', true)->whereIn('status', ['open','in_progress'])->count();

            $closed = DB::table('tickets')->where('tenant_id', $tenantId)
                ->where('is_incident', true)
                ->whereNotNull('resolved_at')->whereNotNull('detected_at')->get();

            if ($closed->isNotEmpty()) {
                $avgHours = round($closed->map(fn ($i) =>
                    (strtotime($i->resolved_at) - strtotime($i->detected_at)) / 3600)->avg(), 1);
            }
        }

        $docsFinal = DB::table('tenant_documents')->where('tenant_id', $tenantId)
            ->where('status', 'final')->distinct()->count('doc_key');

        $recentScans = DB::table('scans')->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')->limit(5)->get();

        // ---- Endpoint coverage -------------------------------------------
        // The Coverage panel counted domains and the WAF but never mentioned
        // computers, which is the largest gap in a tenant's picture: we can
        // only see machines where an agent is installed. A machine on their
        // network without one is not "showing no problems" — it is invisible.
        $agentHosts = 0;
        $fleetSecret = DB::table('tenant_artifacts')
            ->where('tenant_id', $tenantId)->where('tool', 'fleet')
            ->where('artifact_type', 'enroll_secret')->value('external_id');

        if ($fleetSecret) {
            try {
                $agentHosts = count(app(\App\Clients\FleetClient::class)->hostsForTenant($fleetSecret));
            } catch (\Throwable $e) {
                $agentHosts = 0;
            }
        }
         
        // ---- IT asset health (Zabbix) -------------------------------------
        // Same real, honest "what is actually protected/visible" philosophy
        // as the rest of the Coverage panel: real devices reporting real
        // health data, and how many currently show a real resource
        // warning (CPU/memory/disk >= 90%) -- not assumed, computed
        // directly from the same real data the /portal/health page itself
        // shows, so the two are always genuinely consistent with each other.
        $healthDevices = 0;
        $healthWarnings = 0;
        $zabbixGroupId = DB::table('tenant_artifacts')
            ->where('tenant_id', $tenantId)->where('tool', 'zabbix')
            ->where('artifact_type', 'host_group')->value('external_id');

        if ($zabbixGroupId) {
            try {
                $healthData = app(\App\Clients\ZabbixClient::class)->healthForGroup((int) $zabbixGroupId);
                foreach ($healthData as $d) {
                    $reporting = $d['cpu_percent'] !== null || $d['memory_percent'] !== null;
                    if ($reporting) $healthDevices++;
                    $atRisk = ($d['cpu_percent'] ?? 0) >= 90 || ($d['memory_percent'] ?? 0) >= 90 || ($d['disk_percent'] ?? 0) >= 90;
                    if ($reporting && $atRisk) $healthWarnings++;
                }
            } catch (\Throwable $e) {
                // Real, honest degrade: leave both at 0 rather than guess.
            }
        }

        $healthyDevices = $healthDevices - $healthWarnings;

        return view('livewire.portal.dashboard', compact(
            'tenant','severity','urgent','kev','topHosts','frameworks',
            'coverage','monitored','openIncidents','avgHours','docsFinal','recentScans',
            'agentHosts','healthDevices','healthWarnings','healthyDevices'
        ));
    }
}