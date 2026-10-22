<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Authenticates an on-premise installation calling home.
 *
 * The token is presented as a bearer header and stored hashed, the same way
 * device credentials are. An installation that has been suspended is refused
 * immediately -- suspension is the control that makes self-registration safe,
 * so it must be real rather than cosmetic.
 */
class InstallationAuth
{
    /** @return object|null The installation row, or null if unauthenticated. */
    public static function resolve(Request $request): ?object
    {
        $token = $request->bearerToken() ?: $request->header('X-Obaits-Installation-Token');
        if (!$token) {
            return null;
        }

        $install = DB::table('installations')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (!$install || $install->status !== 'active') {
            return null;
        }

        // Best-effort liveness. Never let a telemetry write fail a real request.
        try {
            DB::table('installations')->where('id', $install->id)
                ->update(['last_seen_at' => now()]);
        } catch (\Throwable $e) {
            // ignore
        }

        return $install;
    }
}
