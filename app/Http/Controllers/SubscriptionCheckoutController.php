<?php

namespace App\Http\Controllers;

use App\Support\Payments\FlutterwaveClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Support\Audit;

/**
 * Platform subscription checkout.
 *
 * DELIBERATELY MODELLED ON BackupCheckoutController, not improvised. That
 * controller records three lessons in its own comments and all three apply
 * here unchanged:
 *
 *   1. AUTHORISATION IS A CHAIN, NOT A STEP. A card charge can require a PIN,
 *      and submitting the PIN can move the charge to a FURTHER step rather
 *      than resolving. The original backup controller hardcoded "PIN then
 *      done" and silently misreported a PIN-then-OTP charge as failed.
 *      handleChargeResult() is called after creating a charge AND after every
 *      step submission, so neither it nor the caller needs to know how many
 *      steps a real bank will demand.
 *
 *   2. PENDING ROW FIRST. A subscription_payments row is created with a
 *      reference we generate BEFORE any charge exists, so an interrupted
 *      charge is never orphaned and the webhook has something to reconcile.
 *
 *   3. CARD DATA IS NEVER LOGGED AND NEVER PERSISTED. Card fields exist only
 *      as local variables for the duration of charge(), passed straight into
 *      FlutterwaveClient's AES-256-GCM encryption, then discarded.
 *
 * ONE-OFF PER PERIOD, not recurring. Flutterwave can only tokenise CARDS, so
 * recurring would exclude mobile money entirely -- and automatic subsequent
 * charges additionally require the NOAUTH feature to be enabled on the account
 * by request to Flutterwave support, without which every renewal prompts the
 * customer for an OTP anyway. The payment method id IS stored, so recurring can
 * be added later without reworking this.
 */
class SubscriptionCheckoutController extends Controller
{
    /** Cents per device per month. Mirrors PricingCalculator. */
    public const PER_DEVICE_MONTHLY_CENTS = 300;

    /** Months charged on an annual plan -- two months free. */
    public const YEARLY_MONTHS_CHARGED = 10;

    public static function quote(int $devices, string $period): int
    {
        $devices = max(1, min(100000, $devices));
        $monthly = $devices * self::PER_DEVICE_MONTHLY_CENTS;

        return $period === 'yearly' ? $monthly * self::YEARLY_MONTHS_CHARGED : $monthly;
    }

    /**
     * What this purchase actually costs, given what the tenant already has.
     *
     * TWO CASES, and conflating them overcharges people:
     *
     *   NEW PERIOD -- a trial converting, or a period that has ended. A full
     *   period is charged and the period start moves to now.
     *
     *   MID-PERIOD CAPACITY -- an ACTIVE subscription adding devices with days
     *   still left. They have already paid for their existing devices for those
     *   days, so charging a full period again would bill them twice for the
     *   same time. Only the EXTRA devices are charged, and only for the days
     *   remaining. The period end does not move.
     *
     * Returns [amountCents, isProrated, extraDevices, daysRemaining, periodEnd].
     */
    public static function quoteFor(?object $sub, int $devices, string $period): array
    {
        $fullPeriodEnd = $period === 'yearly' ? now()->addYear() : now()->addMonth();

        $active = $sub
            && ($sub->status ?? null) === 'active'
            && !empty($sub->current_period_ends_at)
            && \Carbon\Carbon::parse($sub->current_period_ends_at)->isFuture();

        $ends = $active ? \Carbon\Carbon::parse($sub->current_period_ends_at) : null;

        // Not active: a full new period. Trial converting, or a period ended.
        if (!$active) {
            return [self::quote($devices, $period), false, 0, null, $fullPeriodEnd];
        }

        // REDUCING mid-period. Nothing to charge and nothing to refund: they
        // have already paid for the current period, so they keep what they have
        // until it ends and pay the lower amount at renewal.
        //
        // Charging a full period at the lower count -- which this did until
        // 2026-08-30 -- billed someone AGAIN for time they had already bought
        // and forfeited their remaining days. Going 21 to 10 on day 25 cost
        // \$30 and lost 5 days of the 21 devices they had paid for.
        if ($devices < (int) ($sub->asset_limit ?? 0)) {
            return [0, false, 0, null, $ends ?? $fullPeriodEnd];
        }

        // Same count on an active period: nothing to do either.
        if ($devices === (int) ($sub->asset_limit ?? 0)) {
            return [0, false, 0, null, \Carbon\Carbon::parse($sub->current_period_ends_at)];
        }

        $extra = $devices - (int) $sub->asset_limit;

        // Whole days remaining, at least one -- buying capacity on the last day
        // of a period should still cost something rather than nothing.
        $daysRemaining = max(1, (int) ceil(now()->floatDiffInDays($ends)));

        // Daily rate derived from the cycle they are ON, not the one selected:
        // a yearly subscriber adding devices pays the yearly daily rate.
        $cycle = $sub->billing_cycle ?: 'monthly';
        $daysInPeriod = $cycle === 'yearly' ? 365 : 30;
        $periodCentsPerDevice = $cycle === 'yearly'
            ? self::PER_DEVICE_MONTHLY_CENTS * self::YEARLY_MONTHS_CHARGED
            : self::PER_DEVICE_MONTHLY_CENTS;

        // Rounded UP so rounding never favours us by fractions of a cent.
        $amount = (int) ceil($extra * $periodCentsPerDevice * $daysRemaining / $daysInPeriod);

        return [max(1, $amount), true, $extra, $daysRemaining, $ends];
    }

    public function show(Request $r)
    {
        $tenantId = session('tenant_id');
        if (!$tenantId) { return redirect('/login'); }

        $devices = (int) $r->query('devices', 10);
        $period  = $r->query('period') === 'yearly' ? 'yearly' : 'monthly';

        // Never quote fewer devices than they already have enrolled -- paying
        // for 5 while running 12 would leave them immediately over their limit.
        $inUse = DB::table('device_credentials')
            ->where('tenant_id', $tenantId)->whereNull('revoked_at')->count();
        $devices = max($devices, $inUse, 1);

        $sub = DB::table('subscriptions')->where('tenant_id', $tenantId)->first();
        [$amount, $prorated, $extra, $daysRemaining, $periodEnd] = self::quoteFor($sub, $devices, $period);

        // Nothing to pay: a reduction, or no change at all. Do not present a
        // payment form for zero -- record the intent and send them back.
        if ($amount === 0) {
            if ($devices < (int) ($sub->asset_limit ?? 0)) {
                $this->scheduleReduction($tenantId, $sub, $devices);

                return redirect('/portal/billing')->with('billing_ok', __(
                    'Your plan will reduce to :n device(s) when your current period ends on :date. Nothing is charged now, and you keep your :current device(s) until then.',
                    [
                        'n' => $devices,
                        'date' => $periodEnd->toFormattedDateString(),
                        'current' => $sub->asset_limit,
                    ]
                ));
            }

            return redirect('/portal/billing');
        }

        return view('subscription-checkout.form', [
            'devices'       => $devices,
            'period'        => $period,
            'inUse'         => $inUse,
            'amountCents'   => $amount,
            'prorated'      => $prorated,
            'extraDevices'  => $extra,
            'daysRemaining' => $daysRemaining,
            'periodEnd'     => $periodEnd,
            'currentLimit'  => (int) ($sub->asset_limit ?? 0),
            'tenantEmail'   => session('auth_email') ?? '',
        ]);
    }

    public function charge(Request $r)
    {
        $tenantId = session('tenant_id');
        if (!$tenantId) { return redirect('/login'); }

        $validated = $r->validate([
            'devices' => ['required', 'integer', 'min:1', 'max:100000'],
            'period'  => ['required', 'in:monthly,yearly'],
            'method'  => ['required', 'in:card,mobile_money'],
            'email'   => ['required', 'email'],

            'card_number'  => ['required_if:method,card', 'nullable', 'string', 'regex:/^[0-9]{12,19}$/'],
            'expiry_month' => ['required_if:method,card', 'nullable', 'string', 'regex:/^[0-9]{1,2}$/'],
            'expiry_year'  => ['required_if:method,card', 'nullable', 'string', 'regex:/^[0-9]{2}$/'],
            'cvv'          => ['required_if:method,card', 'nullable', 'string', 'regex:/^[0-9]{3,4}$/'],

            'phone_number' => ['required_if:method,mobile_money', 'nullable', 'string', 'max:20'],
            'network'      => ['required_if:method,mobile_money', 'nullable', 'string', 'max:20'],
            // REAL BUG FOUND 2026-08-31: this validated size:2 and the form sent
            // 'CM'. Flutterwave wants the DIALLING code as a string -- their own
            // example uses "233" for Ghana -- and rejected CM with
            // 'must be a valid 3 digit country code'.
            'country_code' => ['required_if:method,mobile_money', 'nullable', 'string', 'regex:/^[0-9]{1,4}$/'],
        ]);

        $devices = (int) $validated['devices'];
        $period  = $validated['period'];

        // Re-quote server-side rather than trusting anything from the form.
        // The customer sees a prorated figure on the previous screen; charging
        // a different number would be indefensible.
        $sub = DB::table('subscriptions')->where('tenant_id', $tenantId)->first();
        [$amount, $prorated, $extra, $daysRemaining, $periodEnd] = self::quoteFor($sub, $devices, $period);

        // Never create a charge for nothing. A zero quote means a reduction or
        // no change, both of which are handled without payment.
        if ($amount === 0) {
            return redirect('/portal/checkout?devices=' . $devices . '&period=' . $period);
        }

        // Reference we generate and control, so the webhook can reconcile.
        $reference = 'secx-' . substr($tenantId, 0, 8) . '-' . substr((string) Str::uuid(), 0, 8);

        // The invoice and the pending payment both exist BEFORE any charge.
        $invoiceId = $this->createInvoice($tenantId, $devices, $period, $amount, $prorated, $extra, $daysRemaining, $periodEnd);

        DB::table('subscription_payments')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'invoice_id' => $invoiceId,
            'provider' => 'flutterwave',
            'provider_reference' => $reference,
            'amount_usd_cents' => $amount,
            'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Carried through the authorisation chain so finalize() knows what was
        // bought without re-deriving it from the request.
        session(['checkout_intent' => [
            'devices' => $devices, 'period' => $period,
            'reference' => $reference, 'invoiceId' => $invoiceId,
            // A prorated purchase must NOT move the period end -- they are
            // buying the remainder of a period they already paid for.
            'prorated' => $prorated,
            'periodEnd' => $periodEnd->toDateTimeString(),
        ]]);

        $flw = new FlutterwaveClient();

        try {
            $customer = $flw->createCustomer($validated['email']);

            if ($validated['method'] === 'card') {
                // Card fields exist ONLY in this local scope, passed directly
                // into AES-256-GCM encryption. Not stored, not logged.
                $pm = $flw->createCardPaymentMethod(
                    $validated['card_number'],
                    str_pad($validated['expiry_month'], 2, '0', STR_PAD_LEFT),
                    $validated['expiry_year'],
                    $validated['cvv']
                );
            } else {
                $pm = $flw->createMobileMoneyPaymentMethod(
                    $validated['country_code'],
                    $validated['phone_number'],
                    $validated['network']
                );
            }

            // SANDBOX TESTING ONLY. X-Scenario-Key forces a specific outcome --
            // 'auth_pin&issuer:approved' makes Flutterwave demand a PIN, which
            // is the only practical way to exercise the multi-step
            // authorisation chain deliberately rather than hoping to meet a
            // card that requires one.
            //
            // GUARDED TWICE: it is only read from the environment at all, and
            // it is refused unless the base URL is the sandbox. A scenario key
            // reaching production would be a header on a real customer's real
            // charge, and while Flutterwave's production API ignores it, that
            // is not a thing to rely on.
            $scenario = null;
            if (config('services.flutterwave.scenario_key')
                && str_contains((string) config('services.flutterwave.base_url'), 'sandbox')) {
                $scenario = config('services.flutterwave.scenario_key');
                Log::warning('Flutterwave scenario key in use -- sandbox only', ['scenario' => $scenario]);
            }

            // REAL GAP FOUND 2026-08-31: this pointed at show(), which is the
            // checkout FORM. A customer completing 3DS at their bank, or
            // approving a mobile money push, would have landed back on the
            // payment page with the charge SUCCEEDED at Flutterwave and nothing
            // applied here -- paid, and given nothing.
            //
            // The return route fetches the charge and finalises it instead.
            $chargeResult = $flw->createCharge(
                $pm['id'], $customer['id'], $amount, 'USD',
                $reference, route('subscription-checkout.return', ['reference' => $reference]),
                [], $scenario
            );
        } catch (\Throwable $e) {
            Log::error('Subscription checkout charge failed', ['reference' => $reference, 'error' => $e->getMessage()]);

            DB::table('subscription_payments')->where('provider_reference', $reference)
                ->update(['status' => 'failure', 'updated_at' => now()]);
            DB::table('invoices')->where('id', $invoiceId)
                ->update(['status' => 'void', 'voided_at' => now(), 'void_reason' => 'Charge could not be created', 'updated_at' => now()]);

            return back()->withErrors(__('Your payment could not be processed. Please check your details and try again.'));
        }

        return $this->handleChargeResult($tenantId, $reference, $chargeResult['data'] ?? []);
    }

    /**
     * The single decision point for a charge's CURRENT state. Called after
     * creating a charge, and again after every authorisation step. This is what
     * makes the chain generic -- see lesson 1 in the class comment.
     */
    private function handleChargeResult(string $tenantId, string $reference, array $charge)
    {
        $chargeId = $charge['id'] ?? null;
        $nextAction = $charge['next_action'] ?? null;
        $actionType = $nextAction['type'] ?? null;

        if ($actionType === 'redirect_url') {
            $url = $nextAction['redirect_url']['url'] ?? null;
            if ($url) { return redirect()->away($url); }
        }

        // REAL BUG FOUND 2026-08-31: this listed 'auth_pin' and 'auth_otp',
        // which are the SCENARIO names used to request a test flow -- not the
        // next_action TYPES Flutterwave returns. Their documented customer-input
        // actions are requires_pin, requires_otp and requires_additional_fields.
        // Every PIN-requiring charge therefore fell through to the unknown
        // branch and was abandoned.
        // payment_instruction is handled separately BELOW: it is mobile money
        // telling the customer to approve on their handset, and there is no
        // code for them to type. Sending it to the code form would ask for
        // something that does not exist.
        if ($actionType === 'payment_instruction' && $chargeId) {
            $note = $nextAction['payment_instruction']['note']
                ?? __('Please approve this payment on your phone.');

            return redirect()->route('subscription-checkout.return', ['reference' => $reference])
                ->with('billing_ok', $note);
        }

        if (in_array($actionType, [
                'requires_pin', 'requires_otp', 'requires_additional_fields',
                // Kept as aliases: cheaper than being wrong again if the API
                // ever returns the scenario-style names.
                'auth_pin', 'auth_otp',
            ], true) && $chargeId) {
            return redirect()->route('subscription-checkout.auth-step', [$chargeId, $actionType]);
        }

        // A next_action we have never seen. Alert rather than guess: guessing
        // is what misreported a PIN-then-OTP charge as failed once already.
        if ($actionType && $chargeId) {
            // Record the whole action, not just its type. An unknown action we
            // cannot inspect afterwards means reproducing it to diagnose it.
            \Illuminate\Support\Facades\Log::error('Unknown Flutterwave next_action', [
                'charge_id' => $chargeId, 'reference' => $reference,
                'next_action' => $nextAction,
            ]);

            DB::table('subscription_payments')->where('provider_reference', $reference)
                ->update(['raw_payload' => json_encode($charge), 'updated_at' => now()]);

            \App\Support\Health\OperatorAlert::send('Unknown Flutterwave next_action in subscription checkout', [[
                'level' => 'critical', 'area' => 'billing',
                'message' => "charge_id={$chargeId} reference={$reference} next_action.type={$actionType}",
            ]]);
            return redirect('/portal/billing')->withErrors(__('This payment needs a step we could not complete automatically. We have been notified and will contact you.'));
        }

        return $this->finalizeCharge($tenantId, $reference, $charge['status'] ?? 'unknown', $charge);
    }

    /**
     * Where the customer lands after being sent away to authorise.
     *
     * Reached after 3DS, after a mobile money push approval, or after any other
     * redirect flow. The charge may have succeeded, failed, or still be pending
     * while the provider settles -- so this ASKS Flutterwave rather than
     * assuming, and never trusts anything in the returned URL.
     *
     * The reference is ours and was created before the charge existed, so it
     * identifies the payment even if the customer returns on a different device
     * or after the session has gone.
     */
    public function returnFromRedirect(Request $r, string $reference)
    {
        $payment = DB::table('subscription_payments')
            ->where('provider_reference', $reference)->first();

        if (!$payment) {
            return redirect('/portal/billing')->withErrors(__('We could not find that payment.'));
        }

        // Already resolved -- a customer refreshing the return page must not
        // re-apply a plan or re-void an invoice.
        if ($payment->status !== 'pending') {
            return redirect('/portal/billing')->with(
                $payment->status === 'success' ? 'billing_ok' : 'error',
                $payment->status === 'success'
                    ? __('Payment received. Your plan is active.')
                    : __('That payment was not completed.')
            );
        }

        $chargeId = json_decode($payment->raw_payload ?? '{}', true)['id'] ?? $r->query('charge_id');

        if (!$chargeId) {
            // Nothing to ask about. Leave it pending rather than guessing: the
            // webhook or an operator can still resolve it.
            Log::warning('Redirect return without a charge id', ['reference' => $reference]);
            return redirect('/portal/billing')->with('billing_ok', __(
                'We are confirming your payment. This page will show your plan once it completes.'
            ));
        }

        try {
            $charge = (new FlutterwaveClient())->getCharge($chargeId);
            $charge = $charge['data'] ?? $charge;
        } catch (\Throwable $e) {
            Log::error('Could not fetch charge on return', ['reference' => $reference, 'error' => $e->getMessage()]);
            return redirect('/portal/billing')->with('billing_ok', __(
                'We are confirming your payment. This page will show your plan once it completes.'
            ));
        }

        // Still working. Do NOT mark it failed -- a mobile money push can take
        // a while, and telling a customer their payment failed while it is
        // still processing would be worse than saying nothing.
        $status = strtolower($charge['status'] ?? '');
        if (in_array($status, ['pending', 'processing', ''], true)) {
            return redirect('/portal/billing')->with('billing_ok', __(
                'We are confirming your payment. This page will show your plan once it completes.'
            ));
        }

        return $this->finalizeCharge($payment->tenant_id, $reference, $status, $charge);
    }

    public function showAuthStep(Request $r, string $chargeId, string $stepType)
    {
        return view('subscription-checkout.auth-step', compact('chargeId', 'stepType'));
    }

    public function submitAuthStep(Request $r, string $chargeId, string $stepType)
    {
        $tenantId = session('tenant_id');
        $intent = session('checkout_intent');
        if (!$tenantId || !$intent) { return redirect('/portal/billing'); }

        $validated = $r->validate(['value' => ['required', 'string', 'max:32']]);
        $flw = new FlutterwaveClient();

        try {
            // Keyed on Flutterwave's real action types. 'auth_pin' was the
            // SCENARIO name, not a type it ever returns -- so every PIN would
            // have been submitted to the OTP endpoint.
            $isPin = in_array($stepType, ['requires_pin', 'auth_pin'], true);

            $result = $isPin
                ? $flw->submitCardPin($chargeId, $validated['value'])
                : $flw->submitCardOtp($chargeId, $validated['value']);
        } catch (\Throwable $e) {
            Log::error('Subscription auth step failed', ['charge' => $chargeId, 'step' => $stepType, 'error' => $e->getMessage()]);
            return back()->withErrors(__('That code was not accepted. Please try again.'));
        }

        // May resolve, or hand back ANOTHER next_action. Loop, do not assume.
        return $this->handleChargeResult($tenantId, $intent['reference'], $result['data'] ?? []);
    }

    /**
     * No next_action left. Record the outcome and, on success, apply the plan.
     */
    private function finalizeCharge(string $tenantId, string $reference, string $status, array $charge)
    {
        $intent = session('checkout_intent');
        $succeeded = in_array(strtolower($status), ['succeeded', 'successful', 'success'], true);

        // A customer returning from their bank may have no session intent --
        // different device, or the session expired while they authorised.
        // Rebuild it from the invoice, which was written before the charge.
        if (!$intent) {
            $inv = DB::table('subscription_payments as p')
                ->join('invoices as i', 'i.id', '=', 'p.invoice_id')
                ->where('p.provider_reference', $reference)
                ->first(['i.id as invoice_id', 'i.line_items', 'i.period_end', 'i.kind']);

            if ($inv) {
                $line = json_decode($inv->line_items ?? '[]', true)[0] ?? [];
                $intent = [
                    'devices' => $line['new_total_devices'] ?? $line['quantity'] ?? null,
                    'period' => ($line['months_charged'] ?? 1) > 1 ? 'yearly' : 'monthly',
                    'reference' => $reference,
                    'invoiceId' => $inv->invoice_id,
                    'prorated' => (bool) ($line['prorated'] ?? false),
                    'periodEnd' => $inv->period_end,
                ];
            }
        }

        DB::table('subscription_payments')->where('provider_reference', $reference)->update([
            'status' => $succeeded ? 'success' : 'failure',
            'paid_at' => $succeeded ? now() : null,
            'raw_payload' => json_encode($charge),
            'updated_at' => now(),
        ]);

        if (!$succeeded) {
            if ($intent) {
                DB::table('invoices')->where('id', $intent['invoiceId'])->update([
                    'status' => 'void', 'voided_at' => now(),
                    'void_reason' => 'Payment failed', 'updated_at' => now(),
                ]);
            }
            return redirect('/portal/billing')->withErrors(__('Your payment was not completed. No charge has been made.'));
        }

        $this->applyPlan($tenantId, $intent);
        session()->forget('checkout_intent');

        return redirect('/portal/billing')->with('billing_ok',
            __('Payment received. Your plan is active.'));
    }

    /**
     * Delegates to the shared implementation.
     *
     * The webhook applies the SAME logic when a charge resolves without the
     * customer present, and two copies of "what does paying actually get you"
     * would drift -- the day they do is the day someone is charged for one
     * thing and given another.
     *
     * Driven by the INVOICE, which was written from a server-side quote before
     * the charge existed, so nothing the provider sends back decides what the
     * customer receives.
     */
    private function applyPlan(string $tenantId, ?array $intent): void
    {
        $reference = $intent['reference'] ?? null;
        if (!$reference) { return; }

        \App\Support\Billing\ApplyPaidSubscription::fromReference($reference);
    }

    private function createInvoice(
        string $tenantId, int $devices, string $period, int $amount,
        bool $prorated, int $extra, ?int $daysRemaining, $periodEnd
    ): string {
        $id = (string) Str::uuid();
        $number = (int) DB::table('invoices')->where('tenant_id', $tenantId)->max('number') + 1;

        // An invoice must show its own arithmetic. A prorated line that just
        // says "15 devices, $9.50" invites a support ticket; one that shows
        // 5 extra devices for 19 days does not.
        $line = $prorated
            ? [
                'description'    => $extra . ' additional device' . ($extra === 1 ? '' : 's')
                                    . ', ' . $daysRemaining . ' day' . ($daysRemaining === 1 ? '' : 's')
                                    . ' remaining in the current period',
                'quantity'       => $extra,
                'days_charged'   => $daysRemaining,
                'new_total_devices' => $devices,
                'prorated'       => true,
                'total_cents'    => $amount,
              ]
            : [
                'description'    => $devices . ' device' . ($devices === 1 ? '' : 's') . ', ' . $period,
                'quantity'       => $devices,
                'unit_cents'     => self::PER_DEVICE_MONTHLY_CENTS,
                'months_charged' => $period === 'yearly' ? self::YEARLY_MONTHS_CHARGED : 1,
                'prorated'       => false,
                'total_cents'    => $amount,
              ];

        DB::table('invoices')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'number' => $number,
            // 'capacity' is a distinct kind from 'subscription': an accountant
            // reading the history should see a mid-period top-up for what it is.
            'kind' => $prorated ? 'capacity' : 'subscription',
            'status' => 'issued',
            'amount_usd_cents' => $amount,
            'line_items' => json_encode([$line]),
            'period_start' => now()->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'issued_at' => now(),
            'due_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
