<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Upgrade backup storage') }} — Obaits</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family:system-ui,sans-serif;background:#f4f6f8;margin:0;padding:2rem;">
    <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;padding:2rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h1 style="color:#0B2545;font-size:1.4rem;margin-top:0;">{{ __('Upgrade backup storage') }}</h1>

        <div style="background:#f4f6f8;border-radius:8px;padding:1rem;margin-bottom:1.5rem;">
            <strong>{{ number_format($plan->gb_size / 1024, 0) }} TB</strong> — ${{ number_format($plan->price_usd_cents / 100, 2) }} / year
        </div>

        @if ($errors->any())
            <div style="background:#fdecea;color:#7a2019;padding:.8rem;border-radius:6px;margin-bottom:1rem;">
                {{ $errors->first() }}
            </div>
        @endif

        {{--
            Deliberately a PLAIN HTML form, not a Livewire component.
            Livewire serialises public component properties into the page's
            own network responses on every interaction — binding card fields
            via wire:model would put raw card data into that traffic even
            though it's never persisted. A plain POST keeps it to exactly
            one request, straight to the server, nowhere else.
        --}}
        <form method="POST" action="{{ route('backup-checkout.charge', $plan->id) }}" autocomplete="off">
            @csrf

            <label style="display:block;margin-bottom:1rem;">
                <span style="display:block;font-size:.85rem;color:#5A6B82;margin-bottom:.3rem;">{{ __('Card number') }}</span>
                <input type="text" name="card_number" inputmode="numeric" autocomplete="cc-number" required
                    maxlength="19" placeholder="4242 4242 4242 4242"
                    style="width:100%;padding:.6rem;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">
            </label>

            <div style="display:flex;gap:.8rem;margin-bottom:1rem;">
                <label style="flex:1;">
                    <span style="display:block;font-size:.85rem;color:#5A6B82;margin-bottom:.3rem;">{{ __('Expiry month') }}</span>
                    <input type="text" name="expiry_month" inputmode="numeric" autocomplete="cc-exp-month" required
                        maxlength="2" placeholder="MM"
                        style="width:100%;padding:.6rem;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">
                </label>
                <label style="flex:1;">
                    <span style="display:block;font-size:.85rem;color:#5A6B82;margin-bottom:.3rem;">{{ __('Expiry year') }}</span>
                    <input type="text" name="expiry_year" inputmode="numeric" autocomplete="cc-exp-year" required
                        maxlength="2" placeholder="YY"
                        style="width:100%;padding:.6rem;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">
                </label>
                <label style="flex:1;">
                    <span style="display:block;font-size:.85rem;color:#5A6B82;margin-bottom:.3rem;">CVV</span>
                    <input type="text" name="cvv" inputmode="numeric" autocomplete="cc-csc" required
                        maxlength="4" placeholder="123"
                        style="width:100%;padding:.6rem;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">
                </label>
            </div>

            <label style="display:block;margin-bottom:1.5rem;">
                <span style="display:block;font-size:.85rem;color:#5A6B82;margin-bottom:.3rem;">{{ __('Email for receipt') }}</span>
                <input type="email" name="email" required value="{{ $tenantEmail }}"
                    style="width:100%;padding:.6rem;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">
            </label>

            <button type="submit"
                style="width:100%;background:#0B2545;color:#fff;border:none;border-radius:8px;padding:.8rem;font-size:1rem;cursor:pointer;">
                {{ __('Pay') }} ${{ number_format($plan->price_usd_cents / 100, 2) }}
            </button>

            <p style="font-size:.75rem;color:#8a97a8;margin-top:1rem;text-align:center;">
                {{ __('Your card details are encrypted and sent directly to our payment processor. We do not store your card number.') }}
            </p>
        </form>
    </div>
</body>
</html>
