<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;

class RiskEngine
{
    /**
     * Score one finding 1..5 and return the traceable reasoning.
     *
     * 5 = Fix now       (actively exploited in the wild)
     * 4 = Fix this week (high probability of exploitation)
     * 3 = Plan a fix    (serious, but not being exploited)
     * 2 = Low priority
     * 1 = Informational
     */
public static function score(
        ?string $cve,
        ?float $cvss,
        bool $internetFacing = false,
        ?string $threat = null,
        bool $isWeb = false
    ): array {
        $factors = [];
        $criticality = 1;

        // =====================================================================
        // WEB findings (ZAP): flaws in the tenant's OWN application.
        // There is no published CVE, so KEV/EPSS cannot apply — and that is not
        // a gap, it is the nature of web weaknesses. Score on the weakness's own
        // risk level plus exposure. Deliberately capped at 4: only CISA KEV can
        // prove active exploitation, so 5 ("Fix now") stays reserved for that.
        // =====================================================================
        if ($isWeb) {
            $criticality = match ($threat) {
                'High'   => 3,
                'Medium' => 2,
                'Low'    => 2,
                default  => 1,   // 'Log' / informational
            };

            $factors[] = [
                'signal' => 'web_risk',
                'value' => $threat,
                'source' => 'Web application scan',
                'meaning' => 'A weakness in your own web application, rated '
                    . strtolower($threat ?? 'informational') . ' risk.',
            ];

            if ($internetFacing) {
                $factors[] = [
                    'signal' => 'exposure',
                    'value' => 'internet-facing',
                    'source' => 'Obaits asset context',
                    'meaning' => 'This application is reachable from the internet, so attackers can reach it directly.',
                ];
                if ($criticality >= 2) {
                    $criticality = min(4, $criticality + 1);
                }
            } else {
                $factors[] = [
                    'signal' => 'exposure',
                    'value' => 'internal',
                    'source' => 'Obaits asset context',
                    'meaning' => 'This application is on your internal network, so an attacker would need a foothold first.',
                ];
            }

            return [
                'criticality' => $criticality,
                'factors' => $factors,
                'summary' => self::label($criticality),
            ];
        }

        // =====================================================================
        // NETWORK findings (GVM): known vulnerabilities in third-party software,
        // identified by CVE. These CAN be looked up in KEV and EPSS.
        // =====================================================================

        // --- Signal 1: CISA KEV. The strongest signal there is. ---
        $kev = $cve ? DB::table('kev_entries')->where('cve', $cve)->first() : null;
        if ($kev) {
            $criticality = 5;
            $factors[] = [
                'signal' => 'kev',
                'value' => true,
                'source' => 'CISA Known Exploited Vulnerabilities catalogue',
                'meaning' => 'This vulnerability is being actively exploited by attackers right now.',
            ];
            if ($kev->ransomware) {
                $factors[] = [
                    'signal' => 'ransomware',
                    'value' => true,
                    'source' => 'CISA KEV',
                    'meaning' => 'Known to be used in ransomware campaigns.',
                ];
            }
            if ($kev->required_action) {
                $factors[] = [
                    'signal' => 'required_action',
                    'value' => $kev->required_action,
                    'source' => 'CISA KEV',
                    'meaning' => 'CISA recommended action.',
                ];
            }
        }

        // --- Signal 2: EPSS. Probability of exploitation in the next 30 days. ---
        $epssRow = $cve ? DB::table('epss_scores')->where('cve', $cve)->first() : null;
        $epss = $epssRow ? (float) $epssRow->epss : null;
        if ($epss !== null) {
            $factors[] = [
                'signal' => 'epss',
                'value' => $epss,
                'source' => 'FIRST EPSS',
                'meaning' => 'Estimated ' . round($epss * 100, 1) . '% chance of exploitation within 30 days.',
            ];
            if (!$kev) {   // KEV already pinned it at 5
                if ($epss >= 0.50)      $criticality = max($criticality, 4);
                elseif ($epss >= 0.10)  $criticality = max($criticality, 3);
                elseif ($epss >= 0.01)  $criticality = max($criticality, 2);
            }
        }

        // --- Signal 3: CVSS. How bad IF exploited. ---
        if ($cvss !== null) {
            $factors[] = [
                'signal' => 'cvss',
                'value' => $cvss,
                'source' => 'CVSS base score',
                'meaning' => 'Technical severity if exploited (0-10).',
            ];
            // CVSS alone never reaches 5 — severity without exploitation evidence is not an emergency.
            if (!$kev) {
                if ($cvss >= 9.0)      $criticality = max($criticality, 3);
                elseif ($cvss >= 7.0)  $criticality = max($criticality, 2);
            }
        }

        // --- Signal 4: Exposure. Reachable from the internet? ---
        if ($internetFacing) {
            $factors[] = [
                'signal' => 'exposure',
                'value' => 'internet-facing',
                'source' => 'Obaits asset context',
                'meaning' => 'This system is reachable from the internet, so attackers can reach it directly.',
            ];
            // Exposure escalates by one, but never manufactures a 5 on its own.
            if (!$kev && $criticality >= 2) {
                $criticality = min(4, $criticality + 1);
            }
        } else {
            $factors[] = [
                'signal' => 'exposure',
                'value' => 'internal',
                'source' => 'Obaits asset context',
                'meaning' => 'This system is on your internal network, so an attacker would need a foothold first.',
            ];
        }

        return [
            'criticality' => $criticality,
            'factors' => $factors,
            'summary' => self::label($criticality),
        ];
    }

    public static function label(int $c): string
    {
        return match ($c) {
            5 => 'Fix now',
            4 => 'Fix this week',
            3 => 'Plan a fix',
            2 => 'Low priority',
            default => 'Informational',
        };
    }
}