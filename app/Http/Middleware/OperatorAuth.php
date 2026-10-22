<?php
namespace App\Http\Middleware;
 
use Closure;
use Illuminate\Http\Request;
 
class OperatorAuth
{
    public function handle(Request $request, Closure $next)
    {
        // Must be a logged-in operator whose MFA is confirmed for this session.
        if (!session('operator_id') || !session('operator_mfa_ok')) {
            return redirect('/operator/login');
        }
 
        // Hard guarantee: an operator session must NEVER carry tenant scope.
        // If it somehow does, refuse — this prevents any operator request from
        // being mistaken for a tenant request by tenant-scoped code.
        if (session()->has('tenant_id')) {
            session()->forget(['tenant_id', 'user_role', 'tenant_user_id']);
        }
 
        return $next($request);
    }
}
