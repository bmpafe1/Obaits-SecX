<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Support\Notifications;
use App\Support\Audit;

/**
 * Move expired platform trials through grace and into suspension.
 *
 * WHY THIS EXISTS: trial_ends_at has been written since registration existed
 * and read by NOTHING. A trial simply never ended, so no customer was ever
 * asked to pay. That is the single most commercially consequential gap in the
 * platform, and it is this command that closes it.
 *
 * FOLLOWS EnforceBackupSubscriptions deliberately, rather than inventing a
 * fourth grace mechanism. There are already three in this codebase, reading
 * three different tables -- license_cache, tenant_backup_subscriptions, and
 * (vestigially) subscriptions. Matching the established shape means anyone who
 * understands backup expiry understands this immediately.
 *
 * WHAT SUSPENSION DOES AND DOES NOT DO. It sets tenants.status = 'suspended',
 * which RequireTenant already enforces on every request by flushing the session
 * and redirecting. That blocks PORTAL ACCESS and nothing else:
 *
 *   - device credentials are NOT revoked
 *   - threat-intelligence refresh keeps running on enrolled devices
 *   - no data is deleted
 *
 * A lapsed invoice is a commercial problem, not a reason to leave a customer's
 * estate unprotected -- and someone who pays on day nine must not have to
 * re-enrol forty machines. Reversal is a single status update.
 */
#[Signature('obaits:enforce-billing {--dry-run : report what would change without changing anything}')]
#[Description('Move expired trials and lapsed renewals through grace and into suspension, with reminders')]
class EnforceBilling extends Command
{
    /** Days after a trial expires before access is suspended. */
    public const GRACE_DAYS = 7;

    /** Days before expiry at which a reminder is sent. */
    public const REMIND_AT = [7, 3, 1];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $now = now();

        $this->remindBeforeExpiry($now, $dry);
        $this->remindBeforeRenewal($now, $dry);
        $this->beginGrace($now, $dry);
        $this->beginRenewalGrace($now, $dry);
        $this->suspendAfterGrace($now, $dry);

        return self::SUCCESS;
    }

    /**
     * Warn before the trial ends, not only after. A customer surprised by a
     * suspension had no chance to act; one warned three times did.
     */
    private function remindBeforeExpiry(\Illuminate\Support\Carbon $now, bool $dry): void
    {
        foreach (self::REMIND_AT as $days) {
            $target = $now->copy()->addDays($days)->toDateString();

            $due = DB::table('subscriptions as s')
                ->join('tenants as t', 't.id', '=', 's.tenant_id')
                ->where('s.status', 'trialing')
                ->whereDate('t.trial_ends_at', $target)
                // last_reminder_stage stops the same reminder going twice if
                // the command runs more than once in a day.
                ->where(function ($q) use ($days) {
                    $q->whereNull('s.last_reminder_stage')
                      ->orWhere('s.last_reminder_stage', '!=', (string) $days);
                })
                ->get(['s.id', 's.tenant_id', 't.organization_name']);

            foreach ($due as $sub) {
                $this->line("  reminder ({$days}d): {$sub->organization_name}");
                if ($dry) { continue; }

                Notifications::raiseAndEmailBilling($sub->tenant_id,
                    __('Your SecX trial ends in :n day(s)', ['n' => $days]), [
                        'level' => $days <= 1 ? 'warning' : 'info',
                        'body'  => __('Choose a plan to continue without interruption. Your devices stay enrolled and nothing is lost if you decide later.'),
                    ]);

                DB::table('subscriptions')->where('id', $sub->id)->update([
                    'last_reminder_at' => $now,
                    'last_reminder_stage' => (string) $days,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Trial expired, no grace set yet. Access CONTINUES during grace -- the
     * customer keeps working while they arrange payment.
     */
    private function beginGrace(\Illuminate\Support\Carbon $now, bool $dry): void
    {
        $expired = DB::table('subscriptions as s')
            ->join('tenants as t', 't.id', '=', 's.tenant_id')
            ->where('s.status', 'trialing')
            ->whereNotNull('t.trial_ends_at')
            ->where('t.trial_ends_at', '<', $now)
            ->whereNull('s.grace_ends_at')
            ->get(['s.id', 's.tenant_id', 't.organization_name']);

        foreach ($expired as $sub) {
            $this->warn("  grace begins: {$sub->organization_name}");
            if ($dry) { continue; }

            DB::table('subscriptions')->where('id', $sub->id)->update([
                'status' => 'past_due',
                'grace_ends_at' => $now->copy()->addDays(self::GRACE_DAYS),
                'updated_at' => $now,
            ]);

            DB::table('subscription_changes')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id' => $sub->tenant_id,
                'old_status' => 'trialing', 'new_status' => 'past_due',
                'reason' => 'Trial expired; ' . self::GRACE_DAYS . '-day grace began',
                'actor' => 'system',
                'created_at' => $now,
            ]);

            Notifications::raiseAndEmailBilling($sub->tenant_id,
                __('Your SecX trial has ended'), [
                    'level' => 'warning',
                    'body'  => __('Your access continues for :n more days. Choose a plan to keep using the platform — your devices stay enrolled throughout.', ['n' => self::GRACE_DAYS]),
                ]);

            Audit::log($sub->tenant_id, 'system', 'trial.grace_started', 'success', null,
                ['grace_days' => self::GRACE_DAYS]);
        }
    }

    /**
     * Warn a PAID subscription before its period ends.
     *
     * Separate from the trial reminder because it keys on
     * subscriptions.current_period_ends_at rather than tenants.trial_ends_at,
     * and because the message is different: a renewing customer is being asked
     * to continue, not to decide.
     */
    private function remindBeforeRenewal(\Illuminate\Support\Carbon $now, bool $dry): void
    {
        foreach (self::REMIND_AT as $days) {
            $target = $now->copy()->addDays($days)->toDateString();

            $due = DB::table('subscriptions as s')
                ->join('tenants as t', 't.id', '=', 's.tenant_id')
                ->where('s.status', 'active')
                ->whereDate('s.current_period_ends_at', $target)
                ->where(function ($q) use ($days) {
                    $q->whereNull('s.last_reminder_stage')
                      ->orWhere('s.last_reminder_stage', '!=', 'renew-' . $days);
                })
                ->get(['s.id', 's.tenant_id', 's.asset_limit', 's.billing_cycle', 't.organization_name']);

            foreach ($due as $sub) {
                $this->line("  renewal reminder ({$days}d): {$sub->organization_name}");
                if ($dry) { continue; }

                $amount = \App\Http\Controllers\SubscriptionCheckoutController::quote(
                    (int) $sub->asset_limit, $sub->billing_cycle ?: 'monthly'
                );

                Notifications::raiseAndEmailBilling($sub->tenant_id,
                    __('Your SecX plan renews in :n day(s)', ['n' => $days]), [
                        'level' => $days <= 1 ? 'warning' : 'info',
                        'body'  => __('Renew :n device(s) for $:amount to continue without interruption. You can change the number of devices before paying.', [
                            'n' => $sub->asset_limit,
                            'amount' => number_format($amount / 100, 2),
                        ]),
                    ]);

                DB::table('subscriptions')->where('id', $sub->id)->update([
                    'last_reminder_at' => $now,
                    'last_reminder_stage' => 'renew-' . $days,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * A paid period has ended. Issue the renewal invoice and begin grace.
     *
     * UNLIKE A TRIAL, SOMETHING IS ACTUALLY OWED HERE. The customer has had the
     * service and is continuing to use it during grace, so an invoice is raised
     * rather than only a prompt -- they need something to pay, and their
     * accountant needs a record.
     *
     * The invoice is for the device count they currently hold, which is the
     * continuation of what they bought. They can still choose fewer devices at
     * checkout if they no longer need them.
     */
    private function beginRenewalGrace(\Illuminate\Support\Carbon $now, bool $dry): void
    {
        $lapsed = DB::table('subscriptions as s')
            ->join('tenants as t', 't.id', '=', 's.tenant_id')
            ->where('s.status', 'active')
            ->whereNotNull('s.current_period_ends_at')
            ->where('s.current_period_ends_at', '<', $now)
            ->whereNull('s.grace_ends_at')
            ->get(['s.id', 's.tenant_id', 's.asset_limit', 's.pending_asset_limit', 's.billing_cycle', 't.organization_name']);

        foreach ($lapsed as $sub) {
            $this->warn("  renewal due: {$sub->organization_name}");
            if ($dry) { continue; }

            $cycle = $sub->billing_cycle ?: 'monthly';

            // A reduction requested mid-period takes effect NOW, at renewal --
            // the point at which the customer stops paying for devices they no
            // longer want. Until this moment they kept what they had paid for.
            $devices = $sub->pending_asset_limit !== null
                ? (int) $sub->pending_asset_limit
                : (int) $sub->asset_limit;

            $amount = \App\Http\Controllers\SubscriptionCheckoutController::quote($devices, $cycle);

            $number = (int) DB::table('invoices')->where('tenant_id', $sub->tenant_id)->max('number') + 1;
            $periodEnd = $cycle === 'yearly' ? $now->copy()->addYear() : $now->copy()->addMonth();

            DB::table('invoices')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id' => $sub->tenant_id,
                'number' => $number,
                'kind' => 'subscription',
                'status' => 'issued',
                'amount_usd_cents' => $amount,
                'line_items' => json_encode([[
                    'description' => __('Renewal: :n device(s), :cycle', ['n' => $devices, 'cycle' => $cycle]),
                    'quantity' => $devices,
                    'unit_cents' => \App\Http\Controllers\SubscriptionCheckoutController::PER_DEVICE_MONTHLY_CENTS,
                    'prorated' => false,
                    'total_cents' => $amount,
                ]]),
                'period_start' => $now->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'issued_at' => $now,
                'due_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            DB::table('subscriptions')->where('id', $sub->id)->update([
                'status' => 'past_due',
                'grace_ends_at' => $now->copy()->addDays(self::GRACE_DAYS),
                'last_reminder_stage' => null,
                // The reduction becomes the real limit and the pending value is
                // spent. Applied here rather than at payment, so a customer who
                // lapses is not left paying for devices they asked to drop.
                'asset_limit' => $devices,
                'pending_asset_limit' => null,
                'updated_at' => $now,
            ]);

            DB::table('subscription_changes')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id' => $sub->tenant_id,
                'old_status' => 'active', 'new_status' => 'past_due',
                'reason' => 'Period ended; renewal invoice #' . $number . ' issued, '
                            . self::GRACE_DAYS . '-day grace began',
                'actor' => 'system',
                'created_at' => $now,
            ]);

            // Billing-targeted, and linked to the invoice itself. Telling
            // someone an invoice exists without giving them the invoice is how
            // an invoice goes unpaid.
            Notifications::raiseAndEmailBilling($sub->tenant_id,
                __('Your SecX plan is due for renewal'), [
                    'level' => 'warning',
                    'body'  => __('Invoice #:number for $:amount is now due. Your access continues for :days more day(s) — your devices stay enrolled throughout.', [
                        'number' => $number,
                        'amount' => number_format($amount / 100, 2),
                        'days' => self::GRACE_DAYS,
                    ]),
                    'link'  => '/portal/billing',
                ]);

            Audit::log($sub->tenant_id, 'billing', 'subscription.renewal_due', 'success', null,
                ['invoice' => $number, 'amount_cents' => $amount]);
        }
    }

    /**
     * Grace elapsed. Suspend PORTAL ACCESS only -- see the class comment.
     */
    private function suspendAfterGrace(\Illuminate\Support\Carbon $now, bool $dry): void
    {
        $lapsed = DB::table('subscriptions as s')
            ->join('tenants as t', 't.id', '=', 's.tenant_id')
            ->where('s.status', 'past_due')
            ->whereNotNull('s.grace_ends_at')
            ->where('s.grace_ends_at', '<', $now)
            ->whereNotIn('t.status', ['suspended', 'license_expired'])
            ->get(['s.id', 's.tenant_id', 't.organization_name']);

        foreach ($lapsed as $sub) {
            $this->error("  SUSPENDING: {$sub->organization_name}");
            if ($dry) { continue; }

            DB::table('subscriptions')->where('id', $sub->id)
                ->update(['status' => 'suspended', 'updated_at' => $now]);

            // Reversible. Blocks the portal; touches nothing else.
            DB::table('tenants')->where('id', $sub->tenant_id)
                ->update(['status' => 'suspended', 'updated_at' => $now]);

            DB::table('subscription_changes')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id' => $sub->tenant_id,
                'old_status' => 'past_due', 'new_status' => 'suspended',
                'reason' => 'Grace period elapsed without payment',
                'actor' => 'system',
                'created_at' => $now,
            ]);

            Notifications::raiseAndEmailBilling($sub->tenant_id,
                __('Your SecX access has been suspended'), [
                    'level' => 'critical',
                    'body'  => __('Your devices remain enrolled and your data is untouched. Settle your account and access is restored immediately.'),
                ]);

            Audit::log($sub->tenant_id, 'system', 'trial.suspended', 'success', null, []);
        }
    }
}
