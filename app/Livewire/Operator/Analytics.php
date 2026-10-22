<?php

namespace App\Livewire\Operator;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;

/**
 * Product analytics across all tenants.
 *
 * Reads tenant_snapshots rather than querying live: Fleet host inventory and
 * IRIS case counts are API calls with no local cache, and hitting them per
 * tenant on every page load would be slow and fragile. The snapshot is captured
 * daily instead, so this page is instant and still works when a service is down.
 *
 * DORMANCY LEADS, deliberately. The telemetry design flags it as the most
 * commercially valuable signal in the whole register: a tenant using two of
 * fourteen capabilities, or whose users have stopped logging in, is a tenant
 * about to churn, and knowing that early is worth more than any vulnerability
 * statistic. Zero is the interesting value on this page.
 */
#[Layout('components.layouts.operator')]
class Analytics extends Component
{
    public int $days = 30;

    /**
     * A crude but useful risk score: how many of the platform's core
     * capabilities is this tenant actually using, and are they logging in?
     *
     * Deliberately simple. A score anyone can explain to a customer beats a
     * weighting nobody can justify.
     */
    public static function engagement(object $s): array
    {
        $used = 0;
        foreach (['device_count', 'scans_total', 'policy_assignments',
                  'backup_selections', 'tickets_total', 'marketplace_requests'] as $f) {
            if (($s->$f ?? 0) > 0) { $used++; }
        }

        $loggedIn = ($s->logins_30d ?? 0) > 0;

        if (!$loggedIn && $used === 0) {
            return ['Dormant', '#8B2F2F', '#F7E4E4', $used];
        }
        if (!$loggedIn) {
            return ['No recent logins', '#8B2F2F', '#F7E4E4', $used];
        }
        if ($used <= 2) {
            return ['Low adoption', '#7A5C00', '#FFF6DC', $used];
        }
        return ['Active', '#0B6E4F', '#E3F3EC', $used];
    }

    /**
     * Group host OS strings by major version, keeping the builds as detail.
     *
     * Fleet reports the full version string, so "Windows Server 2019
     * Datacenter 10.0.17763.9121" and "Windows Server 2019 Standard
     * 10.0.17763.2114" are separate entries. Across a real estate that is
     * dozens of near-identical rows and the distribution becomes unreadable.
     *
     * The build numbers are NOT discarded -- they are the interesting part.
     * Five different builds of the same Windows Server release is a patch
     * consistency problem worth seeing, so they are kept as detail under the
     * group rather than thrown away.
     */
    public static function groupOs(array $dist): array
    {
        $groups = [];

        foreach ($dist as $name => $count) {
            // "Windows Server 2019 Datacenter 10.0.17763.9121" -> "Windows Server 2019"
            // "Ubuntu 24.04.2 LTS"                             -> "Ubuntu 24.04"
            // "Linux Mint 22.3.0"                              -> "Linux Mint 22"
            if (preg_match('/^(Windows Server \d{4}|Windows \d+)/i', $name, $m)) {
                $key = $m[1];
            } elseif (preg_match('/^([A-Za-z ]+?)\s+(\d+\.\d+)/', $name, $m)) {
                $key = trim($m[1]) . ' ' . $m[2];
            } elseif (preg_match('/^([A-Za-z ]+?)\s+(\d+)/', $name, $m)) {
                $key = trim($m[1]) . ' ' . $m[2];
            } else {
                $key = $name;
            }

            if (!isset($groups[$key])) {
                $groups[$key] = ['count' => 0, 'builds' => []];
            }
            $groups[$key]['count'] += $count;
            $groups[$key]['builds'][$name] = ($groups[$key]['builds'][$name] ?? 0) + $count;
        }

        uasort($groups, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $groups;
    }

    public function render()
    {
        $latest = DB::table('tenant_snapshots as s')
            ->join('tenants as t', 't.id', '=', 's.tenant_id')
            ->whereIn('s.captured_on', function ($q) {
                $q->from('tenant_snapshots')->selectRaw('max(captured_on)');
            })
            ->orderBy('t.slug')
            ->select('s.*', 't.slug', 't.organization_name', 't.status as tenant_status')
            ->get();

        // Platform-wide OS distribution, merged across tenants.
        $os = [];
        foreach ($latest as $row) {
            foreach (json_decode($row->os_distribution ?? '{}', true) ?: [] as $name => $n) {
                $os[$name] = ($os[$name] ?? 0) + $n;
            }
        }
        arsort($os);

        // Login trend over the window, for the whole platform.
        $trend = DB::table('user_logins')
            ->where('logged_in_at', '>=', now()->subDays($this->days))
            ->selectRaw('date(logged_in_at) as d, count(*) as n')
            ->groupBy('d')->orderBy('d')->pluck('n', 'd')->all();

        // Feature adoption across the platform, last 30 days.
        //
        // ZERO IS THE INTERESTING VALUE. An area no tenant has opened is a
        // capability nobody wants, or one nobody can find -- and either is
        // worth knowing before more is built on top of it.
        $areas = \App\Support\FeatureUsage::allAreas();

        $used = DB::table('feature_usage')
            ->where('used_on', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('area, sum(views) as views, count(distinct tenant_id) as tenants')
            ->groupBy('area')->get()->keyBy('area');

        $adoption = [];
        foreach ($areas as $area) {
            $row = $used->get($area);
            $adoption[$area] = [
                'views' => (int) ($row->views ?? 0),
                'tenants' => (int) ($row->tenants ?? 0),
            ];
        }
        uasort($adoption, fn ($a, $b) => $b['tenants'] <=> $a['tenants'] ?: $b['views'] <=> $a['views']);

        return view('livewire.operator.analytics', [
            'adoption' => $adoption,
            'tenantCount' => $latest->count(),
            'snapshots' => $latest,
            'osDistribution' => self::groupOs($os),
            'trend' => $trend,
            'capturedOn' => $latest->first()->captured_on ?? null,
            'totals' => [
                'tenants' => $latest->count(),
                'devices' => $latest->sum('device_count'),
                'users' => $latest->sum('user_count'),
                'findings' => $latest->sum('findings_total'),
                'dormant' => $latest->filter(fn ($s) => ($s->logins_30d ?? 0) === 0)->count(),
            ],
        ]);
    }
}
