<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

      'keycloak' => [
        // Used by Socialite for the dashboard browser login (obaits-dashboard client)
        'base_url' => env('KEYCLOAK_BASE_URL'),
        'realm' => env('KEYCLOAK_REALM'),
        'realms' => env('KEYCLOAK_REALM'),
        'client_id' => env('KEYCLOAK_DASHBOARD_CLIENT_ID'),
        'client_secret' => env('KEYCLOAK_DASHBOARD_CLIENT_SECRET'),
        'redirect' => env('KEYCLOAK_REDIRECT_URI'),
    ],

    'keycloak_orchestrator' => [
        // Used by KeycloakClient for provisioning (obaits-orchestrator service-account client)
        // Admin API goes over the internal address in prod (bypasses nginx/WAF);
        // falls back to the public URL so dev needs no change.
        'base_url' => env('KEYCLOAK_ADMIN_URL', env('KEYCLOAK_BASE_URL')),
        'realm' => env('KEYCLOAK_REALM'),
        'client_id' => env('KEYCLOAK_CLIENT_ID'),
        'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
    ],

      'domain_verification' => [
        'mode' => env('DOMAIN_VERIFICATION_MODE', 'dns'),
    ],

    'fleet' => [
        'base_url' => env('FLEET_BASE_URL'),
        'agent_url' => env('FLEET_AGENT_URL', env('FLEET_BASE_URL')),
        'api_token' => env('FLEET_API_TOKEN'),
        'cert_path' => env('FLEET_CERT_PATH'),
    ],

    'iris' => [
        // Real, shared, admin-level API key -- confirmed directly against
        // our own real, live instance (v2.4.29) that /api/v2/cases genuinely
        // returns "Resource not found" (a real, confirmed limitation of
        // this IRIS version range, matching a still-open GitHub issue), so
        // real case creation uses the older, "deprecated" /manage/cases/add
        // endpoint instead -- the only one that actually works.
        'base_url' => env('IRIS_BASE_URL', 'https://127.0.0.1:8843'),
        'api_key' => env('IRIS_API_KEY'),
    ],

    'acmedns' => [
        // Real, local-only acme-dns REST API -- confirmed directly
        // this session that the mechanism (registration, CNAME
        // delegation, Certbot DNS-01 issuance and auto-renewal) works
        // correctly end to end against a real test domain.
        'base_url' => env('ACMEDNS_BASE_URL', 'http://127.0.0.1:8095'),
    ],

    'zabbix' => [
        'api_url' => env('ZABBIX_API_URL'),
        'api_token' => env('ZABBIX_API_TOKEN'),
        // The real, public address+port real devices connect their own
        // Zabbix agent to -- confirmed directly (2026-08-04) the VPS is
        // deliberately NOT part of the Tailscale/Headscale mesh, so this
        // follows the same real, already-proven pattern as Wazuh's own
        // agent_address: a direct connection to the VPS's real public IP,
        // not the VPN.
        // REAL FIX 2026-08-22, found during a real portability audit: this
        // previously defaulted to this exact VPS's real IP if the env var
        // was missing -- meaning a fresh deployment (new VPS, or a future
        // bootable ISO) with no ZABBIX_AGENT_ADDRESS set would silently
        // point every real enrolled device's Zabbix agent at the WRONG,
        // OLD VPS. No default now -- Enroll.php has a real, explicit guard
        // that fails loudly if this is genuinely missing, instead.
        'agent_address' => env('ZABBIX_AGENT_ADDRESS'),
    ],

    'wazuh' => [
        'base_url' => env('WAZUH_BASE_URL'),
        'api_user' => env('WAZUH_API_USER'),
        'api_password' => env('WAZUH_API_PASSWORD'),
        'enroll_password' => env('WAZUH_ENROLL_PASSWORD'),
        'agent_address' => env('WAZUH_AGENT_ADDRESS', parse_url((string) env('WAZUH_BASE_URL'), PHP_URL_HOST)),
        // The Indexer (OpenSearch) — where ALERTS live. Separate credentials
        // from the manager API; do not confuse the two.
        'indexer_url' => env('WAZUH_INDEXER_URL', 'https://localhost:9200'),
        'indexer_user' => env('WAZUH_INDEXER_USER', 'admin'),
        'indexer_password' => env('WAZUH_INDEXER_PASSWORD', 'SecretPassword'),
    ],

    'ciso' => [
        // Admin/mutating calls (folder create/delete) go over the internal address
        // in prod, bypassing nginx/ModSecurity — CRS blocks DELETE .../folders/{uuid}/
        // with rule 949110 (Inbound Anomaly Score Exceeded). Falls back to the public
        // URL so dev (no reverse proxy in front) needs no change.
        'base_url' => env('CISO_ADMIN_URL', env('CISO_BASE_URL')),
        'api_token' => env('CISO_API_TOKEN'),
    ],

     'gvm' => [
        'ssh_host' => env('GVM_SSH_HOST'),
        'ssh_port' => env('GVM_SSH_PORT', 22),
        'ssh_user' => env('GVM_SSH_USER'),
        'password' => env('GVM_PASSWORD'),
        'helper' => env('GVM_HELPER'),
        'scan_config_id' => 'daba56c8-73ec-11df-a475-002264764cea',
        // Which GVM scanner runs a scan. Historically the hardcoded built-in
        // ("OpenVAS Default", a unix-socket scanner inside the main stack).
        //
        // 2026-08-25: made overridable so a scanner POOL MEMBER can be used
        // instead. The built-in can only ever reach what the GVM stack itself
        // can reach -- confirmed by a real scan returning findings for
        // 127.0.0.1 rather than the intended target, because the container has
        // no route to any tenant VPN.
        //
        // TEMPORARY as an env override. The real implementation selects a
        // member per scan from scanner_pool_members, because each member joins
        // exactly ONE tenant network at a time and the right member depends on
        // the scan's tenant.
        'scanner_id' => env('GVM_SCANNER_ID', '08b69003-5fc2-4037-a479-93b440211c73'),
    ],

    'zap' => [
        'base_url' => env('ZAP_BASE_URL', 'http://127.0.0.1:8091'),
        'api_key' => env('ZAP_API_KEY', 'OBAITS_DEV_ZAP_KEY'),
    ],

     'llm' => [
        'provider' => env('LLM_PROVIDER', 'deepseek'),
    ],
    'deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY'),
        'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-v4-flash'),
    ],

    'claude' => [
        'api_key' => env('CLAUDE_API_KEY'),
        'model'   => env('CLAUDE_MODEL', 'claude-sonnet-5'),
    ],
    
    'operator' => [
        'email' => env('OPERATOR_EMAIL'),
    ],

    'dropbox' => [
        'refresh_token' => env('DROPBOX_REFRESH_TOKEN'),
        'app_key'       => env('DROPBOX_APP_KEY'),
        'app_secret'    => env('DROPBOX_APP_SECRET'),
        'path'          => env('DROPBOX_LICENCE_PATH', '/licences.csv'),
    ],
    'operator' => [
        'email' => env('OPERATOR_EMAIL'),
    ],
    // Where the PLATFORM itself lives — distinct from Fleet and Wazuh, which are
    // separate services and in production may be separate hosts entirely. The
    // install script fetches itself and the agent package from here.
    'obaits' => [
        'portal_url' => env('OBAITS_PORTAL_URL', env('APP_URL')),
    ],
    // Containers the health monitor watches. Deliberately configurable: the
    // hardcoded list was dev's, so in production it reported containers that do
    // not exist as "down" and tried to restart them.
    'health' => [
        // REAL FAILURE 2026-08-26: the VPS restarted and ospd-pool-1 exited 127.
        // It stayed dead for eight minutes and NOTHING noticed -- the health
        // monitor auto-restarts containers it knows about, and the scanner pool
        // was never added to HEALTH_CONTAINERS. The machinery worked; the
        // registration was missing.
        //
        // The list used to live ONLY in .env, which is not version controlled.
        // So the fix existed on one host, a rebuilt server or an .iso appliance
        // would start with whatever the image happened to ship, and every new
        // container had to be remembered by hand on every deployment target.
        // That is exactly how the scanner fell outside the safety net.
        //
        // Now a repo-tracked default that travels with the code. HEALTH_CONTAINERS
        // still works and is ADDITIVE -- it extends this list rather than
        // replacing it, so an operator can add host-specific containers without
        // being able to silently drop the platform's own.
        'containers' => array_values(array_unique(array_merge(
            [
                // Platform core
                'postgres', 'caddy',
                // Endpoint management
                'fleet', 'fleet-mysql', 'fleet-redis',
                // Identity
                'keycloak', 'keycloak-db',
                // GRC
                'backend', 'frontend', 'huey',
                // Vulnerability scanning -- main stack
                'greenbone-community-edition-gvmd-1',
                'greenbone-community-edition-ospd-openvas-1',
                'greenbone-community-edition-pg-gvm-1',
                // Vulnerability scanning -- pool member 1
                'obaits-gvm-pool-1-ospd-pool-1-1',
                'obaits-gvm-pool-1-tailscale-pool-1-1',
                'obaits-gvm-pool-1-openvasd-pool-1-1',
                'obaits-gvm-pool-1-redis-pool-1-1',
                // Incident response -- holds case data and evidence
                'iriswebapp_app', 'iriswebapp_db', 'iriswebapp_worker',
                'iriswebapp_rabbitmq', 'iriswebapp_nginx',
                // Monitoring -- holds history
                'zabbix-server', 'zabbix-web', 'zabbix-postgres',
                // Certificates
                'acme-dns', 'acme-dns-db',
            ],
            array_filter(array_map('trim', explode(',', (string) env('HEALTH_CONTAINERS', ''))))
        ))),
        'tenant_impact' => array_reduce(array_filter(array_map('trim', explode(',', (string) env('HEALTH_TENANT_IMPACT', '')))), function (array $carry, string $entry): array {
            [$name, $impact] = array_pad(explode(':', $entry, 2), 2, '');
            $name = trim($name);
            $impact = trim($impact);
            if ($name !== '' && $impact !== '') {
                $carry[$name] = $impact;
            }
            return $carry;
        }, []),
    ],

    'idrive_e2' => [
        'reseller_api_key' => env('IDRIVE_E2_RESELLER_API_KEY'),
        'reseller_base_url' => 'https://api.idrivee2.com/api/reseller/v1',
    ],

    'flutterwave' => [
        'client_id'       => env('FLUTTERWAVE_CLIENT_ID'),
        'client_secret'   => env('FLUTTERWAVE_CLIENT_SECRET'),
        'encryption_key'  => env('FLUTTERWAVE_ENCRYPTION_KEY'),
        'webhook_hash'    => env('FLUTTERWAVE_WEBHOOK_HASH'),
        'base_url'        => env('FLUTTERWAVE_BASE_URL', 'https://developersandbox-api.flutterwave.com'),

        // SANDBOX TESTING ONLY. Forces a specific charge outcome via
        // X-Scenario-Key -- 'auth_pin&issuer:approved' makes Flutterwave demand
        // a PIN, which is the only practical way to exercise the multi-step
        // authorisation chain deliberately.
        //
        // Refused at the call site unless base_url contains 'sandbox', so this
        // cannot reach a real customer's charge even if set in the wrong .env.
        // MUST be left unset in production.
        'scenario_key'    => env('FLUTTERWAVE_SCENARIO_KEY'),
    ],

];
