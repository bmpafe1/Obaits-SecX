<?php

namespace App\Http\Controllers;

use App\Support\Backup\BackupProvisioner;
use App\Support\Payments\FlutterwaveClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backup storage checkout. Deliberately NOT Livewire — see routes/web.php
 * for why. Card fields (card_number, expiry_month, expiry_year, cvv) are
 * NEVER logged and NEVER persisted anywhere; they exist only as local
 * variables for the duration of charge(), passed straight into
 * FlutterwaveClient's AES-256-GCM encryption, then discarded.
 *
 * Attribution follows the pattern established for Backups::requestUpgrade():
 * a PENDING backup_payments row is created FIRST, with a reference we
 * generate and control, before any charge exists. The webhook reconciles
 * against this same row. This controller does NOT wait for the webhook to
 * apply quota on a successful synchronous charge — it applies immediately —
 * the webhook remains a safety net for anything that resolves asynchronously.
 *
 * AUTHORIZATION IS A CHAIN, NOT A SINGLE STEP. Confirmed empirically
 * 2026-07-25: a card charge can require PIN, and submitting the PIN can
 * itself move the charge to a FURTHER step (requires_otp) rather than
 * resolving. showAuthStep()/submitAuthStep() are generic and re-check
 * next_action after every submission, looping back to the same step
 * naturally for however many steps a real bank demands, rather than
 * hardcoding "PIN then done" — which is what the ORIGINAL version of this
 * controller did, and it silently misreported a PIN-then-OTP charge as
 * failed because it only ever expected one step.
 */
class BackupCheckoutController extends Controller
{
    public function show(Request $r, string $planId)
    {
        $plan = DB::table('backup_plans')->where('id', $planId)->where('active', true)->firstOrFail();
        // No admin_email column exists on tenants — session('auth_email') is
        // the established convention this codebase already uses everywhere
        // else (Backups.php, Access.php, Account.php, Compliance.php, ...).
        $tenantEmail = session('auth_email') ?? '';

        return view('backup-checkout.form', compact('plan', 'tenantEmail'));
    }

    public function charge(Request $r, string $planId)
    {
        $plan = DB::table('backup_plans')->where('id', $planId)->where('active', true)->firstOrFail();
        $tenantId = session('tenant_id');

        $validated = $r->validate([
            'card_number'  => ['required', 'string', 'regex:/^[0-9]{12,19}$/'],
            'expiry_month' => ['required', 'string', 'regex:/^[0-9]{1,2}$/'],
            'expiry_year'  => ['required', 'string', 'regex:/^[0-9]{2}$/'],
            'cvv'          => ['required', 'string', 'regex:/^[0-9]{3,4}$/'],
            'email'        => ['required', 'email'],
        ]);

        $reference = 'obaits-' . substr($tenantId, 0, 8) . '-' . substr((string) Str::uuid(), 0, 8);

        DB::table('backup_payments')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'plan_id' => $plan->id,
            'provider' => 'flutterwave',
            'provider_reference' => $reference,
            'amount_usd_cents' => $plan->price_usd_cents,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $flw = new FlutterwaveClient();

        try {
            $customer = $flw->createCustomer($validated['email']);

            // Card fields exist ONLY in this local scope, passed directly
            // into AES-256-GCM encryption inside createCardPaymentMethod().
            // Not stored in a variable beyond this call, not logged.
            $pm = $flw->createCardPaymentMethod(
                $validated['card_number'],
                str_pad($validated['expiry_month'], 2, '0', STR_PAD_LEFT),
                $validated['expiry_year'],
                $validated['cvv']
            );

            $chargeResult = $flw->createCharge(
                $pm['id'], $customer['id'], $plan->price_usd_cents, 'USD',
                $reference, route('backup-checkout.show', $plan->id)
            );
        } catch (\Throwable $e) {
            \Log::error('Backup checkout charge failed', ['reference' => $reference, 'error' => $e->getMessage()]);
            DB::table('backup_payments')->where('provider_reference', $reference)
                ->update(['status' => 'failure', 'updated_at' => now()]);
            return back()->withErrors(__('Your card could not be charged. Please check your details and try again.'));
        }

        $charge = $chargeResult['data'] ?? [];
        return $this->handleChargeResult($tenantId, $reference, $charge);
    }

    /**
     * Single decision point for what to do with a charge's CURRENT state —
     * called after creating a charge, and again after every authorization
     * step submission. This is what makes the chain generic: neither this
     * method nor the caller needs to know in advance how many steps there
     * will be.
     */
    private function handleChargeResult(string $tenantId, string $reference, array $charge)
    {
        $status = $charge['status'] ?? 'unknown';
        $chargeId = $charge['id'] ?? null;
        $nextAction = $charge['next_action'] ?? null;
        $actionType = $nextAction['type'] ?? null;

        // Redirect-based auth (3DS, or a payment method needing it) — no
        // further local step, the bank/scheme handles it externally.
        if ($actionType === 'redirect_url') {
            $url = $nextAction['redirect_url']['url'] ?? null;
            if ($url) {
                return redirect()->away($url);
            }
        }

        // Any next_action we know how to collect locally (currently:
        // requires_pin, requires_otp). Generic — a future third step type
        // is handled by adding its label to KNOWN_STEP_LABELS below, not by
        // adding a new branch here.
        if ($actionType && array_key_exists($actionType, self::KNOWN_STEP_LABELS)) {
            return redirect()->route('backup-checkout.show-auth-step', [$chargeId, $actionType]);
        }

        // An next_action type we've never seen and don't know how to
        // collect. Fail loudly and visibly rather than silently
        // misreporting a genuinely-pending charge as failed — this is
        // exactly the bug the original PIN-only version of this code had.
        if ($actionType) {
            \App\Support\Health\OperatorAlert::send('Flutterwave charge needs an unrecognised authorization step', [
                ['level' => 'critical', 'area' => 'payments',
                 'message' => "charge_id={$chargeId} reference={$reference} next_action.type={$actionType} — "
                            . 'no local handler for this step type. Charge left pending; check manually.'],
            ]);
            return back()->withErrors(__('This payment needs an extra confirmation step we do not yet support. Please contact support.'));
        }

        // No next_action at all — resolved (success or failure).
        return $this->finalizeCharge($tenantId, $reference, $status, $charge);
    }

    /** authorization step type => [label shown to the tenant, input name]. */
    private const KNOWN_STEP_LABELS = [
        'requires_pin' => ['label' => 'Card PIN', 'field' => 'pin'],
        'requires_otp' => ['label' => 'One-time code (OTP)', 'field' => 'otp'],
    ];

    public function showAuthStep(Request $r, string $chargeId, string $stepType)
    {
        if (!array_key_exists($stepType, self::KNOWN_STEP_LABELS)) {
            abort(404);
        }

        return view('backup-checkout.auth-step', [
            'chargeId' => $chargeId,
            'stepType' => $stepType,
            'label'    => self::KNOWN_STEP_LABELS[$stepType]['label'],
            'field'    => self::KNOWN_STEP_LABELS[$stepType]['field'],
        ]);
    }

    public function submitAuthStep(Request $r, string $chargeId, string $stepType)
    {
        if (!array_key_exists($stepType, self::KNOWN_STEP_LABELS)) {
            abort(404);
        }

        $field = self::KNOWN_STEP_LABELS[$stepType]['field'];
        $r->validate([$field => ['required', 'string', 'regex:/^[0-9]{4,8}$/']]);
        $value = $r->input($field);

        $flw = new FlutterwaveClient();

        try {
            $result = match ($stepType) {
                'requires_pin' => $flw->submitCardPin($chargeId, $value),
                'requires_otp' => $flw->submitCardOtp($chargeId, $value),
            };
        } catch (\Throwable $e) {
            \Log::error('Backup checkout auth step failed', ['charge_id' => $chargeId, 'step' => $stepType, 'error' => $e->getMessage()]);
            return back()->withErrors(__('That code was not accepted. Please try again.'));
        }

        $charge = $result['data'] ?? [];
        $reference = $charge['reference'] ?? null;
        $tenantId = session('tenant_id');

        if (!$reference) {
            return back()->withErrors(__('Could not confirm the payment. Please contact support.'));
        }

        // Re-check: this may resolve, or hand back yet ANOTHER next_action.
        return $this->handleChargeResult($tenantId, $reference, $charge);
    }

    /**
     * Terminal handling once a charge has genuinely resolved (no
     * next_action left at all). Updates the local payment record, and —
     * for a confirmed success — applies quota IMMEDIATELY rather than
     * waiting for the webhook, since we already hold the verified result
     * from Flutterwave's own direct response. The webhook remains the
     * source of truth for anything that resolves purely asynchronously,
     * and re-processing here is idempotent (status is simply set again).
     */
    private function finalizeCharge(string $tenantId, string $reference, string $status, array $charge)
    {
        $payment = DB::table('backup_payments')->where('provider_reference', $reference)->first();

        DB::table('backup_payments')->where('provider_reference', $reference)->update([
            'status' => $status === 'succeeded' ? 'success' : $status,
            'paid_at' => $status === 'succeeded' ? now() : null,
            'raw_payload' => json_encode($charge),
            'updated_at' => now(),
        ]);

        if ($status !== 'succeeded') {
            return redirect()->route('backup-checkout.show', $payment->plan_id ?? '')
                ->withErrors(__('The payment was not successful. Please try again.'));
        }

        if ($payment && $payment->plan_id) {
            $plan = DB::table('backup_plans')->where('id', $payment->plan_id)->first();
            $connId = DB::table('tenant_backup_connections')
                ->where('tenant_id', $tenantId)->where('provider', 'idrive_e2')->value('id');

            if ($connId && $plan) {
                try {
                    BackupProvisioner::applyQuota($tenantId, $connId, $plan->gb_size);
                } catch (\Throwable $e) {
                    \App\Support\Health\OperatorAlert::send('Checkout succeeded but quota application failed', [
                        ['level' => 'critical', 'area' => 'payments',
                         'message' => "tenant_id={$tenantId} reference={$reference} error=" . $e->getMessage()],
                    ]);
                }
            }
        }

        return redirect('/portal/backups')->with('acct_ok', __('Payment successful! Your storage has been upgraded.'));
    }
}
