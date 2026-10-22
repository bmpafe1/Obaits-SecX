<?php
namespace App\Support;
 
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
 
class OperatorAudit
{
    public static function log(string $action, array $opts = []): void
    {
        DB::table('operator_audit')->insert([
            'id'               => (string) Str::uuid(),
            'operator_id'      => session('operator_id'),
            'actor'            => session('operator_email', 'unknown'),
            'action'           => $action,
            'target_tenant_id' => $opts['tenant_id'] ?? null,
            'reason'           => $opts['reason'] ?? null,
            'detail'           => isset($opts['detail']) ? json_encode($opts['detail']) : null,
            'ip'               => request()->ip(),
            'created_at'       => now(),
        ]);
    }
}
