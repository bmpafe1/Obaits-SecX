{{-- Subscription checkout. Deliberately NOT Livewire, matching
     BackupCheckoutController: card fields must never round-trip through a
     websocket, and a payment form should be a plain POST. --}}
<x-layouts.app>
<div style="max-width:640px;margin:0 auto;padding:2.5rem 1.5rem;font-family:system-ui,sans-serif;">
    <a href="/portal/billing" style="color:#17A2B8;text-decoration:none;font-size:.9rem;">&larr; {{ __('Back to Billing') }}</a>
    <h1 style="font-size:1.6rem;color:#1F3864;margin:.8rem 0 .3rem;">{{ __('Confirm and pay') }}</h1>
    <p style="color:#5A6B82;margin-bottom:1.5rem;">{{ __('One payment for the period. Nothing is stored or charged automatically afterwards.') }}</p>

    @if ($errors->any())
        <div style="background:#F7E4E4;border:1px solid #E6C9C9;color:#8B2F2F;padding:.9rem 1.1rem;border-radius:8px;margin-bottom:1.2rem;">
            @foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('subscription-checkout.charge') }}" autocomplete="on">
        @csrf
        <input type="hidden" name="period" value="{{ $period }}">

        <div style="background:#fff;border:1px solid #dde3ea;border-radius:10px;padding:1.2rem;margin-bottom:1.2rem;">
            <label style="display:block;color:#5A6B82;font-size:.88rem;margin-bottom:.3rem;">{{ __('Devices') }}</label>
            {{-- REAL BUG FOUND 2026-08-30: this was a free-text number input,
                 so typing a different figure changed the device count WITHOUT
                 re-quoting. The panel and total stayed frozen at whatever the
                 page had loaded with, and a customer could agree to one price
                 and be charged another. The charge itself was correct, since
                 charge() re-quotes server-side -- but agreeing to $12.74 and
                 being billed $18 is indefensible in either direction.

                 Changing the number now reloads the page with a fresh quote. --}}
            <input type="number" name="devices" min="{{ max(1, $inUse) }}" value="{{ $devices }}" required
                   id="devices"
                   style="width:100%;padding:.6rem;border:1px solid #dde3ea;border-radius:6px;font-size:1.1rem;">
            <p id="requote" style="display:none;color:#7A5C00;background:#FFF6DC;padding:.5rem .7rem;border-radius:6px;font-size:.85rem;margin:.5rem 0 0;">
                {{ __('Updating your price...') }}
            </p>
            @if ($inUse > 0)
                <p style="color:#8fa3bf;font-size:.82rem;margin:.5rem 0 0;">
                    {{ __('You currently have :n device(s) enrolled, so you cannot buy fewer than that.', ['n' => $inUse]) }}
                </p>
            @endif

            <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid #eef2f6;">
                @if ($prorated)
                    {{-- Show the arithmetic. An unexplained prorated figure is
                         a support ticket waiting to happen. --}}
                    <div style="background:#E8EDF8;border-radius:8px;padding:.8rem 1rem;margin-bottom:.9rem;">
                        <p style="color:#1F3864;font-size:.88rem;margin:0 0 .3rem;font-weight:600;">
                            {{ __('You are adding :n device(s) to your current period', ['n' => $extraDevices]) }}
                        </p>
                        <p style="color:#5A6B82;font-size:.85rem;margin:0;line-height:1.55;">
                            {{ __('You have already paid for your existing :current device(s) until :date, so you are only charged for the :n additional device(s) for the :days day(s) remaining. Your renewal date does not change.', [
                                'current' => $currentLimit,
                                'date' => $periodEnd->toFormattedDateString(),
                                'n' => $extraDevices,
                                'days' => $daysRemaining,
                            ]) }}
                        </p>
                    </div>
                @endif
                <div style="display:flex;justify-content:space-between;align-items:baseline;">
                    <span style="color:#5A6B82;">
                        {{ $prorated
                            ? __('Total to pay now')
                            : ($period === 'yearly' ? __('Total for one year') : __('Total for one month')) }}
                    </span>
                    <strong style="font-size:1.5rem;color:#1F3864;">${{ number_format($amountCents / 100, 2) }}</strong>
                </div>
            </div>
        </div>

        <div style="background:#fff;border:1px solid #dde3ea;border-radius:10px;padding:1.2rem;margin-bottom:1.2rem;">
            <label style="display:block;color:#5A6B82;font-size:.88rem;margin-bottom:.3rem;">{{ __('Email for the receipt') }}</label>
            <input type="email" name="email" value="{{ $tenantEmail }}" required
                   style="width:100%;padding:.6rem;border:1px solid #dde3ea;border-radius:6px;">

            {{-- MOBILE MONEY IS HIDDEN, NOT REMOVED. Flutterwave rejects a USD
                 mobile money charge in Cameroon with CURRENCY_NOT_SUPPORTED --
                 it settles in XAF, and this platform prices in USD. That is a
                 pricing decision, not a bug, and until it is taken, offering a
                 payment method that always errors would be worse than not
                 offering it.

                 The controller still handles mobile_money fully: restoring it
                 is uncommenting this block once the currency question is
                 settled. --}}
            <input type="hidden" name="method" value="card">
            <p style="color:#1F3864;font-weight:600;font-size:.9rem;margin:1.2rem 0 .5rem;">{{ __('Card details') }}</p>

            <div id="card">
                <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Card number') }}</label>
                <input name="card_number" inputmode="numeric" autocomplete="cc-number" placeholder="4242424242424242"
                       style="width:100%;padding:.6rem;border:1px solid #dde3ea;border-radius:6px;margin-bottom:.7rem;">
                <div style="display:flex;gap:.7rem;">
                    <div style="flex:1"><label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Month') }}</label>
                        <input name="expiry_month" inputmode="numeric" placeholder="09" style="width:100%;padding:.6rem;border:1px solid #dde3ea;border-radius:6px;"></div>
                    <div style="flex:1"><label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Year') }}</label>
                        <input name="expiry_year" inputmode="numeric" placeholder="28" style="width:100%;padding:.6rem;border:1px solid #dde3ea;border-radius:6px;"></div>
                    <div style="flex:1"><label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('CVV') }}</label>
                        <input name="cvv" inputmode="numeric" autocomplete="cc-csc" style="width:100%;padding:.6rem;border:1px solid #dde3ea;border-radius:6px;"></div>
                </div>
            </div>


        </div>

        <button type="submit" style="width:100%;background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.85rem;font-size:1rem;cursor:pointer;">
            {{ __('Pay $:amount', ['amount' => number_format($amountCents / 100, 2)]) }}
        </button>
        <p style="color:#8fa3bf;font-size:.8rem;margin-top:.9rem;line-height:1.5;text-align:center;">
            {{ __('Your card details are encrypted and sent directly to our payment provider. They are never stored by Obaits.') }}
        </p>
    </form>
</div>

<script>
(function () {
    // Re-quote on the server whenever the device count changes. The price a
    // customer sees must always be the price they are charged.
    var input = document.getElementById('devices');
    var notice = document.getElementById('requote');
    var period = @json($period);
    var current = @json($devices);
    var timer = null;

    input.addEventListener('input', function () {
        clearTimeout(timer);
        var n = parseInt(input.value, 10);
        if (isNaN(n) || n < 1 || n === current) { notice.style.display = 'none'; return; }

        notice.style.display = 'block';
        timer = setTimeout(function () {
            window.location = '/portal/checkout?devices=' + n + '&period=' + period;
        }, 700);
    });
})();
</script>
</x-layouts.app>
