<?php
namespace App\Clients;

use Illuminate\Support\Facades\Http;

class ZapClient
{
    private function base(): string
    {
        return rtrim(config('services.zap.base_url'), '/');
    }

    private function key(): string
    {
        return config('services.zap.api_key');
    }

    private function call(string $path, array $params = []): array
    {
        $params['apikey'] = $this->key();
        $res = Http::withHeaders(['Host' => 'zap'])
            ->withOptions(['proxy' => ''])
            ->timeout(120)
            ->get($this->base() . $path, $params);
        return $res->json() ?? [];
    }

    public function version(): string
    {
        return $this->call('/JSON/core/view/version/')['version'] ?? 'unknown';
    }

    public function spider(string $targetUrl): string
    {
        return (string) ($this->call('/JSON/spider/action/scan/', ['url' => $targetUrl])['scan'] ?? '');
    }

    public function spiderStatus(string $scanId): int
    {
        return (int) ($this->call('/JSON/spider/view/status/', ['scanId' => $scanId])['status'] ?? 0);
    }

    public function activeScan(string $targetUrl): string
    {
        return (string) ($this->call('/JSON/ascan/action/scan/', ['url' => $targetUrl])['scan'] ?? '');
    }

    public function activeScanStatus(string $scanId): int
    {
        return (int) ($this->call('/JSON/ascan/view/status/', ['scanId' => $scanId])['status'] ?? 0);
    }

    public function alerts(string $targetUrl): array
    {
        $data = $this->call('/JSON/core/view/alerts/', ['baseurl' => $targetUrl]);
        return $data['alerts'] ?? [];
    }

    // ---------------------------------------------------------------
    // Authenticated scanning
    //
    // Plain form-based authentication is NOT sufficient for most real
    // applications: any login form carrying an anti-CSRF token rejects ZAP's
    // POST, and ZAP then scans on unauthenticated while reporting success.
    // Measured against DVWA: form-based found 89 URLs of which ZERO were
    // behind the login. The script below found 35 authenticated-only pages.
    //
    // So we always use scriptBasedAuthentication with a token-aware script.
    // ---------------------------------------------------------------

    private const AUTH_SCRIPT       = 'obaits-csrf-login';
    private const AUTH_SCRIPT_PATH  = '/home/zap/obaits-scripts/obaits-csrf-login.js';

    /** Load the login script into ZAP if it is not already there. Idempotent. */
    public function ensureAuthScript(): bool
    {
        foreach ($this->call('/JSON/script/view/listScripts/')['listScripts'] ?? [] as $s) {
            if (($s['name'] ?? null) === self::AUTH_SCRIPT) {
                return ($s['error'] ?? 'true') === 'false';
            }
        }

        $this->call('/JSON/script/action/load/', [
            'scriptName'   => self::AUTH_SCRIPT,
            'scriptType'   => 'authentication',
            'scriptEngine' => 'ECMAScript : Graal.js',
            'fileName'     => self::AUTH_SCRIPT_PATH,
        ]);

        foreach ($this->call('/JSON/script/view/listScripts/')['listScripts'] ?? [] as $s) {
            if (($s['name'] ?? null) === self::AUTH_SCRIPT) {
                return ($s['error'] ?? 'true') === 'false';
            }
        }
        return false;   // verify by reading back — a "Result: OK" is not evidence
    }

    /**
     * Create (or recreate) a scan context scoped to one site.
     * Contexts accumulate in ZAP, so we remove any prior one of the same name.
     */
    public function createContext(string $name, string $urlRegex): ?string
    {
        $this->call('/JSON/context/action/removeContext/', ['contextName' => $name]);

        $id = $this->call('/JSON/context/action/newContext/', ['contextName' => $name])['contextId'] ?? null;
        if (!$id) return null;

        $this->call('/JSON/context/action/includeInContext/', [
            'contextName' => $name, 'regex' => $urlRegex,
        ]);

        // Never let the crawler click Logout — it would end its own session and
        // continue unauthenticated.
        $this->call('/JSON/context/action/excludeFromContext/', [
            'contextName' => $name, 'regex' => '.*(logout|signout|sign-out).*',
        ]);

        return $id;
    }

    /**
     * Configure token-aware form login for a context.
     *
     * $tokenName is the hidden CSRF field: _token (Laravel),
     * csrfmiddlewaretoken (Django), authenticity_token (Rails), user_token (DVWA).
     *
     * $loggedInRegex is not optional in practice. Without it ZAP cannot tell
     * whether its session is still valid, and a scan that quietly drops to
     * anonymous produces a clean report that means nothing.
     */
    public function configureLogin(
        string $contextId,
        string $loginUrl,
        string $tokenName,
        string $usernameField,
        string $passwordField,
        string $loggedInRegex,
        string $loggedOutRegex = '',
        string $extraPostData = ''
    ): bool {
        $cfg = 'scriptName=' . self::AUTH_SCRIPT
             . '&loginUrl=' . urlencode($loginUrl)
             . '&tokenName=' . urlencode($tokenName)
             . '&usernameField=' . urlencode($usernameField)
             . '&passwordField=' . urlencode($passwordField)
             . '&extraPostData=' . urlencode($extraPostData);

        $ok = ($this->call('/JSON/authentication/action/setAuthenticationMethod/', [
            'contextId'              => $contextId,
            'authMethodName'         => 'scriptBasedAuthentication',
            'authMethodConfigParams' => $cfg,
        ])['Result'] ?? '') === 'OK';

        $this->call('/JSON/sessionManagement/action/setSessionManagementMethod/', [
            'contextId' => $contextId, 'methodName' => 'cookieBasedSessionManagement',
        ]);

        $this->call('/JSON/authentication/action/setLoggedInIndicator/', [
            'contextId' => $contextId, 'loggedInIndicatorRegex' => $loggedInRegex,
        ]);

        if ($loggedOutRegex !== '') {
            $this->call('/JSON/authentication/action/setLoggedOutIndicator/', [
                'contextId' => $contextId, 'loggedOutIndicatorRegex' => $loggedOutRegex,
            ]);
        }

        return $ok;
    }

    /** Create an enabled user in a context. Credentials are never persisted here. */
    public function createUser(string $contextId, string $name, string $username, string $password): ?string
    {
        $uid = $this->call('/JSON/users/action/newUser/', [
            'contextId' => $contextId, 'name' => $name,
        ])['userId'] ?? null;

        if ($uid === null) return null;

        $this->call('/JSON/users/action/setAuthenticationCredentials/', [
            'contextId' => $contextId,
            'userId'    => $uid,
            'authCredentialsConfigParams' => 'username=' . urlencode($username)
                                           . '&password=' . urlencode($password),
        ]);

        $this->call('/JSON/users/action/setUserEnabled/', [
            'contextId' => $contextId, 'userId' => $uid, 'enabled' => 'true',
        ]);

        return $uid;
    }

    /** Spider as a logged-in user. NOTE: returns its id under 'scanAsUser', not 'scan'. */
    public function spiderAsUser(string $contextId, string $userId, string $url): ?string
    {
        $r = $this->call('/JSON/spider/action/scanAsUser/', [
            'contextId' => $contextId, 'userId' => $userId,
            'url' => $url, 'recurse' => 'true',
        ]);
        return $r['scanAsUser'] ?? ($r['scan'] ?? null);
    }

    public function spiderResults(string $scanId): array
    {
        return $this->call('/JSON/spider/view/results/', ['scanId' => $scanId])['results'] ?? [];
    }

    /** Active scan as a logged-in user. Same key quirk as the spider. */
    public function activeScanAsUser(string $contextId, string $userId, string $url): ?string
    {
        $r = $this->call('/JSON/ascan/action/scanAsUser/', [
            'contextId' => $contextId, 'userId' => $userId,
            'url' => $url, 'recurse' => 'true',
        ]);
        return $r['scanAsUser'] ?? ($r['scan'] ?? null);
    }

    public function removeContext(string $name): void
    {
        $this->call('/JSON/context/action/removeContext/', ['contextName' => $name]);
    }

    /**
     * Run an authenticated crawl and PROVE the login worked.
     *
     * This exists because a failed login is invisible. Configured with plain
     * form-based auth against DVWA, ZAP reported a completed scan, crawled 89
     * URLs, and reached ZERO pages behind the login — every API call along the
     * way returned "OK". The only way to know is to compare what an anonymous
     * crawler can see against what the logged-in one saw.
     *
     * Returns:
     *   authenticated => bool|null   true / false / null when it cannot tell
     *   anon_urls, auth_urls, new_urls
     *   sample                       URLs only the logged-in crawl reached
     *   reason                       why it concluded what it did
     */
    public function verifiedSpiderAsUser(
        string $contextId,
        string $userId,
        string $url,
        int $maxWaitSeconds = 300
    ): array {
        $out = [
            'authenticated' => null, 'anon_urls' => 0, 'auth_urls' => 0,
            'new_urls' => 0, 'sample' => [], 'reason' => '',
        ];

        // 1. Anonymous baseline.
        $anonId = $this->spider($url);
        if (!$anonId) { $out['reason'] = 'baseline_crawl_failed'; return $out; }
        if (!$this->awaitSpider($anonId, $maxWaitSeconds)) {
            $out['reason'] = 'baseline_crawl_timeout'; return $out;
        }
        $anon = $this->spiderResults($anonId);

        // 2. Authenticated crawl.
        $authId = $this->spiderAsUser($contextId, $userId, $url);
        if (!$authId) { $out['reason'] = 'authenticated_crawl_failed'; return $out; }
        if (!$this->awaitSpider($authId, $maxWaitSeconds)) {
            $out['reason'] = 'authenticated_crawl_timeout'; return $out;
        }
        $auth = $this->spiderResults($authId);

        $out['anon_urls'] = count($anon);
        $out['auth_urls'] = count($auth);

        // 3. What did logging in actually reach?
        //
        // Compare on PATH, not full URL. A crawler generates query strings of its
        // own (?C=D;O=A directory sort links, ?name=ZAP test params), so counting
        // raw URLs makes a failed login look like a success — which is exactly how
        // the form-based attempt scored 89 against a baseline of 7 while reaching
        // nothing.
        $paths = fn (array $urls) => array_unique(array_map(
            fn ($u) => rtrim((string) parse_url($u, PHP_URL_PATH), '/'), $urls
        ));

        $newPaths = array_values(array_diff($paths($auth), $paths($anon)));
        $out['new_urls'] = count($newPaths);

        $out['sample'] = array_values(array_slice(array_filter(
            $auth,
            fn ($u) => in_array(rtrim((string) parse_url($u, PHP_URL_PATH), '/'), $newPaths, true)
        ), 0, 15));

        // Breadth alone is not proof. Four crawled URLs on an unreachable target
        // once cleared a threshold of three and was reported as success.
        if ($out['auth_urls'] === 0) {
            $out['authenticated'] = null;
            $out['reason'] = 'authenticated_crawl_returned_nothing';
        } elseif ($out['new_urls'] >= 10) {
            $out['authenticated'] = true;
            $out['reason'] = 'reached_many_pages_anonymous_crawl_could_not';
        } elseif ($out['new_urls'] >= 3) {
            $out['authenticated'] = null;
            $out['reason'] = 'only_a_few_new_pages_cannot_confirm';
        } else {
            $out['authenticated'] = false;
            $out['reason'] = 'no_new_pages_reached_login_probably_failed';
        }

        return $out;
    }

    /** Poll a spider to completion. False on timeout — never assume it finished. */
    private function awaitSpider(string $scanId, int $maxSeconds): bool
    {
        $waited = 0;
        while ($waited < $maxSeconds) {
            sleep(3); $waited += 3;
            if ($this->spiderStatus($scanId) >= 100) return true;
        }
        return false;
    }

    /**
     * Ask ZAP to authenticate as this user and confirm the logged-in indicator
     * appears in the response.
     *
     * This is the DIRECT check. Crawl comparison is corroboration, not proof:
     * a scan pointed at an unreachable target crawled four of ZAP's own error
     * pages and was reported as a successful login. Breadth alone proves nothing.
     *
     * true = the indicator was found, false = it was not, null = cannot tell.
     */
    public function loginSucceeds(string $contextId, string $userId, string $checkUrl, string $loggedInRegex): ?bool
    {
        // Force a fresh authentication rather than reusing any existing session.
        $this->call('/JSON/httpSessions/action/removeSession/', []);   // best-effort

        $r = $this->call('/JSON/users/action/authenticateAsUser/', [
            'contextId' => $contextId, 'userId' => $userId,
        ]);
        if (($r['Result'] ?? '') !== 'OK') return null;

        // Send one request as the user and inspect what comes back.
        $send = $this->call('/JSON/ascan/action/scanAsUser/', [
            'contextId' => $contextId, 'userId' => $userId,
            'url' => $checkUrl, 'recurse' => 'false',
        ]);

        // Read the most recent messages ZAP exchanged and look for the indicator.
        $msgs = $this->call('/JSON/core/view/messages/', ['count' => '25'])['messages'] ?? [];
        if (!$msgs) return null;

        $needle = preg_replace('/\\\\Q|\\\\E/', '', $loggedInRegex);
        if ($needle === '') return null;

        foreach (array_reverse($msgs) as $m) {
            $body = ($m['responseBody'] ?? '') . ($m['responseHeader'] ?? '');
            if ($body !== '' && stripos($body, $needle) !== false) return true;
        }
        return false;
    }
}