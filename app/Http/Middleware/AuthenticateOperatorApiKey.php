<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Real API-key auth for the OPERATOR-level External API -- structurally
 * separate from AuthenticateApiKey (tenant keys), reading from a
 * genuinely separate table. An operator key is deliberately full-power
 * and cross-tenant, matching how every real, human operator already
 * works (see OperatorAuth's own real, hard guarantee that an operator
 * session NEVER carries tenant scope). This middleware attaches ONLY
 * an operator_id -- never any tenant_id, by design, so no downstream
 * code can mistake an operator-authenticated request for a
 * tenant-scoped one.
 */
class AuthenticateOperatorApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $header = $request->header('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return response()->json(['error' => 'Missing or malformed Authorization header. Expected: Bearer <api_key>'], 401);
        }
        $presentedKey = substr($header, 7);
        if (strlen($presentedKey) < 20) {
            return response()->json(['error' => 'Invalid API key format.'], 401);
        }
        $hash = hash('sha256', $presentedKey);
        $row = DB::table('operator_api_keys')
            ->where('key_hash', $hash)
            ->whereNull('revoked_at')
            ->first();
        if (!$row) {
            return response()->json(['error' => 'Invalid or revoked API key.'], 401);
        }
        DB::table('operator_api_keys')->where('id', $row->id)->update(['last_used_at' => now()]);
        $request->attributes->set('operator_api_key_id', $row->id);
        $request->attributes->set('operator_id', $row->operator_id);
        return $next($request);
    }
}
