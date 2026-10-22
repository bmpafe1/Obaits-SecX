<?php

namespace App\Livewire\Operator;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;

#[Layout('components.layouts.operator')]
class TenantDetail extends Component
{
    public string $tenantId;

    public string $newLimit = '';
    public string $limitReason = '';

    public function mount(string $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    /**
     * Change a tenant's device limit by hand.
     *
     * WHY THIS EXISTS: a customer blocked at their limit can normally pay and
     * unblock themselves in a minute. Not always -- a card that fails, a
     * mid-rollout at midnight, an agreed trial extension. Without this the only
     * remedy was an operator running a database query, which is both slow and
     * unrecorded.
     *
     * DOES NOT CHANGE THE TIER OR THE PERIOD. This is a goodwill grant or a
     * support action, not a sale. If the customer should be paying more, that
     * is a checkout, not an operator override -- conflating the two would make
     * the billing history untrue.
     *
     * A REASON IS REQUIRED. Raising a limit gives away something unpaid, and
     * "why does this tenant have 50 devices" needs an answer six months later.
     * Recorded in subscription_changes with the operator's identity, so it is
     * distinguishable from a customer purchase.
     */
    public function changeLimit()
    {
        $this->validate([
            'newLimit' => 'required|integer|min:1|max:100000',
            'limitReason' => 'required|string|min:8|max:300',
        ], [], ['newLimit' => __('device limit'), 'limitReason' => __('reason')]);

        $sub = DB::table('subscriptions')->where('tenant_id', $this->tenantId)->first();
        if (!$sub) {
            session()->flash('op_error', __('This tenant has no subscription record.'));
            return;
        }

        $new = (int) $this->newLimit;

        // Never below what is already enrolled -- the tenant would be silently
        // over their limit, unable to re-enrol a rebuilt machine.
        $inUse = DB::table('device_credentials')
            ->where('tenant_id', $this->tenantId)->whereNull('revoked_at')->count();

        if ($new < $inUse) {
            $this->addError('newLimit', __('They already have :n device(s) enrolled.', ['n' => $inUse]));
            return;
        }

        DB::table('subscriptions')->where('tenant_id', $this->tenantId)
            ->update(['asset_limit' => $new, 'updated_at' => now()]);

        DB::table('subscription_changes')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenantId,
            'old_asset_limit' => $sub->asset_limit,
            'new_asset_limit' => $new,
            'reason' => 'Operator adjustment: ' . $this->limitReason,
            'actor' => session('operator_email') ?? 'operator',
            'created_at' => now(),
        ]);

        \App\Support\OperatorAudit::log('operator.device_limit_changed', [
            'tenant_id' => $this->tenantId,
            'from' => $sub->asset_limit, 'to' => $new,
            'reason' => $this->limitReason,
        ]);

        // Billing-targeted: a limit change is a commercial matter, and
        // raiseAndEmail would send it to every analyst and viewer on the team.
        \App\Support\Notifications::raiseAndEmailBilling($this->tenantId,
            __('Your device limit has changed'), [
                'level' => 'info',
                'body'  => __('Your device limit is now :n. If this is unexpected, please contact us.', ['n' => $new]),
                'link'  => '/portal/billing',
            ]);

        $this->reset(['newLimit', 'limitReason']);
        session()->flash('op_ok', __('Device limit set to :n.', ['n' => $new]));
    }

    /**
     * Refund a subscription payment.
     *
     * Follows refundPayment() for backups: only a payment already recorded as
     * successful, only against a real charge id, and never on a pending or
     * failed row.
     *
     * DOES NOT FORCIBLY REDUCE THE DEVICE LIMIT. Refunding a payment that
     * raised a tenant to 25 devices might suggest dropping them back -- but if
     * they have since enrolled 20, dropping them to 10 leaves them over their
     * limit and unable to re-enrol a rebuilt machine. Taking the money back and
     * breaking their estate is two harms where one was intended.
     *
     * The limit is reduced only where it can be done without stranding anyone,
     * and the operator is told when it cannot. They can then use the limit
     * control deliberately.
     *
     * THE INVOICE IS MARKED 'refunded', not voided or deleted. The charge did
     * happen; an accounting record that pretends otherwise is worse than one
     * showing a refund.
     */
    public function refundSubscriptionPayment(string $paymentId)
    {
        $payment = DB::table('subscription_payments')->where('id', $paymentId)
            ->where('tenant_id', $this->tenantId)->where('status', 'success')->first();

        if (!$payment) {
            session()->flash('op_error', __('That payment cannot be refunded (not found or not successful).'));
            return;
        }

        $chargeId = json_decode($payment->raw_payload ?? '{}', true)['id'] ?? null;
        if (!$chargeId) {
            session()->flash('op_error', __('No Flutterwave charge id on record for this payment.'));
            return;
        }

        $flw = new \App\Support\Payments\FlutterwaveClient();
        try {
            $flw->refundCharge(
                $chargeId,
                (int) $payment->amount_usd_cents,
                \App\Support\Payments\FlutterwaveClient::REFUND_REASON_REQUESTED_BY_CUSTOMER
            );
        } catch (\Throwable $e) {
            \App\Support\OperatorAudit::log('operator.subscription_refund_failed', [
                'tenant_id' => $this->tenantId, 'payment_id' => $paymentId,
                'charge_id' => $chargeId, 'error' => $e->getMessage(),
            ]);
            session()->flash('op_error', __('Refund failed at Flutterwave: ') . $e->getMessage());
            return;
        }

        DB::table('subscription_payments')->where('id', $paymentId)
            ->update(['status' => 'refunded', 'updated_at' => now()]);

        if ($payment->invoice_id) {
            DB::table('invoices')->where('id', $payment->invoice_id)->update([
                'status' => 'refunded', 'updated_at' => now(),
            ]);
        }

        // Roll the limit back to what it was before this purchase, but never
        // below what is currently enrolled.
        $change = DB::table('subscription_changes')
            ->where('tenant_id', $this->tenantId)
            ->where('invoice_id', $payment->invoice_id)
            ->orderByDesc('created_at')->first();

        $inUse = DB::table('device_credentials')
            ->where('tenant_id', $this->tenantId)->whereNull('revoked_at')->count();

        $note = '';
        if ($change && $change->old_asset_limit !== null) {
            if ($change->old_asset_limit >= $inUse) {
                DB::table('subscriptions')->where('tenant_id', $this->tenantId)
                    ->update(['asset_limit' => $change->old_asset_limit, 'updated_at' => now()]);

                DB::table('subscription_changes')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'tenant_id' => $this->tenantId,
                    'invoice_id' => $payment->invoice_id,
                    'old_asset_limit' => $change->new_asset_limit,
                    'new_asset_limit' => $change->old_asset_limit,
                    'reason' => 'Payment refunded; limit rolled back',
                    'actor' => session('operator_email') ?? 'operator',
                    'created_at' => now(),
                ]);
                $note = __(' Device limit rolled back to :n.', ['n' => $change->old_asset_limit]);
            } else {
                $note = __(' Limit NOT reduced: they have :n device(s) enrolled and would be left over the limit. Adjust it deliberately if needed.', ['n' => $inUse]);
            }
        }

        \App\Support\OperatorAudit::log('operator.subscription_refunded', [
            'tenant_id' => $this->tenantId, 'payment_id' => $paymentId,
            'charge_id' => $chargeId, 'amount_cents' => $payment->amount_usd_cents,
        ]);

        \App\Support\Notifications::raiseAndEmailBilling($this->tenantId,
            __('Your payment has been refunded'), [
                'level' => 'info',
                'body'  => __('We have refunded $:amount. It may take a few days to appear on your statement.', [
                    'amount' => number_format($payment->amount_usd_cents / 100, 2),
                ]),
                'link'  => '/portal/billing',
            ]);

        session()->flash('op_ok', __('Payment refunded.') . $note);
    }

    public function suspend()
    {
        DB::table('tenants')->where('id', $this->tenantId)
            ->update(['status' => 'suspended', 'updated_at' => now()]);
        \App\Support\OperatorAudit::log('operator.tenant_suspended', ['tenant_id' => $this->tenantId]);
        session()->flash('op_ok', __('Tenant suspended.'));
    }

    public function reactivate()
    {
        DB::table('tenants')->where('id', $this->tenantId)
            ->update(['status' => 'active', 'updated_at' => now()]);
        \App\Support\OperatorAudit::log('operator.tenant_reactivated', ['tenant_id' => $this->tenantId]);
        session()->flash('op_ok', __('Tenant reactivated.'));
    }

    /**
     * Reverse a completed Flutterwave payment: refund at the provider,
     * mark the local record refunded, and revert the tenant's IDrive quota
     * back to the 10GB free tier. Only ever acts on a payment already
     * recorded as 'success' — never on a pending/failed row, and never
     * without a real charge id to refund against at Flutterwave.
     */
    public function refundPayment(string $paymentId)
    {
        $payment = DB::table('backup_payments')->where('id', $paymentId)
            ->where('tenant_id', $this->tenantId)->where('status', 'success')->first();

        if (!$payment) {
            session()->flash('op_error', __('That payment cannot be refunded (not found or not successful).'));
            return;
        }

        $chargePayload = json_decode($payment->raw_payload, true);
        $chargeId = $chargePayload['id'] ?? null;

        if (!$chargeId) {
            session()->flash('op_error', __('No Flutterwave charge id on record for this payment.'));
            return;
        }

        $flw = new \App\Support\Payments\FlutterwaveClient();

        try {
            $refund = $flw->refundCharge(
                $chargeId,
                (int) $payment->amount_usd_cents,
                \App\Support\Payments\FlutterwaveClient::REFUND_REASON_REQUESTED_BY_CUSTOMER
            );
        } catch (\Throwable $e) {
            \App\Support\OperatorAudit::log('operator.refund_failed', [
                'tenant_id' => $this->tenantId, 'payment_id' => $paymentId,
                'charge_id' => $chargeId, 'error' => $e->getMessage(),
            ]);
            session()->flash('op_error', __('Refund failed at Flutterwave: ') . $e->getMessage());
            return;
        }

        DB::table('backup_payments')->where('id', $paymentId)->update([
            'status' => 'refunded',
            'updated_at' => now(),
        ]);

        // Revert to the free tier — this app has no concept of a partial/
        // stacked quota, so a refunded purchase reverts fully rather than
        // trying to compute a partial rollback.
        $connId = DB::table('tenant_backup_connections')
            ->where('tenant_id', $this->tenantId)->where('provider', 'idrive_e2')->value('id');
        if ($connId) {
            try {
                \App\Support\Backup\BackupProvisioner::revertToFreeTier($this->tenantId, $connId);
            } catch (\Throwable $e) {
                \App\Support\Health\OperatorAlert::send('Refund succeeded but quota revert FAILED', [
                    ['level' => 'critical', 'area' => 'payments',
                     'message' => "tenant_id={$this->tenantId} payment_id={$paymentId} error=" . $e->getMessage()],
                ]);
            }
        }

        \App\Support\OperatorAudit::log('operator.refund_issued', [
            'tenant_id' => $this->tenantId, 'payment_id' => $paymentId,
            'charge_id' => $chargeId, 'refund_id' => $refund['id'] ?? null,
        ]);

        \App\Support\Notifications::raiseAndEmail($this->tenantId, __('Refund processed'), [
            'level' => 'info',
            'body'  => __('Your payment has been refunded and your storage has reverted to the free tier.'),
            'link'  => '/portal/backups',
        ]);

        session()->flash('op_ok', __('Refund issued and storage reverted.'));
    }

    public function render()
    {
        $tenant = DB::table('tenants')->where('id', $this->tenantId)->first();
        if (!$tenant) { abort(404); }

        // Metadata only — never the tenant's actual data on this page.
        $stats = [
            'users'      => DB::table('tenant_users')->where('tenant_id', $this->tenantId)->count(),
            'findings'   => DB::table('findings')->where('tenant_id', $this->tenantId)->count(),
            'documents'  => DB::table('tenant_documents')->where('tenant_id', $this->tenantId)->count(),
            'scans'      => DB::table('scans')->where('tenant_id', $this->tenantId)->count(),
            'frameworks' => DB::table('tenant_frameworks')->where('tenant_id', $this->tenantId)->count(),
        ];

        // Subscription state. COUNT only, never hostnames -- this page shows
        // metadata about a tenant, not their data.
        $subscription = DB::table('subscriptions')->where('tenant_id', $this->tenantId)->first();

        $subPayments = DB::table('subscription_payments as p')
            ->leftJoin('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->where('p.tenant_id', $this->tenantId)
            ->orderByDesc('p.created_at')->limit(12)
            ->get(['p.id', 'p.status', 'p.amount_usd_cents', 'p.paid_at', 'p.created_at', 'i.number as invoice_number']);
        $devicesUsed = DB::table('device_credentials')
            ->where('tenant_id', $this->tenantId)->whereNull('revoked_at')->count();

        $domains = DB::table('tenant_domains')->where('tenant_id', $this->tenantId)->get();

        // Recent operator access to THIS tenant — visible accountability.
        $access = DB::table('operator_audit')
            ->where('target_tenant_id', $this->tenantId)
            ->orderByDesc('created_at')->limit(10)->get();

        $payments = DB::table('backup_payments')->where('tenant_id', $this->tenantId)
            ->orderByDesc('created_at')->limit(20)->get();

        return view('livewire.operator.tenant-detail', compact('tenant', 'stats', 'domains', 'access', 'payments', 'subscription', 'devicesUsed', 'subPayments'))
            ->layout('components.layouts.app');
    }
}