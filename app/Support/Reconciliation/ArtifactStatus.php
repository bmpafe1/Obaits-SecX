<?php
namespace App\Support\Reconciliation;

use Illuminate\Support\Facades\DB;

class ArtifactStatus
{
    /**
     * The last verified status of a tenant's artifact.
     * Returns: 'verified' | 'missing' | 'unreachable' | null (never checked / not provisioned)
     */
    public static function statusFor(string $tenantId, string $tool, string $type): ?string
    {
        return DB::table('tenant_artifacts')
            ->where('tenant_id', $tenantId)          // ISOLATION
            ->where('tool', $tool)
            ->where('artifact_type', $type)
            ->value('verify_status');
    }

    /**
     * How stale is our knowledge? A verdict from three weeks ago is not reassurance.
     */
    public static function checkedAt(string $tenantId, string $tool, string $type): ?string
    {
        return DB::table('tenant_artifacts')
            ->where('tenant_id', $tenantId)
            ->where('tool', $tool)
            ->where('artifact_type', $type)
            ->value('verified_at');
    }
}