<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('licences:remind')]
#[Description('Command description')]
class LicenceReminders extends Command
{
    /**
     * Execute the console command.
     */
     public function handle(): int
    {
        $now = now();
        foreach ([30, 20, 7] as $days) {
            $start = $now->copy()->addDays($days)->startOfDay();
            $end   = $now->copy()->addDays($days)->endOfDay();
 
            DB::table('license_cache')
                ->whereNotNull('expires_on')
                ->whereBetween('expires_on', [$start->toDateString(), $end->toDateString()])
                ->where(function ($q) use ($days) {
                    $q->whereNull('last_reminder_stage')->orWhere('last_reminder_stage', '!=', (string) $days);
                })
                ->get()->each(function ($lc) use ($days, $now) {
                    $tenant = DB::table('tenants')->where('id', $lc->tenant_id)->first();
                    if (!$tenant) return;
 
                    $title = __('Your Obaits licence expires in :n days', ['n' => $days]);
                    $body  = __('Your licence ends on :date. Renew to avoid interruption to your security monitoring.',
                                ['date' => \Illuminate\Support\Carbon::parse($lc->expires_on)->toFormattedDateString()]);
 
                    \App\Support\Notifications::raiseAndEmail($lc->tenant_id, $title, [
                        'level' => $days <= 7 ? 'warning' : 'info',
                        'body'  => $body,
                        'link'  => '/portal/account',
                    ]);
                    \App\Support\Health\OperatorAlert::send(
                        "Licence expiring in {$days} days: {$tenant->organization_name}",
                        [['level' => 'info', 'area' => 'licence',
                          'message' => "{$tenant->organization_name} expires {$lc->expires_on}"]]
                    );
 
                    DB::table('license_cache')->where('tenant_id', $lc->tenant_id)
                        ->update(['last_reminder_stage' => (string) $days, 'updated_at' => $now]);
                });
        }
        return self::SUCCESS;
    }

}
