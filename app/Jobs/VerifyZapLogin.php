<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

/**
 * Establish whether a stored web login actually works.
 *
 * Two checks, in this order:
 *
 *   1. DIRECT — authenticate as the user and confirm the logged-in indicator
 *      appears in the response. If the login fails, this fails, whatever else
 *      happens afterwards.
 *
 *   2. CORROBORATING — crawl anonymously, crawl authenticated, compare which
 *      page paths only the authenticated crawl reached.
 *
 * The direct check exists because breadth alone is not proof. A profile aimed
 * at an address ZAP could not reach crawled four of ZAP's own error pages,
 * cleared a threshold of three new paths, and was reported to the tenant as a
 * working login. Telling someone their scan is authenticated when it is not is
 * worse than telling them nothing: they will trust a clean report produced by
 * an anonymous crawler.
 */
class VerifyZapLogin implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries = 1;

    public function __construct(public string $profileId) {}

    public function handle(): void
    {
        $p = DB::table('zap_auth_profiles')->where('id', $this->profileId)->first();
        if (!$p) return;

        $z = app(\App\Clients\ZapClient::class);
        $ctxName = 'obaits-' . $p->tenant_id . '-' . $p->id;

        try {
            if (!$z->ensureAuthScript()) {
                $this->finish($p, 'unknown', null, 'login_script_unavailable');
                return;
            }

            $host = parse_url($p->target_url, PHP_URL_HOST);
            if (!$host) {
                $this->finish($p, 'unknown', null, 'target_url_invalid');
                return;
            }

            // Scope the context to this host only, on any port or scheme.
            $ctx = $z->createContext($ctxName, '\Qhttp\E.*\Q' . $host . '\E.*');
            if (!$ctx) {
                $this->finish($p, 'unknown', null, 'context_failed');
                return;
            }

            $z->configureLogin(
                $ctx,
                $p->login_url,
                (string) $p->token_name,
                $p->username_field,
                $p->password_field,
                $p->logged_in_regex,
                (string) $p->logged_out_regex,
                (string) $p->extra_post_data
            );

            $uid = $z->createUser(
                $ctx,
                'obaits-scan-user',
                $p->username,
                Crypt::decryptString($p->password)
            );

            if (!$uid) {
                $this->finish($p, 'unknown', null, 'user_failed');
                return;
            }

            // ---- 1. Direct check -------------------------------------------
            $loggedIn = $z->loginSucceeds($ctx, $uid, $p->login_url, $p->logged_in_regex);

            if ($loggedIn === false) {
                // Definitive: ZAP authenticated and the logged-in text never
                // appeared. No amount of crawling changes this.
                $this->finish($p, 'failed', 0, 'logged_in_indicator_not_found');
                return;
            }

            // ---- 2. Corroborating crawl comparison -------------------------
            $r = $z->verifiedSpiderAsUser($ctx, $uid, $p->target_url, 600);

            $result = match ($r['authenticated']) {
                true    => 'authenticated',
                false   => 'failed',
                default => 'unknown',
            };

            // The direct check could not confirm the login, and the crawl found
            // little. Report honestly rather than resolving the doubt in our
            // own favour.
            if ($loggedIn === null && $result === 'authenticated' && ($r['new_urls'] ?? 0) < 20) {
                $result = 'unknown';
                $r['reason'] = 'login_unconfirmed_and_few_new_pages';
            }

            $this->finish(
                $p, $result,
                $r['new_urls'] ?? null,
                $r['reason'] ?? '',
                $r['sample'] ?? [],
                $r['anon_urls'] ?? null
            );

        } catch (\Throwable $e) {
            $this->finish($p, 'unknown', null, 'error');
        } finally {
            // Contexts accumulate in ZAP and hold a decrypted credential in
            // memory for as long as they exist.
            try { $z->removeContext($ctxName); } catch (\Throwable $e) {}
        }
    }

    private function finish(
        object $p,
        string $result,
        ?int $newPaths,
        string $reason,
        array $sample = [],
        ?int $anonPaths = null
    ): void {
        DB::table('zap_auth_profiles')->where('id', $this->profileId)->update([
            'last_verified_at'   => now(),
            'last_verify_result' => $result,
            'last_verify_reason' => $reason,
            'last_new_paths'     => $newPaths,
            'last_anon_paths'    => $anonPaths,
            'last_sample'        => json_encode(array_slice($sample, 0, 12)),
            'updated_at'         => now(),
        ]);

        \App\Support\Audit::log($p->tenant_id, 'system', 'zap.login.verified', $result, 'zap', [
            'profile' => $p->name, 'target' => $p->target_url,
            'new_paths' => $newPaths, 'reason' => $reason,
        ]);
    }
}