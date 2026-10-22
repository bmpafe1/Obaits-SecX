<div style="max-width:400px;margin:5rem auto;padding:0 1rem;">
    <h1 style="color:#0B2545;text-align:center;">{{ __('Two-factor authentication') }}</h1>

    <div style="background:#fff;border-radius:12px;padding:2rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-top:1.5rem;">
        @if ($error)
            <div style="background:#fdecea;border-left:4px solid #c0392b;padding:.7rem;border-radius:6px;margin-bottom:1rem;color:#c0392b;font-size:.9rem;">
                {{ $error }}
            </div>
        @endif

        @if ($enrolSecret)
            {{-- First-time enrolment --}}
            <p style="color:#5A6B82;font-size:.9rem;">{{ __('Scan this with your authenticator app (Google Authenticator, Authy, etc.), then enter the 6-digit code.') }}</p>
            <div style="text-align:center;margin:1rem 0;">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data={{ urlencode($enrolQr) }}" alt="QR code" style="border:1px solid #eee;border-radius:8px;">
            </div>
            <p style="color:#9aa;font-size:.75rem;text-align:center;word-break:break-all;">
                {{ __('Or enter this key manually:') }}<br><code>{{ $enrolSecret }}</code>
            </p>
        @else
            <p style="color:#5A6B82;font-size:.9rem;">{{ __('Enter the 6-digit code from your authenticator app.') }}</p>
        @endif

        <input type="text" wire:model="code" wire:keydown.enter="verify"
            inputmode="numeric" maxlength="6" placeholder="000000"
            style="width:100%;padding:.7rem;border:1px solid #ddd;border-radius:6px;text-align:center;font-size:1.3rem;letter-spacing:.4rem;margin:1rem 0;">

        <button wire:click="verify" wire:loading.attr="disabled"
            style="width:100%;background:#0B2545;color:#fff;border:none;border-radius:8px;padding:.7rem;cursor:pointer;font-size:.95rem;">
            {{ __('Verify') }}
        </button>
    </div>
</div>