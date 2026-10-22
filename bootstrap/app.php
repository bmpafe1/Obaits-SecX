<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Illuminate\Foundation\Configuration\Middleware $middleware)  {
        $middleware->alias([
            'tenant' => \App\Http\Middleware\RequireTenant::class,
             'operator' => \App\Http\Middleware\OperatorAuth::class,

            'api-key' => \App\Http\Middleware\AuthenticateApiKey::class,
            'operator-api-key' => \App\Http\Middleware\AuthenticateOperatorApiKey::class,
        ]);

        // Apply the user's chosen language on every web request.
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
        ]);

        // The enroll endpoints are authenticated by the tenant's enroll secret
        // (a header credential), not by a browser session — CSRF does not apply.
        // The install script is a machine with no token; without this it gets 419.
        //
        // webhooks/flutterwave: Flutterwave POSTs to us with no CSRF token —
        // it is authenticated by its own signature scheme (verif-hash or
        // flutterwave-signature, checked in the handler itself), not a
        // browser session.
        $middleware->validateCsrfTokens(except: [
            // REAL FEATURE 2026-08-20: the whole External API is
            // authenticated by its own API-key middleware (see
            // AuthenticateApiKey), never a browser session -- CSRF does
            // not apply, same reasoning as /enroll/*. Using a WILDCARD
            // here deliberately, unlike every individual /enroll/*
            // route below -- forgetting to add one new API endpoint to
            // this list is exactly the real bug already hit once this
            // project (threat-intel-source-counts, a genuine 419 that
            // was silently discarded and went unnoticed for a while).
            // A wildcard for the whole api/ prefix makes that entire
            // class of bug structurally impossible going forward.
            'api/*',
            'enroll/wazuh-precheck',
            'enroll/device-credential',
            'enroll/policy-script',
            'enroll/policy-result',
            // REAL BUG, THE SECOND OCCURRENCE, 2026-08-23: made the exact
            // mistake the comment below already documents, on the same
            // feature, seven days later -- added a new device-POST route
            // without adding it here. Confirmed live: HTTP 419, and because
            // report_health() also redirects to /dev/null, the refresh looked
            // perfectly successful while every health report was rejected
            // before reaching any application code at all (nothing in the log,
            // since VerifyCsrfToken rejects ahead of the route closure).
            'enroll/threat-intel-health',
            'enroll/role-credential',
            'enroll/role-script',
            // REAL BUG FOUND 2026-08-16: forgot to add this brand-new
            // route to the exclusion list -- confirmed live, every real
            // report from a device's own threat-intel-refresh.sh got a
            // genuine 419 Page Expired (CSRF token mismatch), silently
            // discarded by the script's own > /dev/null 2>&1 redirect,
            // so the refresh itself always looked completely successful
            // while every single report to the portal failed unnoticed.
            'enroll/threat-intel-source-counts',
            'webhooks/flutterwave',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
