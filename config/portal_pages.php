<?php

/**
 * The portal's pages, in one place.
 *
 * Brian's system prompt instructs him never to invent UI elements — correctly —
 * but his page list was hand-maintained and drifted. Five pages were added and
 * one renamed without it being updated, so he was faithfully describing a
 * platform that no longer existed.
 *
 * This is the single source of truth. The navigation renders from it and the
 * assistant reads it, so a page cannot exist in one and not the other.
 *
 * 'purpose' is written for the assistant, not the menu: it is what lets him
 * send someone to the right page rather than guessing from a label.
 */
return [

    'dashboard' => [
        'label' => 'Dashboard',
        'match' => ['dashboard'],
        'standalone' => true,
        'pages' => [
            ['path' => '/dashboard', 'label' => 'Dashboard',
             'purpose' => 'Overview: findings by severity, most at-risk systems, compliance progress, and a Coverage panel stating plainly what the platform can and cannot see.'],
        ],
    ],

    'monitoring' => [
        'label' => 'Monitoring',
        'match' => ['portal/enroll','portal/scans*','portal/assets','portal/alerts','portal/threats'],
        'pages' => [
            ['path' => '/portal/enroll', 'label' => 'Protect a computer',
             'purpose' => 'Install the monitoring agents. Gives a single command per operating system that installs both agents and attributes the machine to this organisation. This is the starting point for everything endpoint-based.'],
            ['path' => '/portal/scans', 'label' => 'Scans',
             'purpose' => 'Run and review network and web scans. Each scan has a Findings view. Web scans state whether they got past the site login.'],
            ['path' => '/portal/assets', 'label' => 'Assets',
             'purpose' => 'Computers with the monitoring agent installed, with operating system, address and status.'],
            ['path' => '/portal/alerts', 'label' => 'Security alerts',
             'purpose' => 'Alerts raised by the platform, including critical vulnerability notifications.'],
            ['path' => '/portal/threats', 'label' => 'Threat detection',
             'purpose' => 'What the agents have detected on monitored computers: file integrity changes, rootkit and malware findings, firewall blocks, and MITRE ATT&CK technique mappings. Four tabs over the last seven days.'],
        ],
    ],

    'protection' => [
        'label' => 'Protection',
        'match' => ['portal/waf','portal/software','portal/vulnerable-software','portal/posture','portal/web-scan-login'],
        'pages' => [
            ['path' => '/portal/waf', 'label' => 'Web firewall',
             'purpose' => 'Web application firewall: blocking mode, rule tuning and virtual patching for the organisation website.'],
            ['path' => '/portal/software', 'label' => 'Software',
             'purpose' => 'Software inventory and the approved-software catalogue.'],
            ['path' => '/portal/vulnerable-software', 'label' => 'Vulnerable software',
             'purpose' => 'Installed software with known security flaws, worst first, each CVE linked to its official entry, naming which computers run it.'],
            ['path' => '/portal/posture', 'label' => 'Security posture',
             'purpose' => 'Configuration hardening score from security configuration assessment, with passed and failed checks per machine.'],
            ['path' => '/portal/web-scan-login', 'label' => 'Web scan login',
             'purpose' => 'Store a login so web scans can test pages behind the sign-in. Includes a test that proves whether the login actually works.'],
        ],
    ],

    'compliance' => [
        'label' => 'Compliance',
        'match' => ['portal/compliance','portal/document*','portal/evidence','portal/monitoring-evidence','portal/governance','portal/access'],
        'pages' => [
            ['path' => '/portal/compliance', 'label' => 'Compliance',
             'purpose' => 'Framework progress and control status. Multiple frameworks are supported at once.'],
            ['path' => '/portal/evidence', 'label' => 'Policies & documents',
             'purpose' => 'Policies, procedures and records the organisation writes and uploads. Documents that evidence controls requiring written policy.'],
            ['path' => '/portal/monitoring-evidence', 'label' => 'Monitoring evidence',
             'purpose' => 'Events the monitored computers actually recorded, grouped by the compliance references the detection rules carry. Useful when an assessor asks for proof that monitoring is in place and working. This is records the systems produced, as distinct from documents people wrote.'],
            ['path' => '/portal/governance', 'label' => 'People and approvals',
             'purpose' => 'Roles, responsibilities and approval workflows.'],
            ['path' => '/portal/access', 'label' => 'Access review',
             'purpose' => 'Periodic review of who has access to what.'],
        ],
    ],

    'organisation' => [
        'label' => 'Organisation',
        'match' => ['portal/domains','portal/organisation','portal/helpdesk*','portal/help/mfa','portal/team'],
        'pages' => [
            ['path' => '/portal/domains', 'label' => 'Domains',
             'purpose' => 'Add and verify domains. Only verified domains can be scanned — the platform will not scan a site the organisation has not proven it controls.'],
            ['path' => '/portal/organisation', 'label' => 'Your organisation',
             'purpose' => 'Organisation profile and details used across compliance documents.'],
            ['path' => '/portal/helpdesk', 'label' => 'Helpdesk',
             'purpose' => 'Raise and track support tickets.'],
            ['path' => '/portal/help/mfa', 'label' => 'Signing in safely',
             'purpose' => 'Guidance on multi-factor authentication and safe sign-in.'],
            ['path' => '/portal/team', 'label' => 'Team', 'admin_only' => true,
             'purpose' => 'Manage users and their roles. Visible to platform administrators only.'],
        ],
    ],

    'account' => [
        'label' => 'Account',
        'match' => ['portal/account'],
        'pages' => [
            ['path' => '/portal/account', 'label' => 'Your account',
             'purpose' => 'Personal account settings, notification preferences and licence key activation.'],
        ],
    ],

];