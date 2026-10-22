<?php

namespace App\Support\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Audit;

/**
 * Apply a subscription that has been paid for.
 *
 * SHARED DELIBERATELY. Two paths reach this -- the checkout controller when the
 * customer is present, and the Flutterwave webhook when the charge resolves
 * asynchronously. Duplicating the logic would mean two implementations of
 * "what does paying actually get you", and the day they drift is the day a
 * customer is charged for one thing and given another.
 *
 * DRIVEN BY THE INVOICE, NOT THE PAYLOAD. The invoice was written before the
 * charge existed, from a server-side quote. Nothing a payment provider sends
 * back decides what the customer receives -- a webhook body is attacker-
 * influenced input until proven otherwise, and even a verified one should not
 * be trusted to say how many devices someone bought.
 *
 * IDEMPOTENT. A duplicate webhook, or a customer refreshing the return page,
 * must not apply a plan twice or move a period end twice.
 */
class ApplyPaidSubscription
{
    /**
     * @throws \RuntimeException when the payment or its invoice cannot be found.
     */
    public static function fromReference(string $reference): void
    {
        $payment = DB::table('subscription_payments')
            ->where('provider_reference', $reference)->first();

        if (!$payment) {
            throw new \RuntimeException("No subscription payment for reference {$reference}");
        }

        $invoice = $payment->invoice_id
            ? DB::table('invoices')->where('id', $payment->invoice_id)->first()
            : null;

        if (!$invoice) {
            throw new \RuntimeException("Payment {$reference} has no invoice to apply");
        }

        // Already applied. The invoice being paid is the marker, because it is
        // set in the same transaction as the plan below.
        if ($invoice->status === 'paid') {
            return;
        }

        $line = json_decode($invoice->line_items ?? '[]', true)[0] ?? [];
        $devices = (int) ($line['new_total_devices'] ?? $line['quantity'] ?? 0);

        if ($devices < 1) {
            throw new \RuntimeException("Invoice {$invoice->number} does not say how many devices were bought");
        }

        $tenantId = $payment->tenant_id;
        $sub = DB::table('subscriptions')->where('tenant_id', $tenantId)->first();
        $prorated = (bool) ($line['prorated'] ?? false);

        // A prorated top-up keeps the existing period end -- they bought
        // devices, not more time. Otherwise the invoice's own period applies.
        $ends = $invoice->period_end;

        $values = [
            'tier' => 'paid',
            'status' => 'active',
            'asset_limit' => $devices,
            'current_period_ends_at' => $ends,
            'grace_ends_at' => null,
            'last_reminder_at' => null,
            'last_reminder_stage' => null,
            'updated_at' => now(),
        ];

        // Only a new period changes the billing cycle. A top-up does not --
        // getting this wrong once already produced a yearly cycle ending in a
        // month, and every later proration used the wrong daily rate.
        if (!$prorated) {
            $values['billing_cycle'] = (($line['months_charged'] ?? 1) > 1) ? 'yearly' : 'monthly';
        }

        DB::table('subscriptions')->where('tenant_id', $tenantId)->update($values);

        // Reversal of suspension. Credentials were never revoked, so access
        // returns immediately and no device needs re-enrolling.
        DB::table('tenants')->where('id', $tenantId)
            ->whereIn('status', ['suspended', 'license_expired'])
            ->update(['status' => 'active', 'updated_at' => now()]);

        DB::table('invoices')->where('id', $invoice->id)
            ->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);

        DB::table('subscription_changes')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'invoice_id' => $invoice->id,
            'old_asset_limit' => $sub->asset_limit ?? null,
            'new_asset_limit' => $devices,
            'old_tier' => $sub->tier ?? null, 'new_tier' => 'paid',
            'old_status' => $sub->status ?? null, 'new_status' => 'active',
            'reason' => $prorated
                ? 'Capacity added mid-period: ' . $devices . ' devices total (invoice #' . $invoice->number . ')'
                : 'Subscription paid: ' . $devices . ' devices (invoice #' . $invoice->number . ')',
            'actor' => session('auth_email') ?? 'webhook',
            'created_at' => now(),
        ]);

        // A receipt. A customer who pays and hears nothing has no confirmation
        // the transaction worked, which generates support contact -- and their
        // accountant needs the invoice link regardless.
        //
        // Sent here rather than in the controller so it goes out whether the
        // customer was present or the webhook resolved the charge later.
        \App\Support\Notifications::raiseAndEmailBilling($tenantId,
            __('Payment received — invoice #:number', ['number' => $invoice->number]),
            [
                'level' => 'info',
                'body'  => __('We have received your payment of $:amount. Your plan covers :n device(s) until :date.', [
                    'amount' => number_format($invoice->amount_usd_cents / 100, 2),
                    'n' => $devices,
                    'date' => \Carbon\Carbon::parse($ends)->toFormattedDateString(),
                ]),
                'link'  => '/portal/invoice/' . $invoice->id,
            ],
            // No extra recipient: subscription_payments does not record who
            // initiated the charge. The manage_billing holders are exactly the
            // people who could have made it, so they are the right audience --
            // adding a column purely to email one of them again would be noise.
            null
        );

        Audit::log($tenantId, 'billing', 'subscription.applied', 'success', null, [
            'invoice' => $invoice->number, 'devices' => $devices, 'prorated' => $prorated,
        ]);
    }
}
