<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('licences:enforce')]
#[Description('Command description')]
class EnforceLicences extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = now();
        $state = DB::table('license_fetch_state')->first();
 
        $okAt = $state?->last_fetch_ok_at ? \Illuminate\Support\Carbon::parse($state->last_fetch_ok_at) : null;
        $staleDays = $okAt ? $okAt->diffInDays($now) : 9999;
        $trustCache = $staleDays < \App\Support\Licensing\DropboxLicenceFetcher::STALE_CEILING_DAYS;
 
        if (!$trustCache) {
            \App\Support\Health\OperatorAlert::send(
                'Licence data is STALE (' . $staleDays . ' days since last successful fetch)',
                [['level' => 'critical', 'area' => 'licensing',
                  'message' => 'New lockouts paused until the licence file is reachable again.']]
            );
        }
 
        if ($trustCache) {
            // Expiry passed, no grace yet → begin 7-day grace.
            DB::table('license_cache')
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<', $now->toDateString())
                ->whereNull('grace_ends_at')
                ->get()->each(function ($lc) use ($now) {
                    DB::table('license_cache')->where('tenant_id', $lc->tenant_id)
                        ->update(['grace_ends_at' => $now->copy()->addDays(7), 'updated_at' => $now]);
                });
 
            // Grace elapsed → lock out (reversible status).
            DB::table('license_cache')
                ->whereNotNull('grace_ends_at')
                ->where('grace_ends_at', '<', $now)
                ->get()->each(function ($lc) use ($now) {
                    DB::table('tenants')->where('id', $lc->tenant_id)
                        ->whereNotIn('status', ['license_expired', 'suspended'])
                        ->update(['status' => 'license_expired', 'updated_at' => $now]);
                });
        }
 
        return self::SUCCESS;
    }

}
