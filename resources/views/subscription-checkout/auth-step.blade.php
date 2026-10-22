{{-- One step in the authorisation chain. There may be more than one: a PIN can
     be followed by an OTP. This view is generic and the controller decides
     what comes next. --}}
<x-layouts.app>
<div style="max-width:420px;margin:0 auto;padding:3rem 1.5rem;font-family:system-ui,sans-serif;">
    <h1 style="font-size:1.4rem;color:#1F3864;margin-bottom:.4rem;">
        {{ in_array($stepType, ['requires_pin','auth_pin']) ? __('Enter your card PIN') : __('Enter the code you were sent') }}
    </h1>
    <p style="color:#5A6B82;margin-bottom:1.4rem;font-size:.92rem;">
        {{ in_array($stepType, ['requires_pin','auth_pin'])
            ? __('Your bank requires your PIN to authorise this payment.')
            : __('Your bank has sent you a one-time code to authorise this payment.') }}
    </p>

    @if ($errors->any())
        <div style="background:#F7E4E4;border:1px solid #E6C9C9;color:#8B2F2F;padding:.8rem 1rem;border-radius:8px;margin-bottom:1rem;">
            @foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('subscription-checkout.submit-auth-step', [$chargeId, $stepType]) }}">
        @csrf
        <input name="value" inputmode="numeric" autocomplete="one-time-code" autofocus required
               style="width:100%;padding:.8rem;border:1px solid #dde3ea;border-radius:8px;font-size:1.3rem;text-align:center;letter-spacing:.3em;">
        <button type="submit" style="width:100%;background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.8rem;font-size:1rem;cursor:pointer;margin-top:1rem;">
            {{ __('Continue') }}
        </button>
    </form>
</div>
</x-layouts.app>
