<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Real API-key authentication for the External API. Reads
 * "Authorization: Bearer obaits_live_<64 hex chars>", hashes the
 * PRESENTED key (never the stored one -- we never have the plaintext
 * to compare against, matching real credential-storage discipline),
 * and looks up a matching, non-revoked row.
 *
 * On success, attaches the real, authenticated tenant_id to the
 * request -- every downstream controller reads tenant_id from HERE,
 * not from any other source, so the isolation boundary is enforced in
 * exactly one place, matching this project's own established pattern
 * (see WazuhClient::agentIdsInGroup's own real isolation comment).
 */
class AuthenticateApiKey
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

        $row = DB::table('api_keys')
            ->where('key_hash', $hash)
            ->whereNull('revoked_at')
            ->first();

        if (!$row) {
            return response()->json(['error' => 'Invalid or revoked API key.'], 401);
        }

        DB::table('api_keys')->where('id', $row->id)->update(['last_used_at' => now()]);

        // ISOLATION: every downstream handler reads the authenticated
        // tenant from here, not from any request parameter a caller
        // could tamper with.
        $request->attributes->set('api_tenant_id', $row->tenant_id);
        $request->attributes->set('api_key_id', $row->id);

        return $next($request);
    }
}
