<?php
namespace App\Support\Ai;

use Illuminate\Support\Facades\DB;

class TenantContext
{
    // Build a compact, SCRUBBED snapshot of the tenant's security posture for the model.
    public static function snapshot(string $tenantId): string
    {
        $lines = [];

        // --- The scale, stated explicitly. The model has no way to know 1 is LOW unless we say so. ---
        $lines[] = 'SCALE: criticality runs 1 to 5, where 5 is the MOST urgent and 1 is the LEAST urgent (informational noise, not a security risk).';

        // --- Counts by criticality, LABELLED so the numbers cannot be misread. ---
        $dist = DB::table('findings')->where('tenant_id', $tenantId)
            ->selectRaw('criticality, count(*) c')
            ->groupBy('criticality')
            ->pluck('c', 'criticality');

        $labels = [
            5 => 'Fix now (actively exploited by attackers)',
            4 => 'Fix this week (likely to be exploited)',
            3 => 'Plan a fix (serious, but not being exploited)',
            2 => 'Low priority',
            1 => 'Informational (NOT a security risk)',
        ];

        $lines[] = 'Breakdown of findings:';
        foreach ([5, 4, 3, 2, 1] as $level) {
            $count = $dist[$level] ?? 0;
            $lines[] = "- {$labels[$level]}: {$count} finding(s)";
        }

        $actionable = ($dist[5] ?? 0) + ($dist[4] ?? 0) + ($dist[3] ?? 0);
        $total = (int) $dist->sum();
        $lines[] = "IMPORTANT: of {$total} total findings, only {$actionable} need action. The rest are low priority or informational — do NOT alarm the user about them.";

        // Who we are talking to.
        $p = \App\Support\TenantProfile::for($tenantId);
        if ($p['legal_name']) {
            $lines[] = "Organisation: {$p['legal_name']}"
                . ($p['sector'] ? " ({$p['sector']})" : '')
                . ($p['country'] ? ", {$p['country']}" : '');
        }
        if ($p['security_officer_name']) {
            $lines[] = "Person responsible for information security: {$p['security_officer_name']}"
                . ($p['security_officer_title'] ? " ({$p['security_officer_title']})" : '');
        }
        $missing = \App\Support\TenantProfile::missing($tenantId);
        if ($missing) {
            $lines[] = 'Organisation profile is incomplete. Still missing: ' . implode(', ', $missing)
                . '. If the user asks about compliance documents, tell them to complete these on the "Your organisation" page first.';
        }


        // --- The findings that actually matter (criticality 3+), with their sourced reasoning. ---
        $crit = DB::table('findings')->where('tenant_id', $tenantId)
            ->where('criticality', '>=', 3)
            ->orderByDesc('criticality')
            ->limit(10)
            ->get();

        if ($crit->isEmpty()) {
            $lines[] = 'Findings needing attention: none. Nothing is being exploited or is likely to be.';
        } else {
            $lines[] = 'Findings needing attention (highest first):';
            foreach ($crit as $f) {
                $factors = json_decode($f->risk_factors ?? '[]', true) ?: [];
                $why = collect($factors)->pluck('meaning')->implode(' ');
                $ref = $f->cve ?: ($f->cwe ? "CWE-{$f->cwe}" : 'no reference');
                $lines[] = "- [criticality {$f->criticality} of 5] {$f->name} ({$ref}). Why: {$why}";
            }
        }

        // --- Security monitoring (Wazuh) ---
        $group = DB::table('tenant_artifacts')->where('tenant_id', $tenantId)
            ->where('tool', 'wazuh')->where('artifact_type', 'agent_group')->value('external_id');
        $lines[] = 'Security monitoring configured: ' . ($group ? 'yes' : 'not yet');

        // --- Access ---
        $userCount = DB::table('tenant_users')->where('tenant_id', $tenantId)->count();
        $lines[] = "People with access: {$userCount}";

        // --- Governance readiness (who can sign documents) ---
        $peopleCount = DB::table('tenant_people')->where('tenant_id', $tenantId)->count();
        $lines[] = "People registered for document approvals: {$peopleCount}";

        $configured = DB::table('document_approvals')->where('tenant_id', $tenantId)
            ->distinct()->count('document_type');
        $lines[] = "Document types with signatories set: {$configured} of " . count(\App\Support\DocumentTypes::all());

        $raw = implode("\n", $lines);

        // SCRUB before it can go to an external model.
        return Deidentifier::required() ? Deidentifier::scrub($raw) : $raw;
    }
}