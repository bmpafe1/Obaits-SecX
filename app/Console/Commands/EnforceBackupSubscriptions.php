<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('backups:enforce')]
#[Description('Transition expired backup subscriptions through grace and read-only states')]
class EnforceBackupSubscriptions extends Command
{
    /** Days after expiry before backups stop and the account goes read-only. */
    public const GRACE_DAYS = 30;

    /** Days in read-only before the account is flagged for operator review. */
    public const READ_ONLY_DAYS = 60;

    public function handle(): int
    {
        $now = now();

        // 1. Expired, no grace set yet → begin grace. Backups keep running.
        DB::table('tenant_backup_subscriptions')
            ->where('status', 'active')
            ->where('expires_at', '<', $now)
            ->whereNull('grace_ends_at')
            ->get()->each(function ($sub) use ($now) {
                DB::table('tenant_backup_subscriptions')->where('id', $sub->id)
                    ->update([
                        'status'        => 'grace',
                        'grace_ends_at' => $now->copy()->addDays(self::GRACE_DAYS),
                        'updated_at'    => $now,
                    ]);

                \App\Support\Notifications::raiseAndEmail($sub->tenant_id,
                    __('Your Obaits backup storage has expired'), [
                        'level' => 'warning',
                        'body'  => __('Backups continue for :n more days. Renew now to avoid interruption.',
                                      ['n' => self::GRACE_DAYS]),
                        'link'  => '/portal/backups',
                    ]);
            });

        // 2. Grace elapsed → read-only. New backups stop; RESTORES STILL WORK.
        //    Quota is now genuinely downgraded here (was previously just an
        //    operator alert — credential persistence didn't exist when this
        //    command was first written; it does now). IDrive itself enforces
        //    read-only once usage exceeds the reduced quota, per
        //    IDriveE2Provider — we don't implement read-only ourselves.
        DB::table('tenant_backup_subscriptions')
            ->where('status', 'grace')
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<', $now)
            ->get()->each(function ($sub) use ($now) {
                DB::table('tenant_backup_subscriptions')->where('id', $sub->id)
                    ->update(['status' => 'read_only', 'updated_at' => $now]);

                $tenant = DB::table('tenants')->where('id', $sub->tenant_id)->first();

                $connId = DB::table('tenant_backup_connections')
                    ->where('tenant_id', $sub->tenant_id)->where('provider', 'idrive_e2')
                    ->value('id');

                if ($connId) {
                    try {
                        \App\Support\Backup\BackupProvisioner::revertToFreeTier($sub->tenant_id, $connId);
                    } catch (\Throwable $e) {
                        // Do NOT silently leave the subscription marked
                        // read_only while quota is still unlimited/paid-tier
                        // at IDrive — that's a real revenue leak. Alert
                        // rather than guess or retry blindly.
                        \App\Support\Health\OperatorAlert::send(
                            'Backup quota downgrade FAILED: ' . ($tenant->organization_name ?? $sub->tenant_id),
                            [['level' => 'critical', 'area' => 'backup',
                              'message' => "subscription={$sub->id} tenant={$sub->tenant_id} error=" . $e->getMessage()]]
                        );
                    }
                } else {
                    \App\Support\Health\OperatorAlert::send(
                        'Backup subscription read-only but no connection to downgrade: ' . ($tenant->organization_name ?? $sub->tenant_id),
                        [['level' => 'critical', 'area' => 'backup',
                          'message' => "subscription={$sub->id} tenant={$sub->tenant_id} has no idrive_e2 connection to apply the free-tier quota to."]]
                    );
                }

                \App\Support\Notifications::raiseAndEmail($sub->tenant_id,
                    __('Backups paused — your storage plan has lapsed'), [
                        'level' => 'warning',
                        'body'  => __('New backups have stopped, but your existing data is safe and you can still restore from it. Renew to resume.'),
                        'link'  => '/portal/backups',
                    ]);
            });

        // 3. Read-only past the retention window → flag for OPERATOR REVIEW.
        //    Deliberately NOT automated: deleting a customer's only off-site
        //    copy is irreversible and must be a human decision.
        DB::table('tenant_backup_subscriptions')
            ->where('status', 'read_only')
            ->where('grace_ends_at', '<', $now->copy()->subDays(self::READ_ONLY_DAYS))
            ->get()->each(function ($sub) {
                $tenant = DB::table('tenants')->where('id', $sub->tenant_id)->first();

                \App\Support\Health\OperatorAlert::send(
                    'Backup data past retention window: ' . ($tenant->organization_name ?? $sub->tenant_id),
                    [['level' => 'critical', 'area' => 'backup',
                      'message' => 'Subscription ' . $sub->id . ' has been read-only for '
                                 . self::READ_ONLY_DAYS . '+ days. Review before any deletion.']]
                );
            });

        return self::SUCCESS;
    }
}
