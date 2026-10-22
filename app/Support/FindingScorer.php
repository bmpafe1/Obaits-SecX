<?php
namespace App\Support;
 
use Illuminate\Support\Facades\DB;
 
class FindingScorer
{
    // Score every unscored finding for a scan.
    public static function scoreScan(string $scanId): int
    {
        $scan = DB::table('scans')->where('id', $scanId)->first();
        if (!$scan) return 0;
 
        $host = parse_url($scan->target, PHP_URL_HOST) ?: $scan->target;

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            // An IP: internet-facing only if it's a public address.
            $internetFacing = !Reachability::isPrivate($host);
        } else {
            // A domain name: publicly resolvable, so internet-facing.
            $internetFacing = true;
        }
        
        $findings = DB::table('findings')->where('scan_id', $scanId)->get();
        $n = 0;
 
        foreach ($findings as $f) {
            $result = RiskEngine::score(
                $f->cve ?: null,
                is_numeric($f->severity) ? (float) $f->severity : null,
                $internetFacing,
                $f->threat ?: null,
                ($scan->scan_type ?? 'network') === 'web'
            );
 
            DB::table('findings')->where('id', $f->id)->update([
                'criticality' => $result['criticality'],
                'risk_factors' => json_encode($result['factors']),
                'scored_at' => now(),
                'updated_at' => now(),
            ]);
            $n++;
        }

        // ---- Critical-vulnerability digest: ONE honest notification per scan. ----
        self::notifyIfCritical($scan);
 
        return $n;
    }
 
    /**
     * Raise at most one notification summarising a scan's most serious findings.
     * Email only when something is actively exploited (criticality 5 / KEV).
     */
    private static function notifyIfCritical(object $scan): void
    {
        if (($scan->alerted_at ?? null) !== null) return;
        // Count this scan's findings by the two levels that matter.
        $critical = DB::table('findings')            // 5 = KEV = actively exploited
            ->where('scan_id', $scan->id)->where('criticality', 5)->count();
        $high = DB::table('findings')                // 4 = high, serious but not confirmed-exploited
            ->where('scan_id', $scan->id)->where('criticality', 4)->count();
 
        // Nothing serious — stay silent. No news is good news; we do not manufacture alerts.
        if ($critical === 0 && $high === 0) return;
 
        $link = '/portal/scan/' . $scan->id . '/findings';
 
        // Build an honest, plain-language message from the real counts.
        if ($critical > 0) {
            $title = trans_choice(
                '{1}1 actively-exploited vulnerability found|[2,*]:count actively-exploited vulnerabilities found',
                $critical, ['count' => $critical]
            );
            $bodyParts = [];
            $bodyParts[] = trans_choice(
                '{1}A scan found 1 vulnerability that is being actively exploited in the wild (on CISA'."'".'s Known Exploited list).'
                . '|[2,*]A scan found :count vulnerabilities that are being actively exploited in the wild (on CISA'."'".'s Known Exploited list).',
                $critical, ['count' => $critical]
            );
            if ($high > 0) {
                $bodyParts[] = trans_choice(
                    '{1}It also found 1 other high-risk issue.|[2,*]It also found :count other high-risk issues.',
                    $high, ['count' => $high]
                );
            }
            $bodyParts[] = __('These need attention now. Open the scan to see them.');
 
            \App\Support\Notifications::raiseAndEmail($scan->tenant_id, $title, [
                'level' => 'critical',
                'body'  => implode(' ', $bodyParts),
                'link'  => $link,
            ]);
            DB::table('scans')->where('id', $scan->id)->update(['alerted_at' => now()]);
            return;
        }
 
        // Only high findings (no KEV): in-app digest, NO email.
        $title = trans_choice(
            '{1}1 high-risk finding in your latest scan|[2,*]:count high-risk findings in your latest scan',
            $high, ['count' => $high]
        );
        \App\Support\Notifications::raise($scan->tenant_id, $title, [
            'level' => 'warning',
            'body'  => __('Your latest scan found issues worth reviewing. None are confirmed as actively exploited. Open the scan to see them.'),
            'link'  => $link,
        ]);
        DB::table('scans')->where('id', $scan->id)->update(['alerted_at' => now()]);
    }

        // return $n;
    }
//}
