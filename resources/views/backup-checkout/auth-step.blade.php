<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Confirm payment') }} — Obaits</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family:system-ui,sans-serif;background:#f4f6f8;margin:0;padding:2rem;">
    <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;padding:2rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h1 style="color:#0B2545;font-size:1.4rem;margin-top:0;">{{ __('Confirm your payment') }}</h1>
        <p style="color:#5A6B82;">{{ __('Your bank requires one more step to confirm this payment.') }}</p>

        @if ($errors->any())
            <div style="background:#fdecea;color:#7a2019;padding:.8rem;border-radius:6px;margin-bottom:1rem;">
                {{ $errors->first() }}
            </div>
        @endif

        {{--
            Generic by design: the SAME template serves every known
            authorization step (PIN, OTP, and any future type added to
            BackupCheckoutController::KNOWN_STEP_LABELS). The label and
            input name are driven entirely by what the controller passes in
            — confirmed empirically 2026-07-25 that a real charge can chain
            through more than one of these steps, so this page may be shown
            more than once in a row for the same payment.
        --}}
        <form method="POST" action="{{ route('backup-checkout.submit-auth-step', [$chargeId, $stepType]) }}" autocomplete="off">
            @csrf
            <label style="display:block;margin-bottom:1.5rem;">
                <span style="display:block;font-size:.85rem;color:#5A6B82;margin-bottom:.3rem;">{{ $label }}</span>
                <input type="password" name="{{ $field }}" inputmode="numeric" required maxlength="8"
                    style="width:100%;padding:.6rem;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">
            </label>
            <button type="submit"
                style="width:100%;background:#0B2545;color:#fff;border:none;border-radius:8px;padding:.8rem;font-size:1rem;cursor:pointer;">
                {{ __('Confirm') }}
            </button>
        </form>
    </div>
</body>
</html>
