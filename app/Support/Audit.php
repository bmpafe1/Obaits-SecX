<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Audit
{
    public static function log(
        ?string $tenantId, string $actor, string $action,
        string $result, ?string $tool = null, array $detail = []
    ): void {
        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'actor' => $actor,
            'action' => $action,
            'target_tool' => $tool,
            'result' => $result,
            'detail' => json_encode($detail),
            'created_at' => now(),
        ]);
    }
}