<?php

return [

    /*
     * Where the platform's own copy of every referral goes.
     *
     * Without a provider portal this is the ONLY oversight that exists: it is
     * how the platform sees the referrals it makes, and how it knows to chase
     * or reassign when a provider goes quiet, has shut down, or never replies.
     *
     * Configurable rather than hardcoded because an on-premise deployment may
     * route oversight elsewhere, or a reseller may want their own address here.
     */
    'oversight_email' => env('MARKETPLACE_OVERSIGHT_EMAIL', 'services@obaits.com'),

];
