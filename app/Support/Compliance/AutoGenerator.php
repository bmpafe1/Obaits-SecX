<?php
namespace App\Support\Compliance;

use Illuminate\Support\Facades\DB;
use App\Support\RiskEngine;

class AutoGenerator
{
    /**
     * Build a document body from the tenant's LIVE telemetry.
     * Called for artefacts whose mode is AUTOMATED.
     */
    public static function body(string $tenantId, string $key, array $doc, array $profile): string
    {
        // 1. Get the document's data content (generators unchanged).
        $content = match ($doc['source'] ?? '') {
            'findings'       => self::fromFindings($tenantId),
            'assets'         => self::fromAssets($tenantId),
            'software'       => self::fromSoftware($tenantId),
            'access'         => self::fromAccess($tenantId),
            'governance'     => self::fromGovernance($tenantId),
            'profile'        => self::fromProfile($profile),
            'waf'            => self::fromWaf($tenantId),
            'waf_blocks'     => self::fromWafBlocks($tenantId),
            'wazuh'          => self::fromWazuh($tenantId),
            'monthly_report' => self::fromMonthlyReport($tenantId, $profile),
            'wazuh_alerts'   => self::fromWazuhAlerts($tenantId),
            'incidents'        => self::fromIncidents($tenantId),
            'incident_reviews' => self::fromIncidentReviews($tenantId),
            default          => "## Content\n\n[No data source configured for this document.]",
        };

        // 2. Prepend the professional, framework-aware preamble.
        [$purpose, $howMaintained] = self::purposeFor($doc['source'] ?? '');
        $pre = self::preamble($tenantId, $key, $purpose, $howMaintained);

        return $pre . $content;
    }

    /**
     * Per-document purpose sentence and maintenance points. Framework-NEUTRAL —
     * the clause citation is derived separately from the tenant's real frameworks.
     */
    private static function purposeFor(string $source): array
    {
        return match ($source) {
            'findings' => [
                'This register records the information security risks identified for the organisation and the treatment decision taken for each. It provides evidence that risks are assessed on a defined, repeatable basis and managed deliberately.',
                ['Risks are identified by automated assessment and prioritised using real-world exploitation data, not technical severity alone.', 'Each risk carries a treatment decision made and approved by the responsible person.', 'The register is refreshed as new findings arise and reviewed on the organisation\'s review cycle.'],
            ],
            'assets' => [
                'This register records the devices and information assets within the scope of the management system, so that each asset has a known owner and can be protected appropriately.',
                ['Assets are discovered automatically from enrolled devices.', 'Each asset should have an owner responsible for its protection.', 'The inventory is kept current as devices are added or retired.'],
            ],
            'software' => [
                'This register records the software in use and its licensing, supporting control of what runs on organisational systems and compliance with licence terms.',
                ['Software is inventoried from enrolled devices.', 'Each item should be approved before use.', 'Licences and renewal dates are tracked to avoid lapse or over-deployment.'],
            ],
            'access' => [
                'This document records how access to systems and information is granted, reviewed, and removed, ensuring people have only the access their role requires.',
                ['Access is granted on the principle of least privilege.', 'Access is reviewed periodically and on role change.', 'Access is removed promptly when no longer required.'],
            ],
            'governance' => [
                'This document defines and assigns information security roles, responsibilities, and authorities, ensuring accountability for the management system is clear.',
                ['Every asset, risk, and control has a named owner.', 'Roles are communicated on hire, on change, and at least annually.', 'Conflicts of interest are identified and managed where one person holds several roles.'],
            ],
            'profile' => [
                'This document defines the scope of the management system — its boundaries and applicability — and records the organisation to which it applies.',
                ['The scope names the functions, locations, and infrastructure covered.', 'It is kept aligned with the Statement of Applicability.', 'It is reviewed when the organisation or its context changes.'],
            ],
            'waf' => [
                'This document records the web application firewall protecting the organisation\'s applications, and the basis on which it operates.',
                ['The firewall is deployed on the organisation\'s own infrastructure.', 'Rules are reviewed as applications and threats change.', 'Blocking versus detection posture is recorded and reviewed.'],
            ],
            'waf_blocks' => [
                'This report records attacks that the web application firewall actively blocked, providing evidence that protective monitoring is operating.',
                ['Blocked attacks are recorded automatically from the firewall.', 'Patterns are reviewed to inform rule tuning.', 'This evidence is retained for audit.'],
            ],
            'wazuh', 'wazuh_alerts' => [
                'This document records the logging and monitoring of security events across the organisation\'s systems, so that suspicious activity is detected and can be investigated.',
                ['Events are collected from monitored endpoints.', 'Alerts are reviewed and investigated.', 'Logs are retained per the retention policy.'],
            ],
            'monthly_report' => [
                'This report summarises the organisation\'s security posture over the period, supporting management review and continual improvement.',
                ['Figures are drawn from live platform data.', 'The report supports periodic management review.', 'Trends inform where to focus improvement.'],
            ],
            'incidents', 'incident_reviews' => [
                'This register records information security incidents and the corrective actions taken, providing evidence that incidents are managed consistently and their lessons captured.',
                ['Incidents are recorded when detected, with severity and description.', 'Root cause is analysed before corrective action is defined.', 'An incident is closed only once its lesson is recorded.'],
            ],
            default => [
                'This document forms part of the organisation\'s information security management system.',
                [],
            ],
        };
    }

    /**
     * The professional preamble every ISMS document opens with.
     * The clause citation is DERIVED from the tenant's selected frameworks and the
     * document's real control mappings — never hard-coded. A PCI-only tenant sees
     * PCI references; an ISO+PCI tenant sees both; it updates automatically when
     * frameworks change. Uses the same document_control_map that compliance:validate checks.
     */
    private static function preamble(string $tenantId, string $docKey, string $purpose, array $howMaintained = []): string
    {
        // Real control references for this document, limited to the tenant's frameworks.
        $rows = DB::table('document_control_map as m')
            ->join('tenant_frameworks as tf', 'tf.framework_id', '=', 'm.framework_id')
            ->where('tf.tenant_id', $tenantId)
            ->where('m.doc_key', $docKey)
            ->select('tf.framework_name', 'm.control_ref')
            ->orderBy('tf.framework_name')
            ->orderBy('m.control_ref')
            ->get()
            ->groupBy('framework_name');

        $out = [];

        // Build "Supports <Framework>: ref, ref · <Framework>: ref" from real data.
        if ($rows->isNotEmpty()) {
            $parts = [];
            foreach ($rows as $frameworkName => $refs) {
                $list = $refs->pluck('control_ref')->implode(', ');
                $parts[] = "{$frameworkName}: {$list}";
            }
            $out[] = '*Supports ' . implode('  ·  ', $parts) . '.*';
            $out[] = '';
        }

        $out[] = '## Purpose';
        $out[] = '';
        $out[] = $purpose;
        $out[] = '';

        if ($howMaintained) {
            $out[] = '## How this document is maintained';
            $out[] = '';
            foreach ($howMaintained as $point) {
                $out[] = "- {$point}";
            }
            $out[] = '';
        }

        return implode("\n", $out);
    }

    // ---- Risk Register: from the deterministic engine. ----
    // This is the document that would otherwise cost a consultant days.
    private static function fromFindings(string $tenantId): string
    {
        $risks = DB::table('findings')->where('tenant_id', $tenantId)
            ->where('criticality', '>=', 3)
            ->orderByDesc('criticality')
            ->limit(100)->get();

        if ($risks->isEmpty()) {
            return "## Identified risks\n\nNo risks requiring treatment were identified in the most recent assessment.";
        }

        $out = [
            "## Identified risks and treatment",
            "",
            "Risks were identified by automated assessment and prioritised using exploitation data from "
            . "CISA's Known Exploited Vulnerabilities catalogue and FIRST's EPSS — not technical severity alone.",
            "",
            "| Risk | Priority | Basis | Treatment decision |",
            "|---|---|---|---|",
        ];

        foreach ($risks as $r) {
            $f = json_decode($r->risk_factors ?? '[]', true) ?: [];
            $basis = collect($f)->pluck('source')->unique()->implode('; ');
            $label = RiskEngine::label($r->criticality);
            $name  = str_replace('|', '-', $r->name);
            $out[] = "| {$name} | {$label} | {$basis} | [To be decided by management] |";
        }

        $out[] = "";
        $out[] = "*Treatment decisions are management decisions and must be completed and approved by the responsible person.*";

        return implode("\n", $out);
    }

    // ---- Asset Register: from enrolled devices. ----
    private static function fromAssets(string $tenantId): string
    {
        $enrolled = DB::table('tenant_artifacts')->where('tenant_id', $tenantId)
            ->where('tool', 'fleet')->where('artifact_type', 'enroll_secret')->exists();

        if (!$enrolled) {
            return "## Asset register\n\n[No devices are enrolled yet. Enrol your devices on the Assets page "
                 . "and this register will populate automatically.]";
        }

        return "## Asset register\n\n"
             . "Devices are inventoried automatically through endpoint enrolment. The current device list, "
             . "including operating system, version and last-seen date, is maintained on the Assets page and "
             . "forms part of this register.\n\n"
             . "## How the inventory is kept current\n\n"
             . "Each enrolled device reports its own details continuously. Devices that stop reporting are "
             . "flagged, so the register reflects what is actually in use rather than what was recorded once.";
    }

    // ---- Software Licence Register ----
    // ---- Software Licence Register (A.5.32 Intellectual property rights) ----
    private static function fromSoftware(string $tenantId): string
    {
        $rows = DB::table('software_catalog')->where('tenant_id', $tenantId)
            ->orderBy('name')->get();

        if ($rows->isEmpty()) {
            return "## Software register\n\n[No software has been recorded yet. Add your software on the "
                 . "Software page and this register will populate automatically.]";
        }

        $out = [
            "## Software and licence register",
            "",
            "The organisation maintains the following register of software in use and its licensing position. "
            . "This register exists to ensure that software is properly licensed and that intellectual property "
            . "rights are respected.",
            "",
            "| Software | Vendor | Version | Licence | Seats | Renews |",
            "|---|---|---|---|---|---|",
        ];

        foreach ($rows as $s) {
            $name    = str_replace('|', '-', $s->name);
            $vendor  = str_replace('|', '-', $s->vendor ?: '—');
            $version = str_replace('|', '-', $s->version ?: '—');
            $licence = str_replace('|', '-', $s->license_type ?: '[not recorded]');
            $seats   = $s->seats_owned > 0 ? (string) $s->seats_owned : '—';
            $renews  = $s->renews_at ?: '—';
            $out[] = "| {$name} | {$vendor} | {$version} | {$licence} | {$seats} | {$renews} |";
        }

        // Anything found on devices but not approved is a licensing exposure — say so.
        $unapproved = $rows->where('approved', false)->count();

        $out[] = "";
        $out[] = "## How the register is maintained";
        $out[] = "";
        $out[] = "Software discovered on enrolled devices is reconciled against this register. Software found "
               . "in use but not recorded here is reported on the Software page so that it can be licensed, "
               . "approved, or removed.";

        if ($unapproved > 0) {
            $out[] = "";
            $out[] = "**{$unapproved} item(s) in this register are not yet approved for use.** These should be "
                   . "reviewed and either approved or removed.";
        }

        $out[] = "";
        $out[] = "*Licence keys are held securely and are deliberately not reproduced in this document.*";

        return implode("\n", $out);
    }

    // ---- Access Control Policy: from the real access review. ----
    private static function fromAccess(string $tenantId): string
    {
        $users = DB::table('tenant_users')->where('tenant_id', $tenantId)->get();
        $byRole = $users->groupBy('role')->map->count();

        $lines = ["## Access control", "",
            "Access is granted on the principle of least privilege: each person receives only the access "
            . "their role requires.", "",
            "## Current access", ""];

        $lines[] = "{$users->count()} individuals currently hold access:";
        foreach ($byRole as $role => $n) {
            $lines[] = "- {$n} with the role \"{$role}\"";
        }

        $lines[] = "";
        $lines[] = "## Granting and removing access";
        $lines[] = "";
        $lines[] = "Access is granted by an administrator when someone joins or changes role, and removed "
                 . "when they leave. The current list of who has access, and what each may do, is maintained "
                 . "on the Access review page and is reviewed as part of the organisation's review cycle.";

        return implode("\n", $lines);
    }

    // ---- Roles and responsibilities: from the governance register. ----
    private static function fromGovernance(string $tenantId): string
    {
        $people = DB::table('tenant_people')->where('tenant_id', $tenantId)
            ->where('active', true)->orderBy('name')->get();

        if ($people->isEmpty()) {
            return "## Roles and responsibilities\n\n[No people have been recorded. Add them on the "
                 . "People and approvals page.]";
        }

        $out = ["## Roles and responsibilities", "",
            "The following people hold defined responsibilities for information security.", "",
            "| Name | Role |", "|---|---|"];

        foreach ($people as $p) {
            $out[] = "| {$p->name} | {$p->title} |";
        }

        return implode("\n", $out);
    }

    // ---- Monthly Security Report (9.1 Monitoring, measurement, analysis and evaluation) ----
    // Built from live data, so it is verifiable and reproducible — not improvised prose.
    private static function fromMonthlyReport(string $tenantId, array $profile): string
    {
        $org = $profile['legal_name'] ?: '[Organisation]';
        $period = now()->subMonth()->format('F Y');

        // --- Risk position (deterministic, from the risk engine) ---
        $dist = DB::table('findings')->where('tenant_id', $tenantId)
            ->selectRaw('criticality, count(*) c')->groupBy('criticality')->pluck('c', 'criticality');

        $total     = (int) $dist->sum();
        $urgent    = ($dist[5] ?? 0) + ($dist[4] ?? 0);
        $planned   = $dist[3] ?? 0;
        $low       = $dist[2] ?? 0;
        $info      = $dist[1] ?? 0;
        $actionable = $urgent + $planned;

        $out = [];
        $out[] = "## Executive summary";
        $out[] = "";
        $out[] = "This report covers the security position of {$org} for {$period}.";
        $out[] = "";
        $out[] = "We assessed **{$total}** findings across the organisation's systems. "
               . "Of these, **{$actionable}** require action. The remainder are low priority or informational.";
        $out[] = "";

        if ($urgent > 0) {
            $out[] = "**{$urgent} finding(s) are urgent.** These are vulnerabilities that attackers are known "
                   . "to be exploiting, or are highly likely to exploit. They should be addressed immediately.";
        } else {
            $out[] = "**No urgent issues.** Nothing we found is being actively exploited by attackers, "
                   . "and nothing is judged highly likely to be exploited in the near term.";
        }
        $out[] = "";

        // --- How we prioritise (the auditor will ask) ---
        $out[] = "## How risks are prioritised";
        $out[] = "";
        $out[] = "Findings are ranked by real-world exploitation risk, not technical severity alone. "
               . "We use CISA's Known Exploited Vulnerabilities catalogue (is this being exploited today?) "
               . "and FIRST's EPSS scores (how likely is exploitation in the next 30 days?), combined with "
               . "whether the affected system is reachable from the internet. "
               . "A high technical severity that nobody is exploiting is not treated as an emergency.";
        $out[] = "";

        // --- What needs action ---
        $risks = DB::table('findings')->where('tenant_id', $tenantId)
            ->where('criticality', '>=', 3)
            ->orderByDesc('criticality')->limit(25)->get();

        $out[] = "## Findings requiring action";
        $out[] = "";
        if ($risks->isEmpty()) {
            $out[] = "None. No finding currently meets the threshold for action.";
        } else {
            $out[] = "| Finding | Priority | Why it matters |";
            $out[] = "|---|---|---|";
            foreach ($risks as $r) {
                $f = json_decode($r->risk_factors ?? '[]', true) ?: [];
                $why = collect($f)->pluck('meaning')->implode(' ');
                $why = str_replace('|', '-', mb_substr($why, 0, 180));
                $name = str_replace('|', '-', $r->name);
                $label = RiskEngine::label($r->criticality);
                $out[] = "| {$name} | {$label} | {$why} |";
            }
        }
        $out[] = "";

        // --- The breakdown ---
        $out[] = "## Full breakdown";
        $out[] = "";
        $out[] = "| Priority | Count | Meaning |";
        $out[] = "|---|---|---|";
        $out[] = "| Fix now | " . ($dist[5] ?? 0) . " | Being actively exploited by attackers |";
        $out[] = "| Fix this week | " . ($dist[4] ?? 0) . " | Highly likely to be exploited |";
        $out[] = "| Plan a fix | {$planned} | Serious, but not being exploited |";
        $out[] = "| Low priority | {$low} | Real, but low risk |";
        $out[] = "| Informational | {$info} | Not a security risk |";
        $out[] = "";

        // --- Monitoring ---
        $monitored = DB::table('tenant_artifacts')->where('tenant_id', $tenantId)
            ->where('tool', 'wazuh')->where('artifact_type', 'agent_group')->exists();
        $lastScan = DB::table('scans')->where('tenant_id', $tenantId)
            ->where('status', 'done')->max('finished_at');

        $out[] = "## Monitoring and assurance";
        $out[] = "";
        $out[] = "- Continuous security monitoring: " . ($monitored ? "in place" : "**not yet configured**");
        $out[] = "- Most recent assessment: " . ($lastScan ? substr((string) $lastScan, 0, 10) : "**none recorded**");
        $out[] = "";

        // --- Compliance position (honest) ---
        $frameworks = DB::table('tenant_frameworks')->where('tenant_id', $tenantId)->pluck('framework_name');
        $docsFinal = DB::table('tenant_documents')->where('tenant_id', $tenantId)
            ->where('status', 'final')->distinct()->count('doc_key');

        $out[] = "## Compliance position";
        $out[] = "";
        if ($frameworks->isEmpty()) {
            $out[] = "No compliance standard has been selected yet.";
        } else {
            $out[] = "Working toward: " . $frameworks->implode(', ') . ".";
            $out[] = "";
            $out[] = "**{$docsFinal}** required document(s) have been completed and approved.";
        }

        // --- Gaps, stated plainly ---
        $missing = \App\Support\TenantProfile::missing($tenantId);
        $people = DB::table('tenant_people')->where('tenant_id', $tenantId)->count();

        $gaps = [];
        if (!empty($missing)) {
            $gaps[] = "The organisation profile is incomplete (missing: " . implode(', ', $missing) . ").";
        }
        if ($people === 0) {
            $gaps[] = "No people are registered to prepare, review or approve security documents. "
                    . "An auditor will expect named individuals.";
        }
        if (!$monitored) {
            $gaps[] = "Continuous security monitoring is not yet configured on the organisation's devices.";
        }

        if ($gaps) {
            $out[] = "";
            $out[] = "## Gaps requiring management attention";
            $out[] = "";
            foreach ($gaps as $g) {
                $out[] = "- {$g}";
            }
        }

        // --- Recommended actions ---
        $out[] = "";
        $out[] = "## Recommended actions";
        $out[] = "";
        if ($urgent > 0) {
            $out[] = "1. Address the {$urgent} urgent finding(s) immediately.";
        }
        if ($planned > 0) {
            $out[] = "2. Schedule remediation for the {$planned} finding(s) marked 'Plan a fix'.";
        }
        foreach ($gaps as $i => $g) {
            $out[] = ($i + 3) . ". " . $g;
        }
        if (!$urgent && !$planned && !$gaps) {
            $out[] = "No action is required this period. Maintain the current monitoring and review cycle.";
        }

        return implode("\n", $out);
    }

    // ---- Organisation overview / ISMS scope: from the profile. ----
    private static function fromProfile(array $p): string
    {
        return "## The organisation\n\n"
             . "{$p['legal_name']}"
             . ($p['trading_name'] && $p['trading_name'] !== $p['legal_name'] ? " (trading as {$p['trading_name']})" : '')
             . ($p['sector'] ? ", operating in the {$p['sector']} sector" : '')
             . ($p['country'] ? ", based in {$p['country']}" : '') . ".\n\n"
             . ($p['headcount'] ? "The organisation has approximately {$p['headcount']} staff.\n\n" : '')
             . "## Scope of the information security management system\n\n"
             . ($p['isms_scope'] ?: '[The scope has not yet been defined. Brian can draft one — see Your organisation.]')
             . "\n\n## Responsibility\n\n"
             . "Overall responsibility for information security rests with "
             . ($p['security_officer_name'] ?: '[not yet assigned]')
             . ($p['security_officer_title'] ? ", {$p['security_officer_title']}" : '') . ".";
    }

    // ---- Logging and monitoring: from Wazuh. ----
    private static function fromWazuh(string $tenantId): string
    {
        $group = DB::table('tenant_artifacts')->where('tenant_id', $tenantId)
            ->where('tool', 'wazuh')->where('artifact_type', 'agent_group')->value('external_id');

        if (!$group) {
            return "## Logging and monitoring\n\n[Security monitoring is not yet configured. Once your devices "
                 . "report to the monitoring system, this policy will reflect what is actually being collected.]";
        }

        return "## Logging and monitoring\n\n"
             . "Security-relevant events are collected continuously from enrolled devices and analysed for signs "
             . "of compromise, misconfiguration and policy violation.\n\n"
             . "## What is monitored\n\n"
             . "Enrolled devices report system events, authentication activity, and configuration state. These are "
             . "assessed against recognised hardening benchmarks, and the resulting security posture is reported on "
             . "the Security posture page.\n\n"
             . "## Review\n\n"
             . "Monitoring output is reviewed by the person responsible for information security, and material "
             . "findings are treated as incidents.";
    }

    private static function fromTickets(string $tenantId): string
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('tickets')) {
            return "## Incident log\n\n[No incident tracking system is configured yet. Once the help desk is "
                 . "in place, every incident — with its date, owner, and resolution — will appear here "
                 . "automatically, and will serve as your evidence for incident management.]";
        }

        $tickets = DB::table('tickets')->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')->limit(100)->get();

        if ($tickets->isEmpty()) {
            return "## Incident log\n\nNo incidents have been recorded in this period.";
        }

        $out = ["## Incident and corrective action log", "",
            "| Reference | Raised | Summary | Status | Resolved |", "|---|---|---|---|---|"];

        foreach ($tickets as $t) {
            $out[] = "| {$t->number} | " . substr((string) $t->created_at, 0, 10) . " | "
                   . str_replace('|', '-', $t->subject ?? '') . " | {$t->status} | "
                   . (substr((string) ($t->resolved_at ?? ''), 0, 10) ?: '—') . " |";
        }

        return implode("\n", $out);
    }

    // ---- Web Application Firewall evidence (A.8.20, A.8.26, A.8.16) ----
    // Real operating evidence — which servers are protected, in which mode — not a policy statement.
    private static function fromWaf(string $tenantId): string
    {
        $servers = DB::table('waf_deployments')->where('tenant_id', $tenantId)->get();

        if ($servers->isEmpty()) {
            return "## Web application firewall\n\n[No web servers have been registered for protection yet. "
                 . "Add them on the Web firewall page and this document will reflect what is actually deployed.]";
        }

        $out = [
            "## Web application firewall",
            "",
            "The organisation protects its web applications with a web application firewall (ModSecurity) "
            . "running the OWASP Core Rule Set. The firewall runs on the organisation's own infrastructure; "
            . "web traffic is not routed through any third party.",
            "",
            "## Protected servers",
            "",
            "| Application | Address | Server | Firewall status | Rule set |",
            "|---|---|---|---|---|",
        ];

        $protected = 0;
        foreach ($servers as $s) {
            $status = match ($s->mode) {
                'blocking'  => 'Active — blocking attacks',
                'detection' => 'Monitoring — recording, not yet blocking',
                default     => 'Not yet installed',
            };
            if ($s->mode === 'blocking') $protected++;

            $label = str_replace('|', '-', $s->label);
            $host  = str_replace('|', '-', $s->hostname);
            $crs   = $s->crs_version ? "v{$s->crs_version}" : '—';
            $out[] = "| {$label} | {$host} | " . strtoupper($s->server_type) . " | {$status} | {$crs} |";
        }

        $out[] = "";
        $out[] = "## Summary";
        $out[] = "";
        $out[] = "{$protected} of {$servers->count()} registered server(s) are actively blocking attacks.";

        // Honest about the ones that are only watching.
        $watching = $servers->where('mode', 'detection')->count();
        if ($watching > 0) {
            $out[] = "";
            $out[] = "**{$watching} server(s) are in monitoring mode** — the firewall is recording attacks but "
                   . "not yet blocking them, while rules are tuned to avoid disrupting legitimate users. This is "
                   . "a deliberate stage, not a gap.";
        }

        $pending = $servers->where('mode', 'pending')->count();
        if ($pending > 0) {
            $out[] = "";
            $out[] = "**{$pending} server(s) are registered but not yet protected.** The firewall has not been "
                   . "installed on them. These should be addressed.";
        }

        return implode("\n", $out);
    }

    // ---- Logging, monitoring, malware and incident evidence, from LIVE alerts ----
    // This is the difference between "we have a logging policy" and
    // "here is what our logging caught." An auditor tests the second.
    private static function fromWazuhAlerts(string $tenantId): string
    {
        $group = DB::table('tenant_artifacts')->where('tenant_id', $tenantId)
            ->where('tool', 'wazuh')->where('artifact_type', 'agent_group')->value('external_id');

        if (!$group) {
            return "## Logging and monitoring\n\n[Security monitoring is not configured yet. Once your "
                 . "devices report to the monitoring system, this document will show what it actually caught.]";
        }

        try {
            $c = app(\App\Clients\WazuhClient::class);

            if (!$c->indexerHealthy()) {
                return "## Logging and monitoring\n\n[The alert records cannot be reached, so we cannot "
                     . "confirm what monitoring has caught. This document must not be relied upon until "
                     . "that is resolved.]";
            }

            $token = $c->token();
            $ids = $c->agentIdsInGroup($token, $group);

            if (empty($ids)) {
                return "## Logging and monitoring\n\n[Monitoring is configured, but **no devices are "
                     . "reporting to it**. Nothing is being watched. This is a gap an auditor will find.]";
            }

            $alerts = $c->alertsForAgents($ids, 30, 500);
        } catch (\Throwable $e) {
            return "## Logging and monitoring\n\n[The monitoring system could not be reached.]";
        }

        $n = count($alerts);
        $devices = count($ids);

        // Break down by what the alert actually is.
        $byGroup = [];
        $high = 0;
        foreach ($alerts as $a) {
            $lvl = (int) ($a['rule']['level'] ?? 0);
            if ($lvl >= 12) $high++;
            foreach (($a['rule']['groups'] ?? []) as $g) {
                $byGroup[$g] = ($byGroup[$g] ?? 0) + 1;
            }
        }


        arsort($byGroup);

        $out = [
            "## Logging and monitoring",
            "",
            "Security events are collected continuously from **{$devices} device(s)** and analysed "
            . "automatically for signs of compromise, misconfiguration and policy violation.",
            "",
            "## What monitoring caught (last 30 days)",
            "",
            "**{$n} security event(s)** were recorded and analysed.",
        ];

        if ($high > 0) {
            $out[] = "";
            $out[] = "**{$high} of these were serious** and warrant review.";
        }

        if ($byGroup) {
            $out[] = "";
            $out[] = "| Type of event | Count |";
            $out[] = "|---|---|";
            foreach (array_slice($byGroup, 0, 12, true) as $g => $count) {
                $out[] = "| " . str_replace('|', '-', $g) . " | {$count} |";
            }
        }

        // The serious ones, named.
        $serious = collect($alerts)
            ->filter(fn ($a) => (int) ($a['rule']['level'] ?? 0) >= 10)
            ->take(15);

        if ($serious->isNotEmpty()) {
            $out[] = "";
            $out[] = "## Significant events";
            $out[] = "";
            $out[] = "| When | Device | What happened |";
            $out[] = "|---|---|---|";
            foreach ($serious as $a) {
                $when = substr((string) ($a['@timestamp'] ?? ''), 0, 19);
                $dev = str_replace('|', '-', $a['agent']['name'] ?? '?');
                $desc = str_replace('|', '-', $a['rule']['description'] ?? '');
                $out[] = "| {$when} | {$dev} | {$desc} |";
            }
        }

        $out[] = "";
        $out[] = "## How this is maintained";
        $out[] = "";
        $out[] = "Events are collected and analysed automatically and continuously — not by periodic manual "
               . "review. Records are retained in the monitoring system. Significant events are reviewed by "
               . "the person responsible for information security.";

        if ($n === 0) {
            $out[] = "";
            $out[] = "*No events were recorded in this period. If monitoring was only recently enabled, it "
                   . "may simply not have observed anything yet — an absence of alerts is not the same as "
                   . "an absence of risk.*";
        }

        return implode("\n", $out);
    }

    // Blocked Attacks Report (A.8.16 Monitoring, A.5.7 Threat intelligence).
    // Reports REAL blocks from the firewall, via Wazuh. This is operating evidence —
    // proof the WAF is doing something, not just installed.
    private static function fromWafBlocks(string $tenantId): string
    {
        $blocking = DB::table('waf_deployments')->where('tenant_id', $tenantId)
            ->where('mode', 'blocking')->count();

        if ($blocking === 0) {
            return "## Blocked attacks\n\n[No web firewall is actively blocking yet. Once a firewall is in "
                 . "blocking mode, every attack it stops will be recorded here as evidence. A firewall that is "
                 . "only watching, or not yet installed, cannot block anything.]";
        }

        // Pull WAF events from Wazuh, tenant-scoped by agent group.
        $group = DB::table('tenant_artifacts')->where('tenant_id', $tenantId)
            ->where('tool', 'wazuh')->where('artifact_type', 'agent_group')->value('external_id');

        if (!$group) {
            return "## Blocked attacks\n\n[Your firewall is blocking, but its logs are not yet connected to "
                 . "monitoring, so we cannot show what it has stopped. Connect monitoring to see the evidence.]";
        }

        try {
            $c = app(\App\Clients\WazuhClient::class);
            if (!$c->indexerHealthy()) {
                return "## Blocked attacks\n\n[The monitoring records cannot be reached right now, so this "
                     . "report cannot be produced. It must not be relied upon until that is resolved.]";
            }
            $ids = $c->agentIdsInGroup($c->token(), $group);
            $alerts = empty($ids) ? [] : $c->alertsForAgents($ids, 30, 500);
        } catch (\Throwable $e) {
            return "## Blocked attacks\n\n[Monitoring could not be reached.]";
        }

        // Keep only WAF/ModSecurity blocks.
        $blocks = collect($alerts)->filter(function ($a) {
            $groups = $a['rule']['groups'] ?? [];
            $desc = strtolower($a['rule']['description'] ?? '');
            return in_array('waf_blocked', $groups, true)
                || str_contains($desc, 'modsecurity')
                || str_contains($desc, 'obaits virtual patch');
        });

        $out = [
            "## Blocked attacks",
            "",
            "The organisation's web application firewall actively blocks attacks against its web applications. "
            . "The events below were recorded by monitoring over the last 30 days — each is an attack that was "
            . "stopped before it reached the application.",
            "",
        ];

        if ($blocks->isEmpty()) {
            $out[] = "**No attacks were blocked in this period.** The firewall is active; it simply has not yet "
                   . "had to stop anything. This is normal for a site that is not currently under attack.";
            return implode("\n", $out);
        }

        $out[] = "**{$blocks->count()} attack(s) were blocked.**";
        $out[] = "";
        $out[] = "| When | Target | What was blocked |";
        $out[] = "|---|---|---|";
        foreach ($blocks->take(30) as $a) {
            $when = substr((string) ($a['@timestamp'] ?? ''), 0, 19);
            $uri  = str_replace('|', '-', $a['data']['url'] ?? $a['url'] ?? '—');
            $what = str_replace('|', '-', $a['rule']['description'] ?? 'attack');
            $out[] = "| {$when} | {$uri} | {$what} |";
        }

        return implode("\n", $out);
    }

    // The Incident Log (A.5.25, A.5.26) — the register, from real recorded incidents.
    private static function fromIncidents(string $tenantId): string
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('tickets')) {
            return "## Incident log\n\n[The incident register is not available.]";
        }

        $incidents = DB::table('tickets')->where('tenant_id', $tenantId)
            ->where('is_incident', true)->orderByDesc('detected_at')->get();

        if ($incidents->isEmpty()) {
            return "## Incident log\n\n[No security incidents have been recorded. This is a genuine empty "
                 . "register, not a missing one: incidents are recorded here as they occur, and an auditor "
                 . "can see the mechanism exists even when nothing has happened yet.]";
        }

        $out = ["## Incident log", "",
            "Every security incident is recorded, with when it was detected, how serious it was, what was "
            . "done, and when it was resolved.", "",
            "| Ref | Detected | Severity | What happened | Status | Resolved |",
            "|---|---|---|---|---|---|"];

        foreach ($incidents as $i) {
            $ref = '#' . $i->number;
            $det = $i->detected_at ? substr($i->detected_at, 0, 16) : '—';
            $sev = ucfirst($i->incident_severity ?? '—');
            $sub = str_replace('|', '-', $i->subject);
            $st  = ucfirst(str_replace('_', ' ', $i->status));
            $res = $i->resolved_at ? substr($i->resolved_at, 0, 16) : '—';
            $out[] = "| {$ref} | {$det} | {$sev} | {$sub} | {$st} | {$res} |";
        }

        // The metric an auditor actually probes.
        $closed = $incidents->whereNotNull('resolved_at')->whereNotNull('detected_at');
        if ($closed->isNotEmpty()) {
            $hours = $closed->map(fn ($i) =>
                (strtotime($i->resolved_at) - strtotime($i->detected_at)) / 3600)->avg();
            $out[] = "";
            $out[] = "## Response time";
            $out[] = "";
            $out[] = "Average time from detecting an incident to resolving it: **"
                   . number_format($hours, 1) . " hours** across " . $closed->count() . " resolved incident(s).";
        }

        $open = $incidents->whereIn('status', ['open', 'in_progress'])->count();
        if ($open > 0) {
            $out[] = "";
            $out[] = "**{$open} incident(s) remain open.**";
        }

        return implode("\n", $out);
    }

    // Post-Incident Reviews (A.5.27) — the control auditors most often find missing.
    private static function fromIncidentReviews(string $tenantId): string
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('tickets')) {
            return "## Post-incident reviews\n\n[Not available.]";
        }

        $reviewed = DB::table('tickets')->where('tenant_id', $tenantId)
            ->where('is_incident', true)->whereNotNull('lessons_learned')
            ->orderByDesc('resolved_at')->get();

        $total = DB::table('tickets')->where('tenant_id', $tenantId)
            ->where('is_incident', true)->whereNotNull('resolved_at')->count();

        if ($reviewed->isEmpty()) {
            $msg = "## Post-incident reviews\n\n[No incident reviews have been recorded.";
            if ($total > 0) {
                $msg .= " **{$total} incident(s) have been resolved without recording what was learned.** "
                      . "ISO 27001 (A.5.27) expects you to capture the lesson from each incident so it does not "
                      . "recur. This is a gap an auditor will find.";
            } else {
                $msg .= " No incidents have been resolved yet.";
            }
            return $msg . "]";
        }

        $out = ["## Post-incident reviews", "",
            "After each incident, the organisation records why it happened and what will change as a result.", ""];

        foreach ($reviewed as $i) {
            $out[] = "### Incident #{$i->number}: " . $i->subject;
            $out[] = "";
            $out[] = "**Detected:** " . ($i->detected_at ? substr($i->detected_at, 0, 16) : '—')
                   . "  |  **Severity:** " . ucfirst($i->incident_severity ?? '—');
            $out[] = "";
            if ($i->root_cause) {
                $out[] = "**Why it happened:** " . $i->root_cause;
                $out[] = "";
            }
            $out[] = "**What we changed:** " . $i->lessons_learned;
            $out[] = "";
        }

        if ($total > $reviewed->count()) {
            $gap = $total - $reviewed->count();
            $out[] = "*{$gap} resolved incident(s) have no recorded lesson. Complete these to close the gap.*";
        }

        return implode("\n", $out);
    }
}