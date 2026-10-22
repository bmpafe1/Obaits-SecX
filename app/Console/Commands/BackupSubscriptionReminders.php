<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('backups:remind')]
#[Description('Send renewal reminders for expiring tenant backup storage subscriptions')]
class BackupSubscriptionReminders extends Command
{
    public function handle(): int
    {
        $now = now();

        foreach ([30, 20, 7] as $days) {
            $start = $now->copy()->addDays($days)->startOfDay();
            $end   = $now->copy()->addDays($days)->endOfDay();

            DB::table('tenant_backup_subscriptions')
                ->whereIn('status', ['active', 'grace'])
                ->whereBetween('expires_at', [$start, $end])
                ->where(function ($q) use ($days) {
                    $q->whereNull('last_reminder_stage')
                      ->orWhere('last_reminder_stage', '!=', (string) $days);
                })
                ->get()->each(function ($sub) use ($days, $now) {
                    $tenant = DB::table('tenants')->where('id', $sub->tenant_id)->first();
                    if (!$tenant) return;

                    $gb = $sub->gb_purchased;
                    $label = $gb >= 1024 ? round($gb / 1024, 1) . ' TB' : $gb . ' GB';

                    $title = __('Your Obaits backup storage expires in :n days', ['n' => $days]);
                    $body  = __('Your :size backup plan ends on :date. Renew to keep your scheduled backups running.', [
                        'size' => $label,
                        'date' => \Illuminate\Support\Carbon::parse($sub->expires_at)->toFormattedDateString(),
                    ]);

                    \App\Support\Notifications::raiseAndEmail($sub->tenant_id, $title, [
                        'level' => $days <= 7 ? 'warning' : 'info',
                        'body'  => $body,
                        'link'  => '/portal/backups',
                    ]);

                    \App\Support\Health\OperatorAlert::send(
                        "Backup storage expiring in {$days} days: {$tenant->organization_name}",
                        [['level' => 'info', 'area' => 'backup',
                          'message' => "{$tenant->organization_name} ({$label}) expires {$sub->expires_at}"]]
                    );

                    DB::table('tenant_backup_subscriptions')->where('id', $sub->id)
                        ->update([
                            'last_reminder_stage' => (string) $days,
                            'last_reminder_at'    => $now,
                            'updated_at'          => $now,
                        ]);
                });
        }

        return self::SUCCESS;
    }
}
