<?php
namespace App\Jobs;

use App\Clients\ZapClient;
use App\Support\Audit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RunWebScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 7200;

    public function __construct(public string $scanId, public bool $activeScan = false) {}

    public function handle(ZapClient $zap): void
    {
        $scan = DB::table('scans')->where('id', $this->scanId)->first();
        if (!$scan) return;

        // OWNERSHIP GATE: the URL's host must be a verified domain for this tenant.
        $host = parse_url($scan->target, PHP_URL_HOST) ?: $scan->target;
        $hostNoWww = preg_replace('#^www\.#', '', $host);
        $owned = DB::table('tenant_domains')
            ->where('tenant_id', $scan->tenant_id)
            ->where('verified', true)
            ->where(function ($q) use ($host, $hostNoWww) {
                $q->where('domain', $host)->orWhere('domain', $hostNoWww);
            })->exists();

        // Dev owned test targets (DVWA on the host LAN IP / localhost variants).
        $devHosts = ['127.0.0.1', 'localhost'];
        $isDev = in_array($host, $devHosts)
            || str_starts_with($host, '192.168.')
            || str_starts_with($host, '10.')
            || str_starts_with($host, '172.');

        if (!$owned && !$isDev) {
            DB::table('scans')->where('id', $scan->id)->update(['status' => 'failed', 'updated_at' => now()]);
            Audit::log($scan->tenant_id, 'system', 'webscan.refused_unverified', 'failure', 'zap', ['target' => $scan->target]);
            throw new \RuntimeException('Refused: web-scan target host not a verified domain for this tenant');
        }

        DB::table('scans')->where('id', $scan->id)->update([
            'status' => 'running', 'started_at' => now(), 'progress' => 0, 'updated_at' => now(),
        ]);

        // --- Authenticated scanning ------------------------------------------
        // If this tenant has a VERIFIED login for this site, scan as that user.
        // Only a profile that has actually been proven to work is used: a login
        // we have never confirmed would produce an anonymous scan wearing an
        // authenticated label, which is worse than no login at all.
        $authCtx = null; $authUid = null; $authState = 'none'; $ctxName = null;

        $profile = DB::table('zap_auth_profiles')
            ->where('tenant_id', $scan->tenant_id)          // ISOLATION
            ->where('last_verify_result', 'authenticated')
            ->get()
            ->first(fn ($p) => parse_url($p->target_url, PHP_URL_HOST) === $host);

        if ($profile) {
            $authState = 'failed';   // assume failure until proven otherwise
            try {
                if ($zap->ensureAuthScript()) {
                    $ctxName = 'obaits-scan-' . $scan->id;
                    $authCtx = $zap->createContext($ctxName, '\Qhttp\E.*\Q' . $host . '\E.*');

                    if ($authCtx) {
                        $zap->configureLogin(
                            $authCtx, $profile->login_url, (string) $profile->token_name,
                            $profile->username_field, $profile->password_field,
                            $profile->logged_in_regex, (string) $profile->logged_out_regex,
                            (string) $profile->extra_post_data
                        );

                        $authUid = $zap->createUser(
                            $authCtx, 'obaits-scan-user', $profile->username,
                            \Illuminate\Support\Facades\Crypt::decryptString($profile->password)
                        );

                        // Confirm the login works NOW, not just when it was saved.
                        if ($authUid && $zap->loginSucceeds($authCtx, $authUid, $profile->login_url, $profile->logged_in_regex) !== false) {
                            $authState = 'yes';
                        } else {
                            $authUid = null;   // fall back to an anonymous scan
                        }
                    }
                }
            } catch (\Throwable $e) {
                $authUid = null;
            }

            if ($authState === 'failed') {
                Audit::log($scan->tenant_id, 'system', 'webscan.login_failed', 'failure', 'zap',
                    ['target' => $scan->target, 'profile' => $profile->name]);
            }
        }

        DB::table('scans')->where('id', $scan->id)->update(['authenticated' => $authState]);

        // 1) Spider (safe crawl) — capped so it cannot hang forever (max 5 minutes).
        $spiderId = $authUid
            ? $zap->spiderAsUser($authCtx, $authUid, $scan->target)
            : $zap->spider($scan->target);
        $waited = 0;
        while ($zap->spiderStatus($spiderId) < 100 && $waited < 300) {
            if (DB::table('scans')->where('id', $scan->id)->value('status') === 'cancelled') return;
            sleep(5);
            $waited += 5;
            $sp = $zap->spiderStatus($spiderId);
            DB::table('scans')->where('id', $scan->id)->update([
                'progress' => (int)($sp / ($this->activeScan ? 3 : 1)),
                'updated_at' => now(),
            ]);
        }

        // 2) Active scan (intrusive) — only if requested AND it actually starts. Capped at 10 minutes.
        if ($this->activeScan) {
            // An authenticated active scan submits forms AS the logged-in user.
            // It will exercise whatever that account can do.
            $ascanId = $authUid
                ? $zap->activeScanAsUser($authCtx, $authUid, $scan->target)
                : $zap->activeScan($scan->target);
            if ($ascanId && $ascanId !== '0') {
                $waited = 0;
                while ($zap->activeScanStatus($ascanId) < 100 && $waited < 600) {
                    if (DB::table('scans')->where('id', $scan->id)->value('status') === 'cancelled') return;
                    sleep(10);
                    $waited += 10;
                    DB::table('scans')->where('id', $scan->id)->update([
                        'progress' => (int)(33 + $zap->activeScanStatus($ascanId) * 0.66),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        // 3) Collect alerts -> findings (scan_type = web).
        // NOTE: ZAP reports a CWE (a weakness CLASS, e.g. CWE-79 "XSS") and a risk level —
        // NOT a CVE and NOT a CVSS score. Web flaws live in the tenant's own code, so they
        // have no published CVE and cannot be looked up in KEV/EPSS. We therefore store the
        // CWE in its own column, leave cve NULL, and derive a numeric severity from the risk
        // level so the risk engine can still prioritise these findings.
        $alerts = $zap->alerts($scan->target);
        foreach ($alerts as $a) {
            $threat = $this->mapRisk($a['risk'] ?? '');
            $severity = $this->severityFromThreat($threat);

            DB::table('findings')->insert([
                'id' => (string) Str::uuid(),
                'scan_id' => $scan->id,
                'tenant_id' => $scan->tenant_id,
                'scan_type' => 'web',
                'name' => $a['alert'] ?? $a['name'] ?? 'Unknown',
                'host' => parse_url($a['url'] ?? $scan->target, PHP_URL_HOST),
                'url' => $a['url'] ?? null,          // NEW: full URL, e.g. http://host/vulnerabilities/sqli/?id=1
                'param' => $a['param'] ?? null,      // NEW: the injected parameter, e.g. "id"
                'port' => null,
                'cve' => null,                       // ZAP findings have no CVE
                'cwe' => $a['cweid'] ?? null,        // the weakness class (CWE)
                'severity' => $severity,             // derived from ZAP's risk level
                'threat' => $threat,
                'description' => mb_substr(($a['description'] ?? '') . ' ' . ($a['solution'] ?? ''), 0, 2000),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Score the findings (deterministic risk engine: severity + exposure for web findings).
        \App\Support\FindingScorer::scoreScan($scan->id);

        DB::table('scans')->where('id', $scan->id)->update([
            'status' => 'done', 'progress' => 100, 'finished_at' => now(), 'updated_at' => now(),
        ]);
        Audit::log($scan->tenant_id, 'system', 'webscan.completed', 'success', 'zap', ['target' => $scan->target, 'findings' => count($alerts)]);

        $admin = DB::table('tenant_users')->where('tenant_id', $scan->tenant_id)->first();
        if ($admin) {
            \Illuminate\Support\Facades\Mail::to($admin->email)->send(new \App\Mail\ScanReport($scan->id));
        } else {
            \Illuminate\Support\Facades\Log::warning("Web scan {$scan->id} completed but tenant {$scan->tenant_id} has no admin user to notify");
            Audit::log($scan->tenant_id, 'system', 'webscan.no_recipient', 'failure', 'zap', ['scan' => $scan->id]);
        }
        // Contexts persist in ZAP and hold a decrypted credential for as long
        // as they exist.
        if ($ctxName) {
            try { $zap->removeContext($ctxName); } catch (\Throwable $e) {}
        }
    }

    // ZAP risk levels -> your threat levels.
    private function mapRisk(string $risk): string
    {
        return match (strtolower($risk)) {
            'high' => 'High',
            'medium' => 'Medium',
            'low' => 'Low',
            default => 'Log',
        };
    }

    // Web findings have no CVSS, so derive a comparable numeric severity from the risk level.
    // This lets the risk engine prioritise web findings (severity + exposure) instead of
    // flattening every one of them to "Informational".
    private function severityFromThreat(string $threat): float
    {
        return match ($threat) {
            'High' => 7.5,
            'Medium' => 5.0,
            'Low' => 3.0,
            default => 0.0,      // 'Log' / informational
        };
    }
}