<?php
namespace App\Support;
 
class Perm
{
    // What each role may do.
    //
    // raise_marketplace_request is platform-admin ONLY, deliberately. Browsing
    // the Marketplace is open to everyone -- discovery should not be gated --
    // but submitting a request starts a commercial conversation with a third
    // party on the organisation's behalf and discloses whatever environment
    // facts the requester states. That is a commitment, not a query, and it
    // belongs with the people who can already manage billing and the team.
    private static array $matrix = [
        'platform-admin' => ['manage_team','manage_domains','run_scans','delete_scans','manage_software','manage_documents','view','manage_billing','manage_policies','enforce_policies','manage_api_keys','raise_marketplace_request'],
        'analyst'        => ['manage_domains','run_scans','manage_software','manage_documents','view','manage_policies'],
        'viewer'         => ['view'],
    ];
 
    /** Exposed so billing notifications can find who holds manage_billing. */
    public static function matrix(): array
    {
        return self::$matrix;
    }

    public static function can(string $ability): bool
    {
        $role = session('user_role', 'viewer');
        return in_array($ability, self::$matrix[$role] ?? []);
    }
 
    public static function require(string $ability): void
    {
        if (!self::can($ability)) {
            abort(403, 'You do not have permission for this action.');
        }
    }
}
