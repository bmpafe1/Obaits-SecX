<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records which parts of the platform a tenant actually opens.
 *
 * CHEAP BY CONSTRUCTION. This runs on every portal request, on the path taken
 * from 791ms to 52ms, so it must never be the reason a page is slow:
 *
 *   - the area is derived from the path with no database lookup
 *   - a per-user-per-area-per-day cache entry means the write happens ONCE a
 *     day per person per area, not once per page view
 *   - every failure is swallowed; a usage metric is never worth a failed request
 *
 * The view count is still incremented per request, because "opened compliance
 * once" and "opened compliance forty times" are different facts -- but that is a
 * single indexed UPDATE, not an insert.
 */
class FeatureUsage
{
    /**
     * Path prefix to product area. Deliberately the AREA, not the route:
     * routes churn, areas are the product's own shape and survive refactors.
     */
    private const AREAS = [
        'portal/alerts'          => 'security-operations',
        'portal/live-query'      => 'security-operations',
        'portal/threat-intel'    => 'security-operations',
        'portal/devices'         => 'assets',
        'portal/device-groups'   => 'assets',
        'portal/software'        => 'assets',
        'portal/policies'        => 'assets',
        'portal/scans'           => 'vulnerability',
        'portal/findings'        => 'vulnerability',
        'portal/web-scans'       => 'vulnerability',
        'portal/compliance'      => 'compliance',
        'portal/document'        => 'compliance',
        'portal/risk'            => 'compliance',
        'portal/cases'           => 'incident-response',
        'portal/helpdesk'        => 'incident-response',
        'portal/tickets'         => 'incident-response',
        'portal/backups'         => 'infrastructure',
        'portal/assets-health'   => 'infrastructure',
        'portal/vpn'             => 'infrastructure',
        'portal/cctv'            => 'physical-security',
        'portal/ssl'             => 'certificates',
        'portal/domains'         => 'certificates',
        'portal/ai'              => 'ai-assistant',
        'portal/marketplace'     => 'marketplace',
        'portal/trainings'       => 'trainings',
        'portal/billing'         => 'billing',
        'portal/team'            => 'administration',
        'portal/organisation'    => 'administration',
        'portal/api-keys'        => 'administration',
        'dashboard'              => 'dashboard',
        'portal/reports'         => 'dashboard',
    ];

    /** Every area the platform has, so unused ones can be shown as zero. */
    public static function allAreas(): array
    {
        return array_values(array_unique(array_values(self::AREAS)));
    }

    public static function areaFor(string $path): ?string
    {
        $path = trim($path, '/');

        foreach (self::AREAS as $prefix => $area) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return $area;
            }
        }

        return null;
    }

    public static function record(string $tenantId, ?string $userId, string $path): void
    {
        try {
            $area = self::areaFor($path);
            if (!$area) { return; }

            $today = now()->toDateString();

            // The view count matters: "opened once" and "opened forty times"
            // are different facts. One indexed update, no insert.
            $updated = DB::table('feature_usage')
                ->where('tenant_id', $tenantId)->where('area', $area)->where('used_on', $today)
                ->update(['views' => DB::raw('views + 1'), 'updated_at' => now()]);

            if (!$updated) {
                DB::table('feature_usage')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenantId, 'area' => $area, 'used_on' => $today,
                    'views' => 1, 'distinct_users' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            // Distinct users, written once per person per area per day. The
            // cache check means no query at all on the other 99% of requests.
            if ($userId) {
                $key = "fu:{$userId}:{$area}:{$today}";

                if (!cache()->has($key)) {
                    $new = DB::table('feature_usage_seen')->insertOrIgnore([
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $tenantId, 'user_id' => $userId,
                        'area' => $area, 'used_on' => $today,
                    ]);

                    if ($new) {
                        DB::table('feature_usage')
                            ->where('tenant_id', $tenantId)->where('area', $area)->where('used_on', $today)
                            ->update(['distinct_users' => DB::raw('distinct_users + 1')]);
                    }

                    cache()->put($key, true, now()->endOfDay());
                }
            }
        } catch (\Throwable $e) {
            // Deliberately silent. A usage metric is never worth a failed page.
        }
    }
}
