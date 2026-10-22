<?php
namespace App\Livewire\Portal;

use Livewire\Component;
use Illuminate\Support\Facades\DB;

class ThreatDetection extends Component
{
    public string $tenantId;
    public int $days = 7;
    public string $tab = 'fim';   // fim | rootkit | firewall | mitre | sources

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { return redirect('/login'); }
    }

    public function setTab(string $t) { $this->tab = $t; }

    public function render()
    {
        // Same isolation + resolution as SecurityAlerts.
        $group = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)      // ISOLATION
            ->where('tool', 'wazuh')
            ->where('artifact_type', 'agent_group')
            ->value('external_id');

        // REAL BUG FOUND 2026-08-16, confirmed live on a real tenant:
        // this page previously pulled ONE shared top-500-most-recent
        // set of alerts and bucketed it into all four tabs -- on any
        // tenant with real, high-frequency auth/syslog traffic, that
        // shared window was 100% crowded out by syslog/pam/sudo noise
        // (confirmed directly: 500 of 500 fetched events were that
        // noise), even though the SAME tenant genuinely had 706 real
        // syscheck and 557 real rootcheck events in the identical 7-day
        // window. Real fix: each tab now queries its own dedicated,
        // correctly filtered set directly at the indexer (see
        // WazuhClient::alertsForAgentsInGroups/WithMitre), so a noisy
        // category can never crowd out a quiet one again.
        $raw = ['fim' => [], 'rootkit' => [], 'firewall' => [], 'mitre' => []];
        $monitored = false;
        if ($group) {
            try {
                $c = app(\App\Clients\WazuhClient::class);
                $token = $c->token();
                if ($c->groupExists($token, $group)) {
                    $ids = $c->agentIdsInGroup($token, $group);
                    if (!empty($ids)) {
                        $monitored = true;
                        $raw['fim']      = $c->alertsForAgentsInGroups($ids, ['syscheck'], $this->days, 200);
                        $raw['rootkit']  = $c->alertsForAgentsInGroups($ids, ['rootcheck', 'rootkit'], $this->days, 200);
                        $raw['firewall'] = $c->alertsForAgentsInGroups($ids, ['firewall', 'iptables', 'firewalld', 'pf'], $this->days, 200);
                        $raw['mitre']    = $c->alertsForAgentsWithMitre($ids, $this->days, 200);
                    }
                }
            } catch (\Throwable $e) {
                $raw = ['fim' => [], 'rootkit' => [], 'firewall' => [], 'mitre' => []];
            }
        }

        // Bucket by rule group -- the heart of this feature. Each tab
        // now iterates ONLY its own already-correctly-filtered set,
        // rather than re-filtering one shared combined list the way
        // this page used to.
        $buckets = ['fim' => [], 'rootkit' => [], 'firewall' => [], 'mitre' => []];

        foreach ($raw['fim'] as $a) {
            $level = (int) ($a['rule']['level'] ?? 0);
            $buckets['fim'][] = [
                'time'        => $a['@timestamp'] ?? ($a['timestamp'] ?? null),
                'agent'       => $a['agent']['name'] ?? ($a['agent']['id'] ?? '-'),
                'level'       => $level,
                'severity'    => $level >= 12 ? 'high' : ($level >= 7 ? 'medium' : 'low'),
                'description' => $a['rule']['description'] ?? 'Security event',
                'detail'      => $a['full_log'] ?? null,
                'path'        => $a['syscheck']['path'] ?? '-',
                'event'       => $a['syscheck']['event'] ?? 'changed',
            ];
        }

        foreach ($raw['rootkit'] as $a) {
            $level = (int) ($a['rule']['level'] ?? 0);
            $desc = $a['rule']['description'] ?? 'Security event';
            $buckets['rootkit'][] = [
                'time'        => $a['@timestamp'] ?? ($a['timestamp'] ?? null),
                'agent'       => $a['agent']['name'] ?? ($a['agent']['id'] ?? '-'),
                'level'       => $level,
                'severity'    => $level >= 12 ? 'high' : ($level >= 7 ? 'medium' : 'low'),
                'description' => $desc,
                'detail'      => $a['full_log'] ?? null,
                'title'       => $a['data']['title'] ?? $desc,
            ];
        }

        foreach ($raw['firewall'] as $a) {
            $level = (int) ($a['rule']['level'] ?? 0);
            $buckets['firewall'][] = [
                'time'        => $a['@timestamp'] ?? ($a['timestamp'] ?? null),
                'agent'       => $a['agent']['name'] ?? ($a['agent']['id'] ?? '-'),
                'level'       => $level,
                'severity'    => $level >= 12 ? 'high' : ($level >= 7 ? 'medium' : 'low'),
                'description' => $a['rule']['description'] ?? 'Security event',
                'detail'      => $a['full_log'] ?? null,
                'srcip'       => $a['data']['srcip'] ?? ($a['data']['src_ip'] ?? '-'),
                'action'      => $a['data']['action'] ?? 'blocked',
            ];
        }

        foreach ($raw['mitre'] as $a) {
            $level = (int) ($a['rule']['level'] ?? 0);
            $base = [
                'time'        => $a['@timestamp'] ?? ($a['timestamp'] ?? null),
                'agent'       => $a['agent']['name'] ?? ($a['agent']['id'] ?? '-'),
                'level'       => $level,
                'severity'    => $level >= 12 ? 'high' : ($level >= 7 ? 'medium' : 'low'),
                'description' => $a['rule']['description'] ?? 'Security event',
                'detail'      => $a['full_log'] ?? null,
            ];
            $ids  = $a['rule']['mitre']['id'] ?? [];
            $tech = $a['rule']['mitre']['technique'] ?? [];
            $tac  = $a['rule']['mitre']['tactic'] ?? [];
            foreach ($ids as $i => $mid) {
                $buckets['mitre'][] = $base + [
                    'mitre_id'  => $mid,
                    'technique' => $tech[$i] ?? ($tech[0] ?? '-'),
                    'tactic'    => $tac[$i] ?? ($tac[0] ?? '-'),
                ];
            }
        }

        // REAL FEATURE 2026-08-16, requested directly: a fifth tab
        // showing exactly where the baseline threat-intel block lists
        // (see resources/views/enroll/threat-intel-refresh-sh.blade.php) come from and how
        // many real entries each source contributed -- genuine
        // transparency into what's actually being blocked platform-wide,
        // not tenant-specific data (this protection is baseline, applies
        // to every device regardless of tenant, per its own design).
        //
        // REAL DESIGN, per Brian's own explicit choice: rather than
        // fetching Spamhaus live from this page (which only ever showed
        // the RAW feed size, not what's actually enforced, and left
        // URLhaus/hagezi silently blank), every count here comes from
        // the actual device that most recently refreshed each source --
        // /enroll/threat-intel-source-counts, reported by
        // threat-intel-refresh.sh itself after every real cycle. Same
        // "trust what the device reports" discipline used everywhere
        // else in this project.
        $reports = DB::table('threat_intel_source_reports')->get()->keyBy('source');
        $sourceMeta = [
            'spamhaus_drop' => ['name' => 'Spamhaus DROP', 'url' => 'https://www.spamhaus.org/drop/', 'type' => 'IP ranges'],
            'urlhaus'       => ['name' => 'URLhaus (abuse.ch)', 'url' => 'https://urlhaus.abuse.ch/', 'type' => 'Domains'],
            'hagezi_tif'    => ['name' => 'hagezi Threat Intelligence Feeds (mini)', 'url' => 'https://github.com/hagezi/dns-blocklists', 'type' => 'Domains'],
            'threatfox'     => ['name' => 'ThreatFox (abuse.ch)', 'url' => 'https://threatfox.abuse.ch/', 'type' => 'IPs + Domains'],
        ];
        $sources = [];
        foreach ($sourceMeta as $key => $meta) {
            $report = $reports->get($key);
            $sources[] = $meta + [
                'count' => $report->count ?? null,
                'last_reported_at' => $report->reported_at ?? null,
                'last_reported_by' => $report->reported_by_hostname ?? null,
            ];
        }

        return view('livewire.portal.threat-detection', [
            'buckets'   => $buckets,
            'monitored' => $monitored,
            'hasGroup'  => (bool) $group,
            'sources'   => $sources,
        ])->layout('components.layouts.portal');
    }
}
