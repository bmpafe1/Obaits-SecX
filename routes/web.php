<?php

use Illuminate\Support\Facades\Route;
use App\Livewire\LandingPage;
use App\Livewire\VerifyEmail;
use App\Livewire\VerifyDomain;
use App\Livewire\Portal\Domains;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use App\Livewire\Portal\Dashboard;
use App\Livewire\Portal\Team;
use App\Livewire\Portal\Scans;
use App\Livewire\Portal\ScanFindings;


Route::get('/', LandingPage::class);
Route::get('/register', \App\Livewire\Register::class);

// ISO download. Stubbed: the appliance is not built yet, but the landing page
// links here from the top menu and the deployment section, and a 404 on a menu
// link is worse than a page that says plainly what is coming.
Route::view('/download', 'download');
Route::get('/verify-email', VerifyEmail::class);
Route::get('/verify-domain', VerifyDomain::class);
Route::get('/welcome', fn() => view('welcome-tenant'));
Route::get('/suspended', fn () => view('suspended'));
Route::get('/help/mfa', \App\Livewire\MfaHelpPublic::class);
Route::get('/portal/domains', Domains::class)->middleware('tenant');
Route::get('/portal/ssl-certificates', \App\Livewire\Portal\SslCertificates::class)->middleware('tenant');
Route::get('/portal/api-keys', \App\Livewire\Portal\ApiKeys::class)->middleware('tenant');
Route::get('/login', function () {
    return Socialite::driver('keycloak')->redirect();
});
 
Route::get('/auth/callback', function (Request $request) {
    // A GET without an authorization code (bookmarks, post-action redirects from
    // Keycloak, crawlers) must not 500 — send it to login instead.
    if (!$request->has('code')) {
        return redirect('/login');
    }
    try {
        $kcUser = Socialite::driver('keycloak')->user();
    } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
        return redirect('/login');
    }
    // Match the Keycloak user to a tenant_users row by email.
    $tu = \Illuminate\Support\Facades\DB::table('tenant_users')->where('email', $kcUser->getEmail())->first();
    if (!$tu) {
        abort(403, 'No tenant account for this user.');
    }

    // Refuse login if the tenant is suspended — suspension must be real, not cosmetic.
    $tenantStatus = \Illuminate\Support\Facades\DB::table('tenants')
        ->where('id', $tu->tenant_id)->value('status');
    if ($tenantStatus === 'suspended') {
        return redirect('/suspended');
    }

    // Store identity in session (a full app would use a User model + Auth::login).
    session([
        'auth_email' => $kcUser->getEmail(),
        'auth_name' => $kcUser->getName(),
        'tenant_id' => $tu->tenant_id,
        'user_role' => $tu->role,
        'tenant_user_id' => $tu->id,
    ]);

    // Record the login. NOTHING tracked this before, so "how often does each
    // person actually use the platform" could not be answered at all -- and
    // unlike most gaps this one cannot be backfilled, so every day without it
    // was engagement history permanently lost.
    //
    // Deliberately records NO IP address and NO user agent. Neither answers any
    // question we actually have, and collecting them would turn a usage measure
    // into a surveillance record of a customer's staff.
    //
    // Wrapped: a telemetry failure must never prevent someone logging in.
    try {
        \Illuminate\Support\Facades\DB::table('user_logins')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $tu->tenant_id,
            'user_id' => $tu->id,
            'email' => $kcUser->getEmail(),
            'logged_in_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('tenant_users')->where('id', $tu->id)->update([
            'last_login_at' => now(),
            'last_seen_at' => now(),
            'login_count' => \Illuminate\Support\Facades\DB::raw('login_count + 1'),
            'updated_at' => now(),
        ]);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning('Could not record login for ' . $kcUser->getEmail() . ': ' . $e->getMessage());
    }

    return redirect('/dashboard');
});

Route::get('/license-expired', fn () => view('license-expired'));

Route::get('/logout', function () {
    session()->flush();
    $base = rtrim(config('services.keycloak.base_url'), '/');
    $realm = config('services.keycloak.realm');
    $redirect = urlencode(url('/'));
    $clientId = env('KEYCLOAK_DASHBOARD_CLIENT_ID');
    return redirect("{$base}/realms/{$realm}/protocol/openid-connect/logout?post_logout_redirect_uri={$redirect}&client_id={$clientId}");
});

Route::get('/dashboard', Dashboard::class)->middleware('tenant');
Route::get('/portal/team', Team::class)->middleware('tenant');
Route::get('/portal/scans', Scans::class)->middleware('tenant');
Route::get('/portal/scans/{scanId}/findings', ScanFindings::class)->middleware('tenant');
Route::get('/portal/software', \App\Livewire\Portal\SoftwareCatalog::class)->middleware('tenant');
Route::get('/portal/device-groups', \App\Livewire\Portal\DeviceGroups::class)->middleware('tenant');
Route::get('/portal/group-policies', \App\Livewire\Portal\GroupPolicies::class)->middleware('tenant');
Route::get('/portal/evidence', \App\Livewire\Portal\Evidence::class)->middleware('tenant');
Route::get('/portal/assets', \App\Livewire\Portal\Assets::class)->middleware('tenant');
Route::get('/portal/posture', \App\Livewire\Portal\Posture::class)->middleware('tenant');
Route::get('/portal/access', \App\Livewire\Portal\Access::class)->middleware('tenant');
Route::get('/portal/organisation', \App\Livewire\Portal\Organisation::class)->middleware('tenant');
Route::get('/portal/mail-settings', \App\Livewire\Portal\MailSettings::class)->middleware('tenant');
Route::get('/portal/governance', \App\Livewire\Portal\Governance::class)->middleware('tenant');
Route::get('/portal/document/{docKey}', \App\Livewire\Portal\DocumentBuilder::class)->middleware('tenant');
Route::get('/portal/compliance', \App\Livewire\Portal\Compliance::class)->middleware('tenant');
Route::get('/portal/document/{docKey}', \App\Livewire\Portal\DocumentBuilder::class)->middleware('tenant');
Route::get('/portal/waf', \App\Livewire\Portal\Waf::class)->middleware('tenant');
Route::get('/portal/helpdesk', \App\Livewire\Portal\Helpdesk::class)->middleware('tenant');
Route::get('/portal/helpdesk/{ticketId}', \App\Livewire\Portal\Ticket::class)->middleware('tenant');
Route::get('/portal/help/mfa', \App\Livewire\Portal\MfaHelp::class)->middleware('tenant');
Route::get('/portal/alerts', \App\Livewire\Portal\SecurityAlerts::class)->middleware('tenant');
// --- On-premise installations -----------------------------------------------
//
// A customer running this platform from the ISO on their own hardware still
// needs the centrally-curated Marketplace and Training catalogues, and their
// marketplace requests still need to reach us -- referrals are the product, and
// we want to see them regardless of how a customer is deployed.
//
// SELF-REGISTRATION, no approval step. The ISO is AGPL and meant to be freely
// installable, so requiring a token issued in advance would work against that.
// The catalogue is public information anyway, and the only thing an
// installation can POST is a marketplace request -- which lands in our inbox
// with us in copy, so anything abusive is visible immediately. Suspension is
// the control that matters.

// Register once, on first run. Returns a token used for everything after.
Route::post('/api/installation/register', function (\Illuminate\Http\Request $r) {
    $data = $r->validate([
        'organisation_name' => 'nullable|string|max:200',
        'contact_email'     => 'nullable|email|max:255',
        'country'           => 'nullable|string|size:2',
        'version'           => 'nullable|string|max:40',
    ]);

    $token = \Illuminate\Support\Str::random(64);

    $id = (string) \Illuminate\Support\Str::uuid();
    \Illuminate\Support\Facades\DB::table('installations')->insert([
        'id' => $id,
        'token_hash' => hash('sha256', $token),
        'organisation_name' => $data['organisation_name'] ?? null,
        'contact_email' => $data['contact_email'] ?? null,
        'country' => isset($data['country']) ? strtoupper($data['country']) : null,
        'version' => $data['version'] ?? null,
        'status' => 'active',
        'registered_at' => now(),
        'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Shown ONCE. We store only the hash, so it cannot be recovered.
    return response()->json(['installation_id' => $id, 'token' => $token], 201);
})->middleware('throttle:10,60');

// The catalogue. Read-only, and the same content every installation sees.
Route::get('/api/installation/catalogue', function (\Illuminate\Http\Request $r) {
    $install = \App\Support\InstallationAuth::resolve($r);
    if (!$install) { return response()->json(['error' => 'unauthorised'], 401); }

    \Illuminate\Support\Facades\DB::table('installations')->where('id', $install->id)
        ->update(['last_catalogue_sync_at' => now(), 'version' => $r->query('version', $install->version)]);

    return response()->json([
        'generated_at' => now()->toIso8601String(),
        'providers' => \Illuminate\Support\Facades\DB::table('marketplace_providers')
            ->where('lifecycle', 'active')->get(),
        'services' => \Illuminate\Support\Facades\DB::table('marketplace_services')
            ->where('status', 'published')->get(),
        'trainings' => \Illuminate\Support\Facades\DB::table('trainings')
            ->where('status', 'published')->get(),
    ]);
})->middleware('throttle:60,60');

// A marketplace request raised on an on-premise installation, forwarded here so
// the provider is notified and we see the referral.
Route::post('/api/installation/marketplace-request', function (\Illuminate\Http\Request $r) {
    $install = \App\Support\InstallationAuth::resolve($r);
    if (!$install) { return response()->json(['error' => 'unauthorised'], 401); }

    return app(\App\Support\Marketplace\ForwardedRequest::class)->accept($install, $r);
})->middleware('throttle:30,60');

// --- Marketplace ------------------------------------------------------------
// V1 is a qualified service-request and lead-referral system, NOT a payment
// marketplace. Providers handle quotation, contracting, payment and delivery
// directly; the platform records discovery, qualification, routing and status.
//
// BROWSING is open to every tenant user -- discovery should not be gated.
// SUBMITTING a request requires raise_marketplace_request (platform-admin
// only), because it starts a commercial conversation on the organisation's
// behalf.
// The one-time business-details step. NOT behind the business-details
// middleware itself, or it would redirect to itself forever.
Route::get('/portal/business-details', \App\Livewire\Portal\BusinessDetails::class)->middleware('tenant');

// --- Trainings --------------------------------------------------------------
// A curated catalogue of third-party courses, operator-maintained for the same
// reason as the Marketplace: these are shown under our brand, and one
// misleading or dead listing damages trust in the rest.
//
// One component, three routes -- the category is a parameter rather than three
// near-identical components.
Route::get('/portal/trainings/awareness', \App\Livewire\Portal\Trainings::class)
    ->defaults('category', 'security_awareness')->middleware('tenant');
Route::get('/portal/trainings/certifications', \App\Livewire\Portal\Trainings::class)
    ->defaults('category', 'professional_certification')->middleware('tenant');
Route::get('/portal/trainings/other', \App\Livewire\Portal\Trainings::class)
    ->defaults('category', 'other')->middleware('tenant');

Route::get('/portal/marketplace/providers', \App\Livewire\Portal\MarketplaceProviders::class)->middleware('tenant');
Route::get('/portal/marketplace/services', \App\Livewire\Portal\MarketplaceServices::class)->middleware('tenant');
Route::get('/portal/marketplace/services/{id}', \App\Livewire\Portal\MarketplaceServiceDetail::class)->middleware('tenant');
Route::get('/portal/marketplace/requests', \App\Livewire\Portal\MarketplaceRequests::class)->middleware('tenant');

// STUBS 2026-08-25, added with the navigation restructure. Both are
// first-class areas in the target information architecture but have no
// implementation yet. Stubbed rather than omitted: adding a top-level menu area
// later disrupts the menu again, and a link that 404s is worse than one that
// says plainly it is coming.
//
// Reports: no reporting exists today at all.
// AI Assistant: the assistant EXISTS but only as the floating chat widget on
// every page (App\Livewire\BrianChat). This will become its own conversation
// page; until then the widget remains the way to use it.
Route::get('/portal/reports', \App\Livewire\Portal\Reports::class)->middleware('tenant');

Route::view('/portal/ai-assistant', 'portal.coming-soon', [
    'title' => 'AI Assistant',
    'body'  => 'A dedicated assistant page is coming. In the meantime, the assistant is available from the chat button in the bottom-right corner of every page.',
])->middleware('tenant');

Route::get('/portal/billing', \App\Livewire\Portal\Billing::class)->middleware('tenant');

// A printable invoice. Scoped to the asking tenant -- an invoice id from
// another organisation must 404, not render someone else's billing.
Route::get('/portal/invoice/{id}', function (string $id) {
    $tenantId = session('tenant_id');

    $invoice = \Illuminate\Support\Facades\DB::table('invoices')
        ->where('id', $id)->where('tenant_id', $tenantId)->first();
    abort_if(!$invoice, 404);

    return view('invoices.show', [
        'invoice' => $invoice,
        'lines'   => json_decode($invoice->line_items ?? '[]', true) ?: [],
        'tenant'  => \Illuminate\Support\Facades\DB::table('tenants')->where('id', $tenantId)->first(),
        'profile' => \Illuminate\Support\Facades\DB::table('tenant_profiles')->where('tenant_id', $tenantId)->first(),
        'seller'  => config('invoicing.seller'),
        'currency'=> config('invoicing.currency', 'USD'),
        'terms'   => config('invoicing.payment_terms'),
    ]);
})->middleware('tenant')->name('invoice.show');

// Subscription checkout. NOT Livewire, matching BackupCheckoutController: card
// fields must never round-trip through a websocket, and a payment form should
// be a plain POST.
Route::middleware('tenant')->group(function () {
    Route::get('/portal/checkout', [\App\Http\Controllers\SubscriptionCheckoutController::class, 'show'])
        ->name('subscription-checkout.show');
    Route::post('/portal/checkout', [\App\Http\Controllers\SubscriptionCheckoutController::class, 'charge'])
        ->name('subscription-checkout.charge');
    // Where the customer lands after being sent away to authorise -- 3DS, a
    // mobile money push, or any other redirect flow. NOT the checkout form:
    // this fetches the charge and finalises it, because a customer returning
    // from their bank to a payment form would have paid and received nothing.
    Route::get('/portal/checkout/return/{reference}', [\App\Http\Controllers\SubscriptionCheckoutController::class, 'returnFromRedirect'])
        ->name('subscription-checkout.return');

    Route::get('/portal/checkout/auth/{chargeId}/{stepType}', [\App\Http\Controllers\SubscriptionCheckoutController::class, 'showAuthStep'])
        ->name('subscription-checkout.auth-step');
    Route::post('/portal/checkout/auth/{chargeId}/{stepType}', [\App\Http\Controllers\SubscriptionCheckoutController::class, 'submitAuthStep'])
        ->name('subscription-checkout.submit-auth-step');
});

Route::get('/portal/account', \App\Livewire\Portal\Account::class)->middleware('tenant');
Route::get('/portal/threats', \App\Livewire\Portal\ThreatDetection::class)->middleware('tenant');
Route::get('/portal/cases', \App\Livewire\Portal\Cases::class)->middleware('tenant');
Route::get('/portal/cctv', \App\Livewire\Portal\Cctv::class)->middleware('tenant');
Route::get('/portal/health', \App\Livewire\Portal\AssetHealth::class)->middleware('tenant');
Route::get('/portal/live-query', \App\Livewire\Portal\LiveQuery::class)->middleware('tenant');
Route::get('/portal/vulnerable-software', \App\Livewire\Portal\VulnerableSoftware::class)->middleware('tenant');
Route::get('/portal/enroll', \App\Livewire\Portal\Enroll::class)->middleware('tenant');
Route::get('/portal/backups', \App\Livewire\Portal\Backups::class)->middleware('tenant');
Route::get('/operator/login', \App\Livewire\Operator\Login::class);
Route::get('/operator/mfa', \App\Livewire\Operator\MfaChallenge::class);
Route::get('/operator/logout', function () {
    \App\Support\OperatorAudit::log('operator.logout');
    session()->forget(['operator_id', 'operator_email', 'operator_mfa_ok']);
    return redirect('/operator/login');
});
 
Route::get('/operator', \App\Livewire\Operator\Dashboard::class)->middleware('operator');
Route::get('/operator/tenant/{tenantId}', \App\Livewire\Operator\TenantDetail::class)->middleware('operator');
Route::get('/operator/tenant/{tenantId}/data', \App\Livewire\Operator\TenantData::class)->middleware('operator');
Route::get('/operator/audit', \App\Livewire\Operator\AuditLog::class)->middleware('operator');
Route::get('/operator/api-keys', \App\Livewire\Operator\ApiKeys::class)->middleware('operator');

// Marketplace curation. Providers never log in -- everything about them is
// entered and maintained here, which is what keeps the catalogue trustworthy.
Route::get('/operator/analytics', \App\Livewire\Operator\Analytics::class)->middleware('operator');
Route::get('/operator/trainings', \App\Livewire\Operator\Trainings::class)->middleware('operator');
Route::get('/operator/marketplace/requests', \App\Livewire\Operator\MarketplaceRequests::class)->middleware('operator');
Route::get('/operator/marketplace/providers', \App\Livewire\Operator\MarketplaceProviders::class)->middleware('operator');
Route::get('/operator/marketplace/providers/{id}/services', \App\Livewire\Operator\MarketplaceProviderServices::class)->middleware('operator');
Route::get('/enroll/install.sh', fn () =>
    response(view('enroll.install-sh'))->header('Content-Type', 'text/plain'));

// REAL FEATURE 2026-08-15: baseline platform protection (Spamhaus DROP
// threat-intel blocking), independent of any tenant-configured Group
// Policy -- see the view's own comments for the full design.
// REAL CHANGE 2026-08-23: this was previously served straight from disk,
// justified by "zero per-request variation". That is no longer true: the
// portal URL is now injected per request, so the script is a Blade view.
// Still public and still carries no secret (generic ipset/iptables logic
// plus a portal URL the device already knows), matching install.sh's own
// public nature. The device's enrollment secret is NEVER templated in --
// the script reads it from a root-owned 0600 file on the device itself.
Route::get('/enroll/threat-intel-refresh.sh', fn () =>
    response(view('enroll.threat-intel-refresh-sh', ['portalUrl' => rtrim(config('app.url'), '/')]))
        ->header('Content-Type', 'text/plain'));

// Windows equivalent of the Linux baseline threat-intel refresh --
// served the same way, direct from the real file on disk.
// RETROFIT ONLY, 2026-08-23: installs the threat-intel timer on devices that
// enrolled before the timer became part of enrollment itself. Kept deliberately
// tiny to push via Fleet -- script_contents has a real, confirmed 10,000-char
// limit, so the Fleet payload is a two-line fetch-and-run and the real work
// lives here. See the view's own header for the outstanding cleanup.
// NO .sh EXTENSION, DELIBERATELY. CRS rule 920440 ("URL file extension is
// restricted by policy") blocks any URL ending in .sh with a plain 403 from
// ModSecurity, before Laravel is ever reached -- confirmed live 2026-08-23 on
// this exact route. /enroll/threat-intel-refresh.sh works only because of a
// hand-written exception rule (id:1000021) in /etc/nginx/modsec/main.conf,
// which is NOT tracked in this repo and exists only on the live VPS.
//
// Dropping the extension sidesteps the rule entirely: no WAF exception to
// write, nothing weakened, and nothing manual to remember when the server is
// rebuilt or an on-prem/.iso instance is stood up. Prefer this for any future
// script-serving route.
Route::get('/enroll/timer-setup', fn () =>
    response(view('enroll.timer-setup', ['portalUrl' => rtrim(config('app.url'), '/')]))
        ->header('Content-Type', 'text/plain'));

Route::get('/enroll/threat-intel-refresh.ps1', fn () =>
    response(file_get_contents(base_path('ops/platform/threat-intel-refresh.ps1')))->header('Content-Type', 'text/plain'));

// REAL FEATURE 2026-08-23: per-device threat-intel refresh health.
//
// WHY THIS EXISTS: threat-intel refresh moved off the Fleet script
// channel onto a device-side timer (see the migration's own comment for
// the full, real backlog story). That removed the portal's only previous
// way of knowing a refresh actually happened -- Fleet's own activity log.
//
// AUTHENTICATED, unlike the neighbouring /enroll/threat-intel-source-counts
// route, and deliberately so: that route carries a non-sensitive COUNT,
// while this one carries a HEALTH CLAIM. An unauthenticated health report
// would let anyone suppress an alert for a device that is genuinely dead,
// which would defeat this feature's entire purpose. Real, explicit
// tradeoff confirmed with Brian 2026-08-23: this uses the long-lived
// per-tenant OBAITS_ENROLL_SECRET the device already holds, rather than
// the one-time-token pattern used by /enroll/policy-result -- a recurring
// timer has no dispatch moment at which to mint a one-time token. Blast
// radius of a leaked device secret is confined to falsifying its OWN
// tenant's health rows: no command execution, no data exposure. And a
// dead timer is still caught regardless, because the alert's real signal
// is ABSENCE of a report (a database-side check), which no forged report
// can prevent.
Route::post('/enroll/threat-intel-health', function (\Illuminate\Http\Request $r) {
    // Authenticated with the DEVICE credential already issued at enrollment
    // (/enroll/device-credential, stored root-owned 0600 at
    // /etc/obaits/device-credential since 2026-08-06). REAL NOTE: this route
    // is the FIRST consumer of that credential -- it has been provisioned on
    // every device for weeks but never actually verified anywhere until now.
    //
    // Chosen over the tenant-wide enrollment secret deliberately: a leaked
    // device credential can only falsify ONE device's health rows rather than
    // a whole tenant's, revocation is already modelled (revoked_at), and both
    // tenant AND hostname come from the credential's own database row -- the
    // client supplies neither, so neither can be spoofed.
    $cred = (string) $r->input('device_credential');
    $status = (string) $r->input('status');
    $platform = (string) $r->input('platform', '');
    $error = (string) $r->input('error', '');

    if (!$cred) abort(422, 'device_credential is required.');
    if (!in_array($status, ['success', 'failure'], true)) {
        abort(422, "status must be 'success' or 'failure'.");
    }

    $device = \Illuminate\Support\Facades\DB::table('device_credentials')
        ->where('secret_hash', hash('sha256', $cred))
        ->whereNull('revoked_at')
        ->first();

    if (!$device) {
        // Worth recording: this is the first route in the platform that
        // verifies a device credential at all, so a failure here is either a
        // genuinely misconfigured device or someone probing the endpoint.
        \Log::warning('threat-intel-health: unknown or revoked device credential', ['ip' => $r->ip()]);
        abort(403, 'Unknown or revoked device credential.');
    }

    $tenantId = $device->tenant_id;

    // Hostname comes from the credential row, NOT from the client -- so it
    // matches device_credentials exactly, by construction. NOTE it is the
    // name the enrollment script sent ($(hostname) / $env:COMPUTERNAME),
    // which on Windows is the SHORT name, while Fleet stores the FQDN. Do
    // not join these health rows to Fleet host records on hostname alone.
    $host = $device->hostname;

    $existing = \Illuminate\Support\Facades\DB::table('threat_intel_device_reports')
        ->where('tenant_id', $tenantId)->where('hostname', $host)->first();

    $ok = $status === 'success';

    $values = [
        'tenant_id' => $tenantId,
        'hostname' => $host,
        'platform' => in_array($platform, ['linux', 'windows'], true) ? $platform : ($existing->platform ?? null),
        'last_run_at' => now(),
        'last_status' => $status,
        'last_error' => $ok ? null : mb_substr($error, 0, 2000),
        'consecutive_failures' => $ok ? 0 : (int) (($existing->consecutive_failures ?? 0) + 1),
        'updated_at' => now(),
    ];

    // last_success_at is the AUTHORITATIVE alerting signal -- only ever
    // advanced by a real success, never touched by a failure report.
    if ($ok) $values['last_success_at'] = now();

    if ($existing) {
        \Illuminate\Support\Facades\DB::table('threat_intel_device_reports')
            ->where('id', $existing->id)->update($values);
    } else {
        $values['id'] = (string) \Illuminate\Support\Str::uuid();
        $values['created_at'] = now();
        \Illuminate\Support\Facades\DB::table('threat_intel_device_reports')->insert($values);
    }

    return response('ok', 200)->header('Content-Type', 'text/plain');
})->middleware('throttle:60,1');

// REAL FEATURE 2026-08-18: CIS Baseline via Wazuh SCA, audit-only (real,
// explicit safety decision -- never auto-remediate individual CIS
// checks, since they can genuinely break real production functionality
// if auto-applied). Wazuh's own Manager already ships the COMPLETE,
// real, current CIS ruleset locally (confirmed directly: every relevant
// file already present under /var/ossec/ruleset/sca/, just shipped
// .disabled by default) -- served live from there rather than
// vendoring copies into our own repo (avoids drift, avoids the exact
// class of transient external-fetch failure already hit tonight with
// GitHub's real 429 rate limiting) or fetching from GitHub per device.
// Restricted to an explicit allow-list of real, confirmed-present
// filenames -- never accepts an arbitrary path from the request.
Route::get('/enroll/sca-policy/{name}', function (string $name) {
    $allowed = [
        'cis_ubuntu22-04.yml',
        'cis_ubuntu2404_adapted.yml',
        'cis_win2019.yml',
        'cis_win2016.yml',
        'cis_win2022.yml',
        'cis_debian10.yml',
        'cis_debian11.yml',
        'cis_debian12.yml',
    ];
    if (!in_array($name, $allowed, true)) {
        abort(404, 'Unknown SCA policy.');
    }

    $rulesetDir = '/var/ossec/ruleset/sca/';
    $realPath = file_exists($rulesetDir . $name) ? $rulesetDir . $name : $rulesetDir . $name . '.disabled';
    if (!file_exists($realPath)) {
        abort(404, 'Policy file not present on this Wazuh Manager.');
    }

    return response(file_get_contents($realPath))->header('Content-Type', 'text/plain');
})->middleware('throttle:20,1');



// REAL FEATURE 2026-08-16, requested directly: each device reports its
// own real, current per-source count after every threat-intel refresh
// cycle, so the portal's Threat Intelligence Sources tab shows genuine,
// live data rather than a stale or guessed number. Lightweight,
// deliberately: this reports a non-sensitive count (not tenant data),
// validated against a known source-name allow-list and rate-limited,
// without the heavier per-tenant secret auth used elsewhere -- there is
// no natural tenant secret to check here, since this protection is
// baseline/platform-wide, not tenant-scoped.
Route::post('/enroll/threat-intel-source-counts', function (\Illuminate\Http\Request $r) {
    $source = (string) $r->input('source');
    $count = (int) $r->input('count');
    $hostname = (string) $r->input('hostname');

    $allowed = ['spamhaus_drop', 'urlhaus', 'hagezi_tif', 'threatfox'];
    if (!in_array($source, $allowed, true)) {
        abort(422, 'Unknown source.');
    }
    if ($count < 0) {
        abort(422, 'count must be a real, non-negative integer.');
    }

    // REAL FIX: the original draft tried to preserve created_at via a
    // COALESCE inside updateOrInsert's single shared values array --
    // fragile, and created_at genuinely doesn't exist yet on a real
    // first insert to coalesce against. This table only ever stores the
    // MOST RECENT report per source (no history), so created_at and
    // updated_at being identical on every write is harmless and, if
    // anything, more honest -- it always reflects when this row was
    // last genuinely touched.
    \Illuminate\Support\Facades\DB::table('threat_intel_source_reports')->updateOrInsert(
        ['source' => $source],
        [
            'count' => $count,
            'reported_by_hostname' => $hostname ?: null,
            'reported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );

    return response('ok', 200)->header('Content-Type', 'text/plain');
})->middleware('throttle:60,1');


/**
 * Windows enrollment script. Added 2026-07-23: this route never existed —
 * install-ps1.blade.php has been present (and edited twice this session for
 * the Wazuh password fix) but was never wired to a URL, so Windows
 * enrollment has always 404'd, in every prior session, undiscovered until
 * the first real Windows enrollment test.
 */
Route::get('/enroll/install.ps1', fn () =>
    response(view('enroll.install-ps1'))->header('Content-Type', 'text/plain'));

/**
 * Phase 4.1 Stage 2: audit-enablement scripts, run transparently at the end
 * of enrollment (not a separate manual step). Hosted here rather than
 * fetched by the client from a third party — the Windows script in
 * particular used to pull Sysmon live from sysinternals.com/GitHub at every
 * single install, a real reliability/supply-chain concern given this
 * project's own prior experience with silently-failing live downloads.
 */
Route::get('/enroll/audit-linux.sh', fn () =>
    response(view('enroll.audit-linux'))->header('Content-Type', 'text/plain'));

Route::get('/enroll/audit-windows.ps1', fn () =>
    response(view('enroll.audit-windows'))->header('Content-Type', 'text/plain'));

/**
 * Sysmon binary + config, hosted from Obaits' own storage rather than
 * fetched live from sysinternals.com/GitHub at every single Windows
 * enrollment. Fetched ONCE during setup (2026-07-27), checksums verified
 * independently from two separate machines before being trusted. Served
 * as plain downloads — these are public, unmodified upstream files, no
 * auth needed, same reasoning as install.sh itself being unauthenticated.
 */
Route::get('/enroll/assets/sysmon64.exe', fn () =>
    response()->download(storage_path('app/enroll-assets/sysmon64.exe'), 'Sysmon64.exe'));

Route::get('/enroll/assets/sysmonconfig.xml', fn () =>
    response()->file(storage_path('app/enroll-assets/sysmonconfig.xml'), ['Content-Type' => 'text/xml']));
/**
 * Serves a tenant's pre-built Fleet agent package to the install script.
 *
 * The script runs on a machine with no browser session, so it authenticates with
 * the enroll secret it already carries. That goes in a HEADER, never a query
 * string — query strings land in access logs, proxy logs and browser history.
 */
Route::get('/enroll/package', function (\Illuminate\Http\Request $r) {
    $secret = $r->header('X-Obaits-Enroll-Secret');
    if (!$secret) abort(401);
    $artifact = \Illuminate\Support\Facades\DB::table('tenant_artifacts')
        ->where('tool', 'fleet')->where('artifact_type', 'enroll_secret')
        ->where('external_id', $secret)->first();
    if (!$artifact) abort(403);
    $type = preg_replace('/[^a-z]/', '', (string) $r->query('type', 'deb'));

    $hits = glob(storage_path('app/private/agent-packages/' . $artifact->tenant_id)
                 . '/fleet-osquery*.' . $type);

    // Build-on-miss: a freshly provisioned tenant has no package until one is
    // built. Rather than 404 on the customer's first "protect a computer", queue
    // the build (the queue runs as a user that can run fleetctl; the web user
    // cannot — it has no writable HOME) and tell the caller to retry shortly.
    // Fix A's chgrp is what makes the queue-built file readable here.
    // Observed 2026-07-22: mbonjiltd's first enrollment 404'd for this reason.
    if (!$hits) {
        $tenant = \Illuminate\Support\Facades\DB::table('tenants')
            ->where('id', $artifact->tenant_id)->first();
        if ($tenant) {
            \App\Jobs\BuildAgentPackageJob::dispatch($tenant->slug, $type);
        }
        \App\Support\Audit::log($artifact->tenant_id, 'enroll-script', 'agent.package.build_queued', 'success', 'fleet',
            ['type' => $type, 'ip' => $r->ip()]);
        return response(
            "Your agent package is being prepared. Please run the same command again in about a minute.\n",
            503
        )->header('Content-Type', 'text/plain')->header('Retry-After', '60');
    }

    \App\Support\Audit::log($artifact->tenant_id, 'enroll-script', 'agent.package.downloaded',
        'success', 'fleet', ['type' => $type, 'ip' => $r->ip()]);
    return response()->download($hits[0]);
})->middleware('throttle:20,1');
/**
 * Real, self-hosted mirror for Windows install-time dependencies (restic,
 * Tailscale, Wazuh agent, Zabbix agent) -- Brian's own real, important
 * concern: previously fetched live from an external URL at enrollment
 * time, meaning a temporary outage or upstream URL change could break a
 * real enrollment. Same real auth model as /enroll/package.
 *
 * Serves an already-cached real file if present (the common case, kept
 * fresh by the real, proactive obaits:sync-package-mirror scheduled
 * command); falls back to a real, synchronous fetch-and-cache on a genuine
 * cache miss (a brand-new tool, or a request arriving before the first
 * real sync has run) rather than failing outright.
 */
Route::get('/enroll/mirror/{tool}', function (\Illuminate\Http\Request $r, string $tool) {
    if (!in_array($tool, \App\Support\PackageMirror::TOOLS, true)) abort(404);

    $secret = $r->header('X-Obaits-Enroll-Secret');
    if (!$secret) abort(401);
    $artifact = \Illuminate\Support\Facades\DB::table('tenant_artifacts')
        ->where('tool', 'fleet')->where('artifact_type', 'enroll_secret')
        ->where('external_id', $secret)->first();
    if (!$artifact) abort(403);

    $wazuhVersion = in_array($tool, ['wazuh-agent', 'wazuh-agent-linux'], true)
        ? preg_replace('/[^0-9.\-]/', '', (string) $r->query('version'))
        : null;

    // REAL FIX 2026-08-11: zabbix-agent-linux was hardcoded to
    // ubuntu24.04 everywhere -- confirmed live, this broke the Zabbix
    // agent install outright on a real Ubuntu 22.04.5 machine. os_id/
    // os_version are supplied by install.sh (detected from the real
    // device's own /etc/os-release), sanitised to a safe character set
    // here before ever reaching a filename or an outbound URL.
    $osId = $tool === 'zabbix-agent-linux'
        ? preg_replace('/[^a-z]/', '', strtolower((string) $r->query('os_id')))
        : null;
    $osVersion = $tool === 'zabbix-agent-linux'
        ? preg_replace('/[^0-9.]/', '', (string) $r->query('os_version'))
        : null;

    $path = \App\Support\PackageMirror::cachedPath($tool, $wazuhVersion, $osId, $osVersion);
    if (!$path) {
        $path = \App\Support\PackageMirror::fetchAndCache($tool, $wazuhVersion, $osId, $osVersion);
    }
    if (!$path) {
        \App\Support\Audit::log($artifact->tenant_id, 'enroll-script', 'mirror.package.unavailable', 'failure', $tool,
            ['ip' => $r->ip()]);
        $path = \App\Support\PackageMirror::fetchAndCache($tool, $wazuhVersion);
    }

    if (!$path) {
        \App\Support\Audit::log($artifact->tenant_id, 'enroll-script', 'mirror.package.unavailable', 'failure', $tool,
            ['ip' => $r->ip()]);
        abort(502, 'Package mirror could not serve this tool right now.');
    }

    \App\Support\Audit::log($artifact->tenant_id, 'enroll-script', 'mirror.package.downloaded', 'success', $tool,
        ['ip' => $r->ip()]);
    return response()->download($path);
})->middleware('throttle:20,1');

/**
 * Real, tenant-scoped delivery of a real, uploaded software installer to
 * an enrolled device -- Phase B of software push automation. Same real
 * auth model as /enroll/package. Never serves anything whose real,
 * confirmed scan_status is not genuinely 'clean' -- an unscanned or
 * infected file must never reach a real device, no matter what.
 */
Route::get('/enroll/software-installer', function (\Illuminate\Http\Request $r) {
    // REAL, IMPORTANT DESIGN CHOICE: a Fleet Script-triggered run (unlike
    // the original enrollment) has no access to the tenant's real
    // OBAITS_ENROLL_SECRET at all -- matches the same real, single-use
    // token pattern already proven for /enroll/role-credential.
    $token = $r->query('token');
    if (!$token) abort(401);

    $row = \Illuminate\Support\Facades\DB::table('software_install_tokens')
        ->where('token', $token)->first();
    if (!$row) abort(403, 'Invalid token.');
    if ($row->used_at) abort(410, 'Token already used.');
    if (\Illuminate\Support\Carbon::parse($row->expires_at)->isPast()) abort(410, 'Token expired.');

    \Illuminate\Support\Facades\DB::table('software_install_tokens')->where('id', $row->id)->update([
        'used_at' => now(), 'updated_at' => now(),
    ]);

    $file = \Illuminate\Support\Facades\DB::table('software_files')
        ->where('id', $row->software_file_id)
        ->where('tenant_id', $row->tenant_id)
        ->where('scan_status', 'clean')
        ->first();
    if (!$file) abort(404);

    // REAL BUG FOUND before this ever reached a real device: confirmed
    // directly on the real server -- SoftwareCatalog.php's own real
    // upload logic stores files under storage/app/private/, matching
    // this project's own established private-storage convention (same
    // as agent-packages), but $file->path is relative to that private/
    // root, not app/ directly. The 700 permission on this directory is
    // a real, deliberate security boundary (only www-data can read it),
    // not itself a bug -- confirmed our own web route runs as www-data.
    $fullPath = storage_path('app/private/' . $file->path);
    if (!is_file($fullPath)) abort(404);

    \App\Support\Audit::log($row->tenant_id, 'enroll-script', 'software.installer.downloaded', 'success', null,
        ['file' => $file->original_name, 'ip' => $r->ip()]);

    return response()->download($fullPath, $file->original_name);
})->middleware('throttle:20,1');

/**
 * Backup credentials for a device already running the Fleet/restic agent.
 *
 * Auth: identical model to /enroll/package — the enroll secret the device
 * already holds locally (in /etc/default/orbit, written at enrollment). No
 * new credential is introduced. The connection is resolved by TENANT, not
 * by a caller-supplied id, so there is nothing here for a compromised script
 * to enumerate — only "give me the one connection this tenant has."
 *
 * Returns a restic-consumable env file (RESTIC_REPOSITORY / RESTIC_PASSWORD /
 * AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY), NEVER embedded in script_contents
 * — Fleet stores script_contents verbatim in its own database and echoes it
 * back via /scripts/results, so a secret placed there is a second, uncontrolled
 * copy of something we otherwise encrypt carefully in tenant_backup_connections.
 *
 * The restic repository password is generated on FIRST fetch and persisted —
 * every subsequent fetch (a re-run, a new device for the same tenant) gets
 * the same password, which is required: restic repositories are keyed to one
 * password for their whole lifetime.
 */
Route::get('/enroll/backup-credentials', function (\Illuminate\Http\Request $r) {
    $secret = $r->header('X-Obaits-Enroll-Secret');
    if (!$secret) abort(401);

    $artifact = \Illuminate\Support\Facades\DB::table('tenant_artifacts')
        ->where('tool', 'fleet')->where('artifact_type', 'enroll_secret')
        ->where('external_id', $secret)->first();
    if (!$artifact) abort(403);

    $conn = \App\Support\Backup\BackupConnections::activeFor($artifact->tenant_id, 'idrive_e2');
    if (!$conn) abort(404, 'No active backup connection for this tenant.');

    $creds = \App\Support\Backup\BackupConnections::credentials($artifact->tenant_id, $conn->id);

    if (empty($creds['restic_password'])) {
        $creds['restic_password'] = bin2hex(random_bytes(32));
        \App\Support\Backup\BackupConnections::merge($artifact->tenant_id, $conn->id, [
            'restic_password' => $creds['restic_password'],
        ]);
    }

    $endpoint = $creds['tenant_endpoint'] ?? $creds['user_dn'] ?? null;
    if (!$endpoint) abort(500, 'Backup connection has no usable endpoint.');

    \App\Support\Audit::log($artifact->tenant_id, 'backup-script', 'backup.credentials_fetched',
        'success', 'idrive_e2', ['ip' => $r->ip()]);

    $body = "RESTIC_REPOSITORY=s3:https://{$endpoint}/obaits-backup\n"
          . "RESTIC_PASSWORD={$creds['restic_password']}\n"
          . "AWS_ACCESS_KEY_ID={$creds['access_key']}\n"
          . "AWS_SECRET_ACCESS_KEY={$creds['secret_key']}\n";

    return response($body)->header('Content-Type', 'text/plain');
})->middleware('throttle:20,1');
/**
 * Headscale VPN credentials for a device already running the Fleet agent.
 *
 * Auth: identical model to /enroll/backup-credentials — the enroll secret
 * the device already holds locally. The instance is resolved by TENANT,
 * never by a caller-supplied id.
 *
 * Returns the tenant's Headscale login-server URL and a REUSABLE pre-auth
 * key, plain text, consumed directly by `tailscale up --login-server=...
 * --authkey=...` in install.sh/install.ps1. The key is reusable and
 * long-lived (1 year, see ProvisionHeadscaleInstance) — every device this
 * tenant ever enrolls uses the same one, mirroring how one Fleet enroll
 * secret already covers every device for a tenant today.
 */
/**
 * Issues (or rotates) this device's long-lived policy credential.
 *
 * Auth mirrors every other /enroll/* endpoint: the enroll secret proves
 * this is a real device belonging to this tenant. Keyed by hostname since
 * neither install script knows its own fleet_host_id at the point it
 * runs -- resolved later by AutoAssignDeviceGroups.
 *
 * Rotates rather than reuses on every call: a re-enrollment gets a FRESH
 * secret and the old one stops working immediately. The plaintext secret
 * is returned exactly once, in this response -- only its SHA-256 hash is
 * ever stored.
 */
Route::post('/enroll/device-credential', function (\Illuminate\Http\Request $r) {
    $secret = $r->header('X-Obaits-Enroll-Secret');
    if (!$secret) abort(401);

    $artifact = \Illuminate\Support\Facades\DB::table('tenant_artifacts')
        ->where('tool', 'fleet')->where('artifact_type', 'enroll_secret')
        ->where('external_id', $secret)->first();
    if (!$artifact) abort(403);

    $hostname = trim((string) $r->input('hostname'));
    if (!$hostname) abort(422, 'hostname is required.');

    $plaintext = bin2hex(random_bytes(32));
    $hash = hash('sha256', $plaintext);

    // Same updateOrInsert primary-key bug fixed twice earlier today
    // (SoftwarePush::record(), DeviceGroups::assignSoftware()) avoided
    // here from day one: 'id' must never sit in an updateOrInsert values
    // array, or every rotation silently overwrites the row's own PK.
    // MATCH ON THE SHORT NAME TOO. Windows sends its SHORT hostname at
    // enrolment while Fleet stores the FQDN, and the two forms have now
    // diverged NINE separate times in this codebase.
    //
    // REAL BUG FOUND 2026-08-31: an exact match meant PDC02 and
    // PDC02.NOFIA.LOCAL became TWO credentials for one machine. A device
    // rebuilt and reporting its name in the other form silently consumed a
    // second slot against the customer's limit -- and worse, a rotation test
    // run on 2026-08-29 was reported as proof that an existing device is never
    // refused, when it had in fact created a duplicate.
    //
    // Comparing the leading label of each makes both forms the same device.
    $short = strtolower(explode('.', $hostname)[0]);

    $existing = \Illuminate\Support\Facades\DB::table('device_credentials')
        ->where('tenant_id', $artifact->tenant_id)
        ->whereNull('revoked_at')
        ->get(['id', 'hostname'])
        ->first(fn ($c) => strtolower(explode('.', $c->hostname)[0]) === $short);

    if ($existing) {
        \Illuminate\Support\Facades\DB::table('device_credentials')
            ->where('tenant_id', $artifact->tenant_id)
            ->where('hostname', $hostname)
            ->where('id', '!=', $existing->id)
            ->whereNotNull('revoked_at')
            ->delete();

        \Illuminate\Support\Facades\DB::table('device_credentials')->where('id', $existing->id)->update([
            // Record the form the device actually reports now. A machine that
            // starts sending its FQDN should not keep a stale short name.
            //
            // REAL BUG 2026-08-31: there is a UNIQUE (tenant_id, hostname), and
            // revoking a credential leaves its hostname in place -- so
            // rewriting an active row to a name a revoked row still holds
            // collides and 500s. Clear any revoked row holding the target name
            // first: it is by definition the same machine, and a revoked
            // credential retaining a name it can no longer use serves nothing.
            'hostname' => $hostname,
            'secret_hash' => $hash,
            'issued_at' => now(),
            'revoked_at' => null,
            'updated_at' => now(),
        ]);
    } else {
        // ASSET LIMIT ENFORCEMENT.
        //
        // REAL GAP FOUND 2026-08-29: asset_limit appeared exactly ONCE in the
        // whole codebase -- where registration writes it. Nothing read it. A
        // trial tenant could enrol unlimited devices, and per-asset pricing had
        // nothing behind it. aicexperts had 6 devices against a limit of 2.
        //
        // Checked HERE and not on the install-script routes, because this is
        // the moment a device actually becomes one: the script can be fetched
        // and re-run freely, and failing at fetch would block re-enrolment of
        // machines that are already counted.
        //
        // Only in the else branch, deliberately. Re-issuing a credential to a
        // hostname that already exists is a rotation, not an addition, and must
        // never be refused -- a device rebuilt or restored has to be able to
        // come back.
        $limit = \Illuminate\Support\Facades\DB::table('subscriptions')
            ->where('tenant_id', $artifact->tenant_id)
            ->value('asset_limit');

        if ($limit !== null) {
            $inUse = \Illuminate\Support\Facades\DB::table('device_credentials')
                ->where('tenant_id', $artifact->tenant_id)
                ->whereNull('revoked_at')
                ->count();

            if ($inUse >= $limit) {
                \App\Support\Audit::log($artifact->tenant_id, 'enroll', 'device.refused_limit',
                    'failure', null, ['hostname' => $hostname, 'in_use' => $inUse, 'limit' => $limit]);

                // Read in a terminal, not a browser -- the enrolment script
                // shows this to whoever is standing at the machine. Say what
                // the limit is, what is used, and what to do about it.
                abort(response(
                    "Enrolment refused: this organisation is using {$inUse} of {$limit} device "
                    . "slots.\n"
                    . "Add capacity from Billing in the portal, then run this installer again.\n",
                    402
                ));
            }
        }

        \Illuminate\Support\Facades\DB::table('device_credentials')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $artifact->tenant_id,
            'hostname' => $hostname,
            'secret_hash' => $hash,
            'issued_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    \App\Support\Audit::log($artifact->tenant_id, 'enroll-script', 'device.credential_issued', 'success', null,
        ['hostname' => $hostname, 'ip' => $r->ip()]);

    return response($plaintext, 200)->header('Content-Type', 'text/plain');
})->middleware('throttle:20,1');


Route::get('/enroll/headscale-credentials', function (\Illuminate\Http\Request $r) {
    $secret = $r->header('X-Obaits-Enroll-Secret');
    if (!$secret) abort(401);

    $artifact = \Illuminate\Support\Facades\DB::table('tenant_artifacts')
        ->where('tool', 'fleet')->where('artifact_type', 'enroll_secret')
        ->where('external_id', $secret)->first();
    if (!$artifact) abort(403);

    $instance = \Illuminate\Support\Facades\DB::table('headscale_instances')
        ->where('tenant_id', $artifact->tenant_id)->where('status', 'active')->first();
    if (!$instance) abort(404, 'No active VPN instance for this tenant.');

    try {
        $key = \Illuminate\Support\Facades\Crypt::decryptString($instance->preauth_key_encrypted);
    } catch (\Throwable $e) {
        abort(500, 'Could not read VPN credentials for this tenant.');
    }

    \App\Support\Audit::log($artifact->tenant_id, 'enroll-script', 'headscale.credentials_fetched',
        'success', 'headscale', ['ip' => $r->ip()]);

    $body = "HEADSCALE_LOGIN_SERVER=https://vpn-{$instance->slug}." . config('platform.base_domain') . "\n"
          . "HEADSCALE_AUTH_KEY={$key}\n";

    return response($body)->header('Content-Type', 'text/plain');
})->middleware('throttle:20,1');
/**
 * Called by the install script ONLY when agent-auth reports a duplicate agent
 * name. A machine re-enrolling (re-image, restore) legitimately replaces its own
 * prior identity — but must never evict another tenant's agent, nor a DIFFERENT
 * live machine that happens to share a hostname.
 *
 * Auth: the enroll secret the script already carries (same as /enroll/package).
 * Scope: the agent is looked up by name WITHIN this tenant's group only.
 * Policy: replace a dead prior identity (disconnected/never_connected);
 *         refuse if an ACTIVE/pending agent already holds the name (409).
 */
Route::post('/enroll/wazuh-precheck', function (\Illuminate\Http\Request $r) {
    $secret = $r->header('X-Obaits-Enroll-Secret');
    if (!$secret) abort(401);

    $artifact = \Illuminate\Support\Facades\DB::table('tenant_artifacts')
        ->where('tool', 'fleet')->where('artifact_type', 'enroll_secret')
        ->where('external_id', $secret)->first();
    if (!$artifact) abort(403);

    $name = trim((string) $r->input('agent_name', ''));
    if ($name === '' || strlen($name) > 128) {
        abort(422, 'agent_name required');
    }

    $tenant = \Illuminate\Support\Facades\DB::table('tenants')
        ->where('id', $artifact->tenant_id)->first();
    if (!$tenant) abort(403);

    $group = 'tenant-' . $tenant->slug;
    $wazuh = app(\App\Clients\WazuhClient::class);

    try {
        $token = $wazuh->token();
        $existing = $wazuh->findAgentInGroup($token, $group, $name);
    } catch (\Throwable $e) {
        \App\Support\Audit::log($artifact->tenant_id, 'enroll-script', 'agent.wazuh_precheck', 'failure', 'wazuh',
            ['agent_name' => $name, 'error' => $e->getMessage()]);
        // Transient: tell the script to try the whole thing again shortly.
        return response("Could not verify with the monitoring server. Try again shortly.\n", 503)
            ->header('Content-Type', 'text/plain')->header('Retry-After', '30');
    }

    // Nothing there — clear to register.
    if ($existing === null) {
        return response("clear\n", 200)->header('Content-Type', 'text/plain');
    }

    $status = $existing['status'] ?? 'unknown';
    $replaceable = in_array($status, ['disconnected', 'never_connected'], true);

    if (!$replaceable) {
        \App\Support\Audit::log($artifact->tenant_id, 'enroll-script', 'agent.wazuh_precheck', 'refused', 'wazuh',
            ['agent_name' => $name, 'status' => $status]);
        return response(
            "A different active computer is already registered under the name \"{$name}\" "
            . "in your organization. Rename this computer and run the command again.\n",
            409
        )->header('Content-Type', 'text/plain');
    }

    // Dead prior identity — replace it.
    try {
        $wazuh->deleteAgentById($token, $existing['id']);
    } catch (\Throwable $e) {
        \App\Support\Audit::log($artifact->tenant_id, 'enroll-script', 'agent.wazuh_precheck', 'failure', 'wazuh',
            ['agent_name' => $name, 'status' => $status, 'error' => $e->getMessage()]);
        return response("Could not clear the old registration. Try again shortly.\n", 503)
            ->header('Content-Type', 'text/plain')->header('Retry-After', '30');
    }

    \App\Support\Audit::log($artifact->tenant_id, 'enroll-script', 'agent.wazuh_precheck', 'cleared', 'wazuh',
        ['agent_name' => $name, 'old_id' => $existing['id'], 'old_status' => $status]);
    return response("cleared\n", 200)->header('Content-Type', 'text/plain');
})->middleware('throttle:10,1');

/**
 * Fetches a tenant's stored database credential for a role-setup script,
 * authenticated by a SHORT-LIVED, SINGLE-USE token — never the device's
 * own enroll secret, and NEVER embedded as a permanent value in
 * script_contents itself. Fleet stores script_contents verbatim in its
 * own database (same documented concern as /enroll/backup-credentials),
 * so a real, reusable password baked into the script text would be a
 * second, uncontrolled copy of something we otherwise encrypt carefully.
 * The token is minted fresh by launchRoleSetup() the moment a role setup
 * actually runs, embedded in the script INSTEAD of any permanent secret,
 * consumed exactly once here, then invalidated — even if the script
 * output/contents leak later, this token is already dead.
 */
Route::post('/enroll/role-credential', function (\Illuminate\Http\Request $r) {
    $token = (string) $r->input('token');
    if (!$token) abort(401);

    $row = \Illuminate\Support\Facades\DB::table('role_credential_tokens')
        ->where('token', $token)->first();

    if (!$row) abort(403, 'Invalid token.');
    if ($row->used_at) abort(410, 'Token already used.');
    if (\Illuminate\Support\Carbon::parse($row->expires_at)->isPast()) abort(410, 'Token expired.');

    \Illuminate\Support\Facades\DB::table('role_credential_tokens')->where('id', $row->id)->update([
        'used_at' => now(), 'updated_at' => now(),
    ]);

    // REAL, SERIOUS GAP FIXED, TWICE: a credential lookup originally
    // matched only on (tenant_id, role_group) -- meaning two devices
    // running the same role always shared the SAME stored credential.
    // A first fix added a device-specific match with a "shared/
    // default" fallback -- but Brian correctly, directly identified
    // this fallback ITSELF as the real, remaining danger: a genuine
    // second device (confirmed directly: WinClient and the domain
    // controller are BOTH real members of the mssql-db Wazuh group
    // right now) would silently receive the FIRST device's credential
    // rather than being prompted for its own, distinct, correct one.
    // Real, complete fix: NO fallback at all. Every device must have
    // its own, real, explicitly-stored credential -- matching exactly
    // how real installs actually work (different accounts on
    // different machines).
    $cred = \Illuminate\Support\Facades\DB::table('tenant_role_credentials')
        ->where('tenant_id', $row->tenant_id)
        ->where('role_group', $row->role_group)
        ->where('fleet_host_id', $row->fleet_host_id)
        ->first();

    if (!$cred) abort(404, 'No credential stored for this device/role.');

    $password = \Illuminate\Support\Facades\Crypt::decryptString($cred->encrypted_password);

    \App\Support\Audit::log($row->tenant_id, 'role-setup-script', 'role_credential.fetched', 'success', 'wazuh', [
        'role_group' => $row->role_group,
    ]);

    return response()->json(['username' => $cred->username, 'password' => $password]);
})->middleware('throttle:20,1');

/**
 * Fetches the REAL, COMPLETE, unmodified role-setup script content for a
 * Fleet Script to execute at runtime. This is the fix for a real,
 * substantial class of problems discovered across a whole session of
 * Windows role automation: Fleet's OWN script_contents transport itself
 * (not PowerShell, not our own code) corrupts backtick escapes and
 * non-ASCII characters, and rejects anything over 10,000 characters --
 * a hard, real ceiling that was hit repeatedly as real scripts grew with
 * real fixes and their own documenting comments.
 *
 * The NEW approach: Fleet's own script_contents is now always a tiny,
 * generic, near-constant TRIGGER script (see launchRoleSetup()) that
 * fetches the REAL script from here via a normal HTTPS call and then
 * executes it. Since HTTP response bodies have none of Fleet's own
 * transport quirks, the real script file can be any length, use real
 * backticks/em-dashes freely, and grow with real documentation forever
 * without ever hitting Fleet's limit again.
 *
 * Authenticated the same way as /enroll/role-credential: a short-lived,
 * single-use token, minted fresh by launchRoleSetup() the moment a role
 * setup actually runs.
 */
Route::post('/enroll/role-script', function (\Illuminate\Http\Request $r) {
    $token = (string) $r->input('token');
    if (!$token) abort(401);

    $row = \Illuminate\Support\Facades\DB::table('role_script_tokens')
        ->where('token', $token)->first();

    if (!$row) abort(403, 'Invalid token.');
    if ($row->used_at) abort(410, 'Token already used.');
    if (\Illuminate\Support\Carbon::parse($row->expires_at)->isPast()) abort(410, 'Token expired.');

    \Illuminate\Support\Facades\DB::table('role_script_tokens')->where('id', $row->id)->update([
        'used_at' => now(), 'updated_at' => now(),
    ]);

    $ext = ($row->platform === 'windows') ? 'ps1' : 'sh';
    $scriptPath = base_path("ops/wazuh/role-setup-scripts/{$row->role_group}.{$ext}");

    if (!file_exists($scriptPath)) {
        abort(404, 'Script not found.');
    }

    \App\Support\Audit::log($row->tenant_id, 'role-setup-script', 'role_script.fetched', 'success', 'wazuh', [
        'role_group' => $row->role_group, 'platform' => $row->platform,
    ]);

    return response(file_get_contents($scriptPath), 200)->header('Content-Type', 'text/plain');
})->middleware('throttle:20,1');

/**
 * Fetches a policy check script -- same real trigger-fetch-execute
 * pattern already proven for role-setup scripts, for the same reason:
 * Fleet's own script_contents transport corrupts backticks/non-ASCII and
 * hard-caps at 10,000 characters. First slice: check scripts only, one
 * hardcoded location per policy (no marketplace/versioning storage yet).
 */
Route::post('/enroll/policy-script', function (\Illuminate\Http\Request $r) {
    $token = (string) $r->input('token');
    if (!$token) abort(401);
    $row = \Illuminate\Support\Facades\DB::table('policy_script_tokens')
        ->where('token', $token)->first();
    if (!$row) abort(403, 'Invalid token.');
    if ($row->used_at) abort(410, 'Token already used.');
    if (\Illuminate\Support\Carbon::parse($row->expires_at)->isPast()) abort(410, 'Token expired.');

    \Illuminate\Support\Facades\DB::table('policy_script_tokens')->where('id', $row->id)->update([
        'used_at' => now(), 'updated_at' => now(),
    ]);

    $run = \Illuminate\Support\Facades\DB::table('policy_runs')->where('id', $row->policy_run_id)->first();
    if (!$run) abort(404, 'Run not found.');

    $assignment = \Illuminate\Support\Facades\DB::table('policy_assignments')->where('id', $run->policy_assignment_id)->first();
    $policy = $assignment ? \Illuminate\Support\Facades\DB::table('policies')->where('id', $assignment->policy_id)->first() : null;
    if (!$policy) abort(404, 'Policy not found.');

    // REAL BUG FIXED 2026-08-07: this used to be a plain
    // check_script_windows ?: check_script_linux fallback, which always
    // served the Windows script whenever one existed -- confirmed live,
    // a Linux device's bash trigger fetched and eval'd PowerShell.
    // $run->platform is set correctly at launch time by PolicyRunner.
    //
    // EXTENDED 2026-08-07 for enforce mode: the column to serve now
    // depends on $run->phase too (check/remediate/revert), not just
    // platform -- built dynamically rather than a three-way if/elseif
    // per platform, since the column naming (phase_script_platform) is
    // deliberately consistent specifically to allow this.
    $scriptCol = $run->phase . '_script_' . $run->platform;
    $script = $policy->{$scriptCol} ?? null;
    if (!$script) abort(404, 'Script not found for this phase/platform.');

    \App\Support\Audit::log($run->tenant_id, 'policy-script', 'policy_script.fetched', 'success', null, [
        'policy_run_id' => $run->id, 'hostname' => $run->hostname,
    ]);

    return response($script, 200)->header('Content-Type', 'text/plain');
})->middleware('throttle:20,1');

/**
 * Real, important reason this exists at all: Fleet silently TRUNCATES
 * captured script output (confirmed directly elsewhere in this project,
 * e.g. ResticScripts.php). A check script's entire value is its findings
 * -- trusting Fleet's own stdout capture for that would silently drop
 * data on any real, non-trivial result. The check script POSTs its real
 * findings here instead; Fleet's own captured output is kept only as a
 * short diagnostic fallback, never as the source of truth.
 *
 * Auth: NOT a token -- by the time a check script is running and ready
 * to report back, it has no fresh token left (its fetch token was
 * single-use, already consumed by /enroll/policy-script above). Identified
 * instead by policy_run_id + hostname, matched against the SAME run row
 * the script was launched against. This is deliberately weaker than
 * token auth; acceptable ONLY because this slice is read-only (a
 * malicious POST here can, at worst, write a false compliance result --
 * it cannot execute anything or change any device).
 */
Route::post('/enroll/policy-result', function (\Illuminate\Http\Request $r) {
    $runId = (string) $r->input('policy_run_id');
    $hostname = (string) $r->input('hostname');
    if (!$runId || !$hostname) abort(422, 'policy_run_id and hostname are required.');

    $run = \Illuminate\Support\Facades\DB::table('policy_runs')->where('id', $runId)->first();
    if (!$run) abort(404, 'Run not found.');

    // REAL BUG FOUND 2026-08-12, sixth occurrence of this exact class in
    // this project: the original lookup required an EXACT hostname match
    // against check.ps1's own $env:COMPUTERNAME, which returns the SHORT
    // name -- but policy_runs.hostname stores Fleet's own FQDN
    // (e.g. WIN-2UQ47DD9KL4.AICEXPERTS.LOCAL). Confirmed live: the check
    // script correctly read real firewall state, but every result
    // POST-back 404'd on this exact mismatch, on the very first Windows
    // device to ever exercise this route. id alone is already globally
    // unique and sufficient for correctness; hostname is now only a
    // normalized sanity check, logged if it disagrees, never a hard block.
    $wantShort = strtolower(explode('.', $hostname)[0]);
    $gotShort = strtolower(explode('.', $run->hostname)[0]);
    if ($wantShort !== $gotShort) {
        \Log::warning('policy-result: hostname mismatch on an otherwise valid run id', [
            'run_id' => $run->id, 'expected' => $run->hostname, 'received' => $hostname,
        ]);
    }

    // REAL FIX 2026-08-23, found during a real, live investigation of a
    // real symptom (a check appearing permanently "running" despite the
    // device having genuinely already finished and reported a real
    // result): this route and Fleet's own separate script-execution
    // polling (PollPolicyRuns/FleetScriptRunner::poll) are TWO
    // genuinely independent, asynchronous completion paths. A real
    // result received here IS definitive, authoritative proof the
    // check actually completed -- there is no real reason to keep
    // status stuck at 'running'/'pending' waiting for Fleet's own,
    // separate exec-status polling to ALSO eventually agree, especially
    // since that polling can legitimately lag or never fully resolve
    // for reasons unrelated to whether the actual check itself worked.
    \Illuminate\Support\Facades\DB::table('policy_runs')->where('id', $run->id)->update([
        'result' => mb_substr((string) $r->input('result', ''), 0, 60000),
        'status' => 'success',
        'finished_at' => now(),
        'updated_at' => now(),
    ]);

    \App\Support\Audit::log($run->tenant_id, 'policy-script', 'policy_result.received', 'success', null, [
        'policy_run_id' => $run->id, 'hostname' => $hostname,
    ]);

    return response('ok', 200)->header('Content-Type', 'text/plain');
})->middleware('throttle:20,1');

// REAL FEATURE 2026-08-13: the first policy needing genuine tenant-
// supplied input (IP Block, Website Block). A device fetches its own
// assignment's parameter list via THIS route -- never interpolated into
// the trigger script (that mechanism only ever carried our own
// controlled values; literal, tenant-typed text is a real injection
// risk in a shell/PowerShell context run with root/admin privileges).
// Same trust model as /enroll/policy-result directly above: resolved by
// run_id alone, which is a real, server-generated, unguessable UUID --
// no separate token needed, matching the existing precedent exactly.
Route::get('/enroll/policy-params', function (\Illuminate\Http\Request $r) {
    $runId = (string) $r->query('run_id');
    if (!$runId) abort(422, 'run_id is required.');

    $run = \Illuminate\Support\Facades\DB::table('policy_runs')->where('id', $runId)->first();
    if (!$run) abort(404, 'Run not found.');

    $assignment = \Illuminate\Support\Facades\DB::table('policy_assignments')
        ->where('id', $run->policy_assignment_id)->first();
    if (!$assignment) abort(404, 'Assignment not found.');

    \App\Support\Audit::log($run->tenant_id, 'policy-script', 'policy_params.fetched', 'success', null, [
        'policy_run_id' => $run->id,
    ]);

    // Real, honest default: an assignment with no parameters yet set
    // returns a genuinely empty JSON array, never null/missing -- so a
    // fetching script can always safely json_decode this without a
    // separate null-check.
    return response($assignment->parameters ?? '[]')->header('Content-Type', 'application/json');
})->middleware('throttle:20,1');



/**
 * Flutterwave payment webhook.
 *
 * AUTH: Flutterwave has no CSRF token and no session — it authenticates via
 * its own signature scheme instead (see bootstrap/app.php's CSRF exemption
 * for this route). Two DIFFERENT signature schemes appear across
 * Flutterwave's own docs depending on API era:
 *   - verif-hash: the header IS the secret hash, direct string compare.
 *   - flutterwave-signature: HMAC-SHA256 of the raw body, base64-encoded.
 * We don't yet know which one a real v4 webhook actually sends — accept
 * either, and log which one arrived so this can be simplified later once
 * that's confirmed empirically (same discipline as everything else this
 * project has verified rather than assumed).
 *
 * NEVER trust the webhook body's stated status. Per Flutterwave's own
 * best-practice docs, always re-verify server-side via the charge-retrieval
 * endpoint before crediting anything.
 *
 * Idempotent via the existing unique(provider, provider_reference)
 * constraint on backup_payments — a duplicate delivery is a harmless
 * updateOrInsert, never a double-provision.
 *
 * DEGRADED PATH: checkout initiation (requestUpgrade() calling Flutterwave
 * for real) is not built yet as of 2026-07-25 — nothing currently populates
 * charge.meta with which tenant/plan a payment is for. Until that exists,
 * a real webhook here records the payment and raises a CRITICAL operator
 * alert rather than silently dropping money on the floor. Once checkout
 * initiation is built and always sets meta.tenant_id/meta.gb_size, this
 * degraded path should stop firing in practice — but it stays as a safety
 * net, not a TODO to delete.
 */
Route::post('/webhooks/flutterwave', function (\Illuminate\Http\Request $r) {
    $rawBody = $r->getContent();
    $webhookHash = config('services.flutterwave.webhook_hash');

    $verifHash = $r->header('verif-hash');
    $flwSignature = $r->header('flutterwave-signature');

    $verified = false;
    $method = null;

    if ($verifHash && $webhookHash && hash_equals($webhookHash, $verifHash)) {
        $verified = true;
        $method = 'verif-hash';
    } elseif ($flwSignature && $webhookHash) {
        $computed = base64_encode(hash_hmac('sha256', $rawBody, $webhookHash, true));
        if (hash_equals($computed, $flwSignature)) {
            $verified = true;
            $method = 'flutterwave-signature';
        }
    }

    if (!$verified) {
        \App\Support\Health\OperatorAlert::send('Flutterwave webhook signature verification FAILED', [
            ['level' => 'critical', 'area' => 'payments',
             'message' => 'Rejected webhook — neither verif-hash nor flutterwave-signature matched. '
                        . 'Headers present: verif-hash=' . ($verifHash ? 'yes' : 'no')
                        . ', flutterwave-signature=' . ($flwSignature ? 'yes' : 'no')],
        ]);
        abort(401);
    }

    // Log which scheme actually matched — first real confirmation of which
    // one this account's webhooks use.
    \App\Support\Audit::log(null, 'flutterwave-webhook', 'payment.webhook_received', 'success', 'flutterwave', [
        'verified_via' => $method,
        'ip' => $r->ip(),
    ]);

    $payload = $r->json()->all();
    $chargeId = $payload['data']['id'] ?? null;

    if (!$chargeId) {
        // Not every Flutterwave event is a charge event — acknowledge and
        // move on rather than erroring on something we don't act on.
        return response('ok', 200);
    }

    // NEVER trust the body — re-fetch the charge from Flutterwave directly.
    $flw = new \App\Support\Payments\FlutterwaveClient();
    try {
        $charge = $flw->getCharge($chargeId);
    } catch (\Throwable $e) {
        \App\Support\Health\OperatorAlert::send('Flutterwave webhook: could not re-verify charge', [
            ['level' => 'critical', 'area' => 'payments',
             'message' => "charge_id={$chargeId} error=" . $e->getMessage()],
        ]);
        // Still 200 — Flutterwave will retry a non-200, and the failure is
        // on our verification call, not something a retry would fix faster
        // than an operator looking at it.
        return response('ok', 200);
    }

    $reference = $charge['reference'] ?? $chargeId;
    $status = $charge['status'] ?? 'unknown';
    $amount = (int) round(($charge['amount'] ?? 0));

    // SUBSCRIPTION PAYMENTS FIRST. Platform subscriptions use their own table
    // and their own 'secx-' reference prefix. Without this branch every
    // subscription webhook fell through to the backup lookup below and fired
    // the CRITICAL "no matching pending payment" alert -- correct behaviour for
    // an unknown charge, wrong for one we initiated ourselves.
    //
    // The same attribution rule applies: our own row, keyed on the reference we
    // generated before the charge existed. Nothing in the payload decides what
    // the customer gets -- the INVOICE written at checkout does.
    $subPayment = \Illuminate\Support\Facades\DB::table('subscription_payments')
        ->where('provider', 'flutterwave')->where('provider_reference', $reference)
        ->first();

    if ($subPayment) {
        // Already resolved. A duplicate delivery must not apply a plan twice.
        if ($subPayment->status !== 'pending') {
            return response('ok', 200);
        }

        \Illuminate\Support\Facades\DB::table('subscription_payments')
            ->where('id', $subPayment->id)->update([
                'status' => $status === 'succeeded' ? 'success' : $status,
                'paid_at' => $status === 'succeeded' ? now() : null,
                'raw_payload' => json_encode($charge),
                'updated_at' => now(),
            ]);

        if ($status !== 'succeeded') {
            return response('ok', 200);
        }

        try {
            \App\Support\Billing\ApplyPaidSubscription::fromReference($reference);
        } catch (\Throwable $e) {
            \App\Support\Health\OperatorAlert::send('Subscription paid but the plan could not be applied', [
                ['level' => 'critical', 'area' => 'billing',
                 'message' => "reference={$reference} error=" . $e->getMessage()
                            . ' -- the customer HAS paid. Apply the plan manually.'],
            ]);
        }

        return response('ok', 200);
    }

    // ATTRIBUTION SOURCE OF TRUTH: our OWN backup_payments row, created by
    // requestUpgrade() at request time with a reference we generated and
    // control — NEVER Flutterwave's charge.meta, which depends on every
    // future call site remembering to set it correctly and which we cannot
    // fully trust to survive round-trip unaltered.
    $payment = \Illuminate\Support\Facades\DB::table('backup_payments')
        ->where('provider', 'flutterwave')->where('provider_reference', $reference)
        ->first();

    if (!$payment) {
        // No local record expected this charge. Do NOT insert a guessed
        // row — tenant_id is NOT NULL by design, and a row with an unknown
        // tenant is worse than no row at all. This is a genuine anomaly:
        // a charge our own checkout flow never initiated (a manual/test
        // charge created outside requestUpgrade(), or a reference that
        // somehow diverged) — surface it loudly rather than guess.
        \App\Support\Health\OperatorAlert::send('Flutterwave webhook: no matching pending payment', [
            ['level' => 'critical', 'area' => 'payments',
             'message' => "charge_id={$chargeId} reference={$reference} amount_cents={$amount} "
                        . "status={$status} — no backup_payments row with this reference. "
                        . 'Not recorded automatically; if this is a real payment, provision manually.'],
        ]);
        return response('ok', 200);
    }

    \Illuminate\Support\Facades\DB::table('backup_payments')->where('id', $payment->id)->update([
        'amount_usd_cents' => $amount,
        'status' => $status === 'succeeded' ? 'success' : $status,
        'paid_at' => $status === 'succeeded' ? now() : null,
        'raw_payload' => json_encode($charge),
        'updated_at' => now(),
    ]);

    if ($status !== 'succeeded') {
        return response('ok', 200);
    }

    $tenantId = $payment->tenant_id;
    $plan = $payment->plan_id
        ? \Illuminate\Support\Facades\DB::table('backup_plans')->where('id', $payment->plan_id)->first()
        : null;
    $gbSize = $plan->gb_size ?? null;

    if (!$gbSize) {
        \App\Support\Health\OperatorAlert::send('Flutterwave payment succeeded but plan is unknown', [
            ['level' => 'critical', 'area' => 'payments',
             'message' => "tenant_id={$tenantId} reference={$reference} — backup_payments row has no "
                        . 'plan_id, or that plan no longer exists. Provision manually.'],
        ]);
        return response('ok', 200);
    }

    try {
        $connId = \Illuminate\Support\Facades\DB::table('tenant_backup_connections')
            ->where('tenant_id', $tenantId)->where('provider', 'idrive_e2')->value('id');

        if ($connId) {
            \App\Support\Backup\BackupProvisioner::applyQuota($tenantId, $connId, (int) $gbSize);

            \App\Support\Notifications::raiseAndEmail($tenantId, __('Storage upgrade active'), [
                'level' => 'info',
                'body'  => __('Your backup storage has been upgraded. Thank you for your payment.'),
                'link'  => '/portal/backups',
            ]);
        } else {
            \App\Support\Health\OperatorAlert::send('Flutterwave payment succeeded but tenant has no backup connection', [
                ['level' => 'critical', 'area' => 'payments',
                 'message' => "tenant_id={$tenantId} paid for {$gbSize}GB but has no idrive_e2 connection to apply it to."],
            ]);
        }
    } catch (\Throwable $e) {
        \App\Support\Health\OperatorAlert::send('Flutterwave payment succeeded but quota application FAILED', [
            ['level' => 'critical', 'area' => 'payments',
             'message' => "tenant_id={$tenantId} gb={$gbSize} error=" . $e->getMessage()],
        ]);
    }

    return response('ok', 200);
})->middleware('throttle:60,1');

/**
 * Backup storage checkout — deliberately PLAIN routes/controller, not
 * Livewire. Livewire serialises public component state into every network
 * response; card fields must never round-trip through that mechanism even
 * transiently. This is a single POST straight to the server, encrypted and
 * forwarded to Flutterwave immediately, never logged or persisted.
 */
Route::middleware('tenant')->group(function () {
    Route::get('/portal/backups/checkout/{plan}', [\App\Http\Controllers\BackupCheckoutController::class, 'show'])
        ->name('backup-checkout.show');
    Route::post('/portal/backups/checkout/{plan}', [\App\Http\Controllers\BackupCheckoutController::class, 'charge'])
        ->name('backup-checkout.charge');
    Route::get('/portal/backups/checkout/auth/{chargeId}/{stepType}', [\App\Http\Controllers\BackupCheckoutController::class, 'showAuthStep'])
        ->name('backup-checkout.show-auth-step');
    Route::post('/portal/backups/checkout/auth/{chargeId}/{stepType}', [\App\Http\Controllers\BackupCheckoutController::class, 'submitAuthStep'])
        ->name('backup-checkout.submit-auth-step');
});

// REAL FEATURE 2026-08-23: External API, step 3 -- wiring the real,
// already-built ScansController (see Session 13/14 notes: the
// controller itself, and its own real, tenant-ownership fix for
// device-target scans, were built and reviewed weeks ago but never
// actually wired to a route). Uses the real 'api-key' middleware
// (AuthenticateApiKey), NOT session/tenant auth -- matches how every
// real external integrator will actually call this.
Route::middleware('api-key')->prefix('api')->group(function () {
    Route::post('/scans', [\App\Http\Controllers\Api\ScansController::class, 'store']);
    Route::get('/scans/{id}', [\App\Http\Controllers\Api\ScansController::class, 'show']);
    Route::get('/scans', [\App\Http\Controllers\Api\ScansController::class, 'index']);
});
Route::get('/portal/monitoring-evidence', \App\Livewire\Portal\MonitoringEvidence::class)->middleware('tenant');
Route::get('/portal/web-scan-login', \App\Livewire\Portal\WebScanLogin::class)->middleware('tenant');

