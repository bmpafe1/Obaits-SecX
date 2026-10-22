<?php
namespace App\Livewire\Portal;

use Livewire\Component;
use Illuminate\Support\Facades\DB;

class VulnerableSoftware extends Component
{
    public string $tenantId;

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { return redirect('/login'); }
    }

    public function render()
    {
        $secret = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)      // ISOLATION
            ->where('tool', 'fleet')
            ->where('artifact_type', 'enroll_secret')
            ->value('external_id');

        $items = [];
        $enrolled = false;
        $reachable = true;

        if ($secret) {
            try {
                $items = app(\App\Clients\FleetClient::class)->vulnerableSoftwareForTenant($secret);
                $enrolled = true;
            } catch (\Throwable $e) {
                $reachable = false;
            }
        }

        // REAL, DELIBERATE DESIGN (Brian's own real feedback, and his own
        // real suggestion to reuse existing infrastructure): the raw
        // CVE-list column could grow unboundedly long for one software
        // package (real example: 30+ CVEs for a single binutils version),
        // making the whole page very tall with no way to scan it at a
        // glance. Real severity (CRITICAL/HIGH/MEDIUM/LOW) is sourced
        // from Wazuh's own Vulnerability Detection module (confirmed
        // directly enabled, syncing from NVD every 60 minutes), via a
        // real, LOCAL cache table (cve_severity_cache, refreshed by the
        // intel:cve-severity command) rather than a live indexer call on
        // every page view -- a real page can reference thousands of
        // distinct CVEs (13,755 known CVEs total, confirmed directly on
        // this real page), and a live per-view call would mean dozens of
        // sequential HTTP requests to the indexer on every single load.
        $allCves = [];
        foreach ($items as $item) {
            foreach ($item['cves'] as $c) {
                $allCves[] = $c['cve'];
            }
        }
        $severityMap = [];
        if (!empty($allCves)) {
            $rows = DB::table('cve_severity_cache')->whereIn('cve', array_unique($allCves))->get(['cve', 'score', 'severity']);
            foreach ($rows as $r) {
                $severityMap[$r->cve] = ['score' => $r->score, 'severity' => $r->severity];
            }
        }

        foreach ($items as &$item) {
            $counts = ['critical' => 0, 'high' => 0, 'other' => 0];
            foreach ($item['cves'] as &$c) {
                $sev = $severityMap[$c['cve']]['severity'] ?? null;
                $c['severity'] = $sev;
                $c['score'] = $severityMap[$c['cve']]['score'] ?? null;
                $bucket = match (strtolower((string) $sev)) {
                    'critical' => 'critical',
                    'high' => 'high',
                    default => 'other',
                };
                $counts[$bucket]++;
            }
            unset($c);
            $item['severity_counts'] = $counts;
        }
        unset($item);

        $totalCves = array_sum(array_map(fn ($i) => $i['cve_count'], $items));

        return view('livewire.portal.vulnerable-software', [
            'items'     => $items,
            'enrolled'  => $enrolled,
            'reachable' => $reachable,
            'hasSecret' => (bool) $secret,
            'totalCves' => $totalCves,
        ])->layout('components.layouts.portal');
    }
}
