<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RequireTenant
{
    public function handle(Request $request, Closure $next)
    {
        if (!session('tenant_id')) {
            return redirect('/login');
        }

        // Enforce suspension on every request — an active session must not survive
        // the tenant being suspended. Cheap single-column lookup per request.
        $status = DB::table('tenants')->where('id', session('tenant_id'))->value('status');
        if ($status === 'suspended')        { session()->flush(); return redirect('/suspended'); }
        if ($status === 'license_expired')  { session()->flush(); return redirect('/license-expired'); }


        // Last seen, throttled to once an hour per user.
        //
        // DISTINCT FROM last_login_at, and the truer churn signal: a user who
        // authenticates once and stays signed in for a week looks dormant by
        // login date while actually using the platform every day. Dormancy is
        // the most commercially valuable signal in the telemetry register, so it
        // is worth measuring honestly.
        //
        // The hourly throttle keeps this to one write per active user per hour,
        // which is negligible against request volume -- and this sits on the
        // path just optimised from 791ms to 52ms, so it must stay cheap.
        //
        // Wrapped: telemetry must never break a page load.
        if ($uid = session('tenant_user_id')) {
            try {
                $key = 'seen:' . $uid;
                if (!cache()->has($key)) {
                    DB::table('tenant_users')->where('id', $uid)->update(['last_seen_at' => now()]);
                    cache()->put($key, true, now()->addHour());
                }
            } catch (\Throwable $e) {
                // Ignore -- a usage metric is never worth a failed request.
            }
        }

        // Which part of the platform they are using.
        //
        // audit_log cannot answer this: every action it records is a MACHINE
        // event -- scripts running, packages downloading, credentials issuing.
        // Not one records a person opening a page, which is by design and is
        // why this is separate.
        //
        // Written as daily aggregates, not a row per request, and the distinct
        // user write happens once per person per area per day behind a cache
        // check. This sits on the path taken from 791ms to 52ms.
        \App\Support\FeatureUsage::record(
            session('tenant_id'),
            session('tenant_user_id'),
            $request->path()
        );

        // One-time business-details step.
        //
        // FOLDED IN HERE rather than added as a second middleware because
        // 'tenant' is declared 42 separate times in routes/web.php. Adding a
        // second name to each is an edit that only has to be missed once for a
        // route to silently skip the check -- and this file already answers
        // "is this session ready to use the portal".
        //
        // Asked EXACTLY ONCE, tracked by business_details_completed_at. The
        // empty tenant_profiles table -- one blank row across four tenants --
        // is what optional collection looks like in practice, so this is a
        // redirect rather than a banner people learn to ignore.
        //
        // Never traps the user: the step itself, logout, account and help stay
        // reachable.
        if (!$request->is('portal/business-details', 'logout', 'portal/account', 'portal/help/*')) {
            $done = DB::table('tenant_profiles')
                ->where('tenant_id', session('tenant_id'))
                ->value('business_details_completed_at');

            if (!$done) {
                return redirect('/portal/business-details');
            }
        }

        return $next($request);
    }
}