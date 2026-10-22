<?php
namespace App\Support\Compliance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ComplianceSummary
{
    /**
     * A separate, honest count for EACH standard the tenant is pursuing.
     * Never a single blended number — that would be meaningless.
     */
    public static function perFramework(string $tenantId): array
    {
        $done = DB::table('tenant_documents')
            ->where('tenant_id', $tenantId)                     // ISOLATION
            ->whereIn('status', ['final', 'received'])
            ->pluck('doc_key')->unique();

        $inProgress = DB::table('tenant_documents')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['draft', 'sent_to_owner'])
            ->pluck('doc_key')->unique()
            ->diff($done);

        $out = [];

        foreach (DB::table('tenant_frameworks')->where('tenant_id', $tenantId)->get() as $fw) {

            // Documents that serve THIS framework.
            $keys = DB::table('document_control_map')
                ->where('framework_id', $fw->framework_id)
                ->pluck('doc_key')->unique();

            $docs = DB::table('compliance_documents')->whereIn('doc_key', $keys)->get();

            $byMode = [
                'automated' => $docs->where('mode', 'automated')->count(),
                'interview' => $docs->where('mode', 'interview')->count(),
                'delegate'  => $docs->where('mode', 'delegate')->count(),
                'guide'     => $docs->where('mode', 'guide')->count(),
            ];

            // Counter 1: documents we can help PRODUCE.
            $helpable = $byMode['automated'] + $byMode['interview'] + $byMode['delegate'];

            // Counter 2: controls with LIVE OPERATING EVIDENCE.
            // Only automated documents count — a policy is not proof.
            $proven = 0;
            foreach ($docs->where('mode', 'automated') as $d) {
                if (self::hasLiveData($tenantId, $d->source ?? '')) $proven++;
            }

            // How many of this framework's controls we reference at all.
            $refs = DB::table('document_control_map')
                ->where('framework_id', $fw->framework_id)
                ->pluck('control_ref')->unique()->count();

            // Unverified references — the platform must not present these as fact.
            $unverified = DB::table('document_control_map')
                ->where('framework_id', $fw->framework_id)
                ->where('verified', false)->count();

            $out[] = [
                'id' => $fw->framework_id,
                'name' => $fw->framework_name,
                'total' => $docs->count(),
                'helpable' => $helpable,
                'completed' => $done->intersect($keys)->count(),
                'in_progress' => $inProgress->intersect($keys)->count(),
                'by_mode' => $byMode,
                'evidence_proven' => $proven,
                'controls_referenced' => $refs,
                'unverified_refs' => $unverified,
            ];
        }

        return $out;
    }

    /**
     * Documents a tenant has already finished that ALSO satisfy a given framework.
     * This is the multi-framework payoff: "14 documents you wrote for ISO also cover PCI."
     */
     // Documents already finished that ALSO satisfy a newly added framework.
    public static function alreadyCovered(string $tenantId, string $frameworkId): array
    {
        $done = DB::table('tenant_documents')->where('tenant_id', $tenantId)
            ->whereIn('status', ['final', 'received'])->pluck('doc_key')->unique();
 
        $needed = DB::table('document_control_map')
            ->where('framework_id', $frameworkId)->pluck('doc_key')->unique();
 
        $shared = $done->intersect($needed);
 
        return DB::table('compliance_documents')->whereIn('doc_key', $shared)
            ->pluck('title')->all();
    }


    // Platform-wide: is anything claiming a control we have not verified?
    public static function unverifiedCount(): int
    {
        return DB::table('document_control_map')->where('verified', false)->count();
    }

    // Is there LIVE telemetry behind this source right now?
    private static function hasLiveData(string $tenantId, string $source): bool
    {
        return match ($source) {
            'wazuh_alerts' => self::wazuhActuallyMonitoring($tenantId),
            'waf' => DB::table('waf_deployments')->where('tenant_id', $tenantId)
                ->where('mode', 'blocking')->exists(),
            'waf_blocks' => DB::table('waf_deployments')->where('tenant_id', $tenantId)
                ->where('mode', 'blocking')->exists(),
            'findings' => DB::table('scans')->where('tenant_id', $tenantId)
                ->where('status', 'done')->exists(),
            'incidents' => \Illuminate\Support\Facades\Schema::hasTable('tickets')
                && DB::table('tickets')->where('tenant_id', $tenantId)->where('is_incident', true)->exists(),
            'incident_reviews' => \Illuminate\Support\Facades\Schema::hasTable('tickets')
                && DB::table('tickets')->where('tenant_id', $tenantId)
                    ->where('is_incident', true)->whereNotNull('lessons_learned')->exists(),
            'assets' => DB::table('tenant_artifacts')->where('tenant_id', $tenantId)
                ->where('tool', 'fleet')->where('artifact_type', 'enroll_secret')->exists(),
            'software' => DB::table('software_catalog')->where('tenant_id', $tenantId)->exists(),
            'access' => DB::table('tenant_users')->where('tenant_id', $tenantId)->exists(),
            'wazuh' => DB::table('tenant_artifacts')->where('tenant_id', $tenantId)
                ->where('tool', 'wazuh')->where('artifact_type', 'agent_group')->exists(),
            'governance' => DB::table('tenant_people')->where('tenant_id', $tenantId)->exists(),
            'profile' => !empty(\App\Support\TenantProfile::for($tenantId)['isms_scope']),
            'documents' => DB::table('tenant_documents')->where('tenant_id', $tenantId)->exists(),

            // The helpdesk is not built yet — the incident log has no source until it is.
            'tickets' => self::tableExists('tickets')
                && DB::table('tickets')->where('tenant_id', $tenantId)->exists(),

            'metrics', 'monthly_report' => DB::table('scans')->where('tenant_id', $tenantId)
                ->where('status', 'done')->exists(),
            'soa' => DB::table('tenant_frameworks')->where('tenant_id', $tenantId)->exists(),
            default => false,
        };
    }

    // Guard against sources whose backing table has not been built yet.
    private static function tableExists(string $table): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable($table);
    }

    /**
     * "Configured" is NOT "proven."
     * A monitoring system with no devices reporting proves nothing.
     * An unreachable alert store proves nothing.
     * Only actual, retrievable alerts from actual devices count as evidence.
     */
    private static function wazuhActuallyMonitoring(string $tenantId): bool
    {
        $group = DB::table('tenant_artifacts')->where('tenant_id', $tenantId)
            ->where('tool', 'wazuh')->where('artifact_type', 'agent_group')->value('external_id');

        if (!$group) return false;

        try {
            $c = app(\App\Clients\WazuhClient::class);
            if (!$c->indexerHealthy()) return false;

            $ids = $c->agentIdsInGroup($c->token(), $group);
            if (empty($ids)) return false;                 // nothing is being watched

            return count($c->alertsForAgents($ids, 30, 1)) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
