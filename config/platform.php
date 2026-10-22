<?php

return [
    // REAL FEATURE 2026-08-22, added during a real portability audit:
    // the base domain was previously hardcoded ("obaits.com") in
    // roughly a dozen real, separate places across the codebase.
    // Centralizing it here means a future rename (e.g. to "secx.com",
    // or the platform's own likely eventual name "SecX") is a single,
    // real config change instead of hunting through the codebase.
    // Default matches the real, current, live value -- changing
    // nothing about today's actual behavior.
    'base_domain' => env('PLATFORM_BASE_DOMAIN', 'obaits.com'),

    // Real, separate email addresses -- kept configurable rather than
    // assumed to always be "@{base_domain}", since a real future setup
    // could plausibly want these on a different domain than the main
    // platform itself.
    'support_email' => env('PLATFORM_SUPPORT_EMAIL', 'support@obaits.com'),
    'admin_email' => env('PLATFORM_ADMIN_EMAIL', 'admin@obaits.com'),
    'backups_email_domain' => env('PLATFORM_BACKUPS_EMAIL_DOMAIN', 'backups.obaits.com'),
];
