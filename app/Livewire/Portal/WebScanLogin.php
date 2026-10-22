<?php

namespace App\Livewire\Portal;

use Livewire\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class WebScanLogin extends Component
{
    public string $tenantId;

    public string $name = '';
    public string $domainId = '';
    public string $scheme = 'https';
    public string $port = '';
    public string $targetPath = '/';
    public string $loginPath = '/login';
    public string $preset = 'laravel';
    public string $tokenName = '_token';
    public string $usernameField = 'email';
    public string $passwordField = 'password';
    public string $extraPostData = '';
    public string $loggedInRegex = 'Logout';
    public string $loggedOutRegex = '';
    public string $username = '';
    public string $password = '';

    public ?string $editing = null;
    public string $notice = '';

    // Filling these in from framework knowledge is kinder than asking a
    // non-specialist to read their own login form's HTML.
    public array $presets = [
        'laravel'   => ['label' => 'Laravel',   'token' => '_token',              'user' => 'email',    'pass' => 'password', 'extra' => ''],
        'django'    => ['label' => 'Django',    'token' => 'csrfmiddlewaretoken', 'user' => 'username', 'pass' => 'password', 'extra' => ''],
        'rails'     => ['label' => 'Rails',     'token' => 'authenticity_token',  'user' => 'user[email]', 'pass' => 'user[password]', 'extra' => ''],
        'wordpress' => ['label' => 'WordPress', 'token' => '',                    'user' => 'log',      'pass' => 'pwd',      'extra' => 'wp-submit=Log+In'],
        'other'     => ['label' => 'Other',     'token' => '',                    'user' => 'username', 'pass' => 'password', 'extra' => ''],
    ];

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { return redirect('/login'); }
    }

    public function updatedPreset($v)
    {
        if (!isset($this->presets[$v])) return;
        $p = $this->presets[$v];
        $this->tokenName     = $p['token'];
        $this->usernameField = $p['user'];
        $this->passwordField = $p['pass'];
        $this->extraPostData = $p['extra'];
    }

    public function save()
    {
        $this->notice = '';

        // A tenant may only scan a domain they have PROVEN they control.
        // Unauthorised scanning is a criminal offence in most jurisdictions and
        // it is this platform's IP address making the requests.
        $domain = DB::table('tenant_domains')
            ->where('id', $this->domainId)
            ->where('tenant_id', $this->tenantId)     // ISOLATION
            ->where('verified', true)
            ->first();

        if (!$domain) {
            $this->notice = __('Choose a verified domain. You can only scan sites you have proven you control.');
            return;
        }

        if (trim($this->name) === '' || trim($this->username) === '') {
            $this->notice = __('A name and a username are required.');
            return;
        }

        if (trim($this->loggedInRegex) === '') {
            $this->notice = __('The logged-in indicator is required — without it we cannot tell whether the login worked.');
            return;
        }

        $scheme = in_array($this->scheme, ['http', 'https'], true) ? $this->scheme : 'https';
        $port   = trim($this->port);

        if ($port !== '' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
            $this->notice = __('The port must be a number between 1 and 65535.');
            return;
        }

        // The verified HOST is the constraint. Scheme and port are the tenant's
        // to choose — they do not widen which site is being scanned.
        $base = $scheme . '://' . $domain->domain . ($port !== '' ? ':' . $port : '');
        $targetUrl = $base . '/' . ltrim($this->targetPath, '/');
        $loginUrl  = $base . '/' . ltrim($this->loginPath, '/');

        $row = [
            'tenant_id'       => $this->tenantId,
            'name'            => trim($this->name),
            'target_url'      => $targetUrl,
            'scheme'          => $scheme,
            'port'            => $port !== '' ? (int) $port : null,
            'login_url'       => $loginUrl,
            'token_name'      => trim($this->tokenName) ?: null,
            'username_field'  => trim($this->usernameField),
            'password_field'  => trim($this->passwordField),
            'extra_post_data' => trim($this->extraPostData) ?: null,
            'logged_in_regex' => trim($this->loggedInRegex),
            'logged_out_regex'=> trim($this->loggedOutRegex) ?: null,
            'username'        => trim($this->username),
            'updated_at'      => now(),
        ];

        // Only overwrite the stored password when a new one was typed, so
        // editing other fields does not require re-entering it.
        if ($this->password !== '') {
            $row['password'] = Crypt::encryptString($this->password);
        }

        if ($this->editing) {
            DB::table('zap_auth_profiles')
                ->where('id', $this->editing)->where('tenant_id', $this->tenantId)->update($row);
        } else {
            if ($this->password === '') {
                $this->notice = __('A password is required.');
                return;
            }
            $row['id'] = (string) Str::uuid();
            $row['created_at'] = now();
            DB::table('zap_auth_profiles')->insert($row);
        }

        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'zap.login.saved', 'success', 'zap',
            ['name' => $row['name'], 'target' => $targetUrl]);

        $this->reset(['name','targetPath','loginPath','username','password','editing','port']);
        $this->notice = __('Saved. Use “Test login” to confirm it actually works.');
    }

    public function edit(string $id)
    {
        $p = DB::table('zap_auth_profiles')
            ->where('id', $id)->where('tenant_id', $this->tenantId)->first();
        if (!$p) return;

        $this->editing        = $p->id;
        $this->name           = $p->name;
        $this->scheme = $p->scheme;
        $this->port   = $p->port ? (string) $p->port : '';
        $this->tokenName      = (string) $p->token_name;
        $this->usernameField  = $p->username_field;
        $this->passwordField  = $p->password_field;
        $this->extraPostData  = (string) $p->extra_post_data;
        $this->loggedInRegex  = $p->logged_in_regex;
        $this->loggedOutRegex = (string) $p->logged_out_regex;
        $this->username       = $p->username;
        $this->password       = '';        // never sent back to the browser
        $this->preset         = 'other';
        $this->notice         = __('Editing. Leave the password blank to keep the existing one.');
    }

    public function testLogin(string $id)
    {
        $p = DB::table('zap_auth_profiles')
            ->where('id', $id)->where('tenant_id', $this->tenantId)->first();
        if (!$p) return;

        DB::table('zap_auth_profiles')->where('id', $id)
            ->update(['last_verify_result' => 'testing', 'updated_at' => now()]);

        \App\Jobs\VerifyZapLogin::dispatch($id);
        $this->notice = __('Testing the login. This takes a few minutes — the page will update.');
    }

    public function delete(string $id)
    {
        DB::table('zap_auth_profiles')
            ->where('id', $id)->where('tenant_id', $this->tenantId)->delete();
        $this->notice = __('Deleted.');
    }

    /**
     * Plain-language explanation of a verdict.
     *
     * A result a tenant cannot act on is only half useful. Each reason maps to
     * what it means and what to change — the most common cause of a failed
     * login is the "logged-in text" not matching what the site actually shows.
     */
    public function explain(?string $reason): array
    {
        return match ($reason) {
            'logged_in_indicator_not_found' => [
                'what' => __('We logged in, but the text you told us to look for never appeared.'),
                'fix'  => __('Log in to the site yourself and find a word that appears ONLY when logged in — usually “Logout” or “Sign out” — and use exactly that. Also check the username and password are correct.'),
            ],
            'no_new_pages_reached_login_probably_failed' => [
                'what' => __('The scan saw nothing that a logged-out visitor could not already see.'),
                'fix'  => __('Check the login page address, and the field names under Technical details. They must match the form on your site exactly.'),
            ],
            'only_a_few_new_pages_cannot_confirm', 'login_unconfirmed_and_few_new_pages' => [
                'what' => __('A small number of extra pages were reached — not enough to be sure the login worked.'),
                'fix'  => __('Check the “Start scanning from” address points at a page inside the logged-in area, then test again.'),
            ],
            'authenticated_crawl_returned_nothing' => [
                'what' => __('The scanner could not reach your site at all.'),
                'fix'  => __('Check the address, scheme and port, and that the site is reachable from the internet.'),
            ],
            'baseline_crawl_failed', 'authenticated_crawl_failed' => [
                'what' => __('The scan could not be started.'),
                'fix'  => __('Check the address is correct and the site is online, then try again.'),
            ],
            'baseline_crawl_timeout', 'authenticated_crawl_timeout' => [
                'what' => __('The scan took too long and was stopped.'),
                'fix'  => __('Large sites can exceed the time limit. Try a more specific starting page.'),
            ],
            'target_url_invalid' => [
                'what' => __('The site address could not be understood.'),
                'fix'  => __('Re-select the domain and check the paths.'),
            ],
            'login_script_unavailable', 'context_failed', 'user_failed', 'error' => [
                'what' => __('Something went wrong on our side, not yours.'),
                'fix'  => __('Please try again. If it keeps happening, contact support.'),
            ],
            default => ['what' => '', 'fix' => ''],
        };
    }

    public function render()
    {
        return view('livewire.portal.web-scan-login', [
            'domains'  => DB::table('tenant_domains')
                            ->where('tenant_id', $this->tenantId)
                            ->where('verified', true)->orderBy('domain')->get(),
            'anyDomains' => DB::table('tenant_domains')
                            ->where('tenant_id', $this->tenantId)->exists(),
            'profiles' => DB::table('zap_auth_profiles')
                            ->where('tenant_id', $this->tenantId)
                            ->orderBy('name')->get(),
        ])->layout('components.layouts.portal');
    }
}