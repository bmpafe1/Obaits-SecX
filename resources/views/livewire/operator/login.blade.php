<div style="max-width:400px;margin:5rem auto;padding:0 1rem;">
    <h1 style="color:#0B2545;text-align:center;">{{ __('Operator sign-in') }}</h1>
    <p style="color:#5A6B82;text-align:center;font-size:.9rem;">{{ __('Platform administration. Authorised operators only.') }}</p>

    <div style="background:#fff;border-radius:12px;padding:2rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-top:1.5rem;">
        @if ($error)
            <div style="background:#fdecea;border-left:4px solid #c0392b;padding:.7rem;border-radius:6px;margin-bottom:1rem;color:#c0392b;font-size:.9rem;">
                {{ $error }}
            </div>
        @endif

        <label style="display:block;margin-bottom:.8rem;">
            <span style="color:#5A6B82;font-size:.85rem;">{{ __('Email') }}</span>
            <input type="email" wire:model="email" wire:keydown.enter="submit"
                style="width:100%;padding:.6rem;border:1px solid #ddd;border-radius:6px;margin-top:.2rem;">
        </label>

        <label style="display:block;margin-bottom:1.2rem;">
            <span style="color:#5A6B82;font-size:.85rem;">{{ __('Password') }}</span>
            <input type="password" wire:model="password" wire:keydown.enter="submit"
                style="width:100%;padding:.6rem;border:1px solid #ddd;border-radius:6px;margin-top:.2rem;">
        </label>

        <button wire:click="submit" wire:loading.attr="disabled"
            style="width:100%;background:#0B2545;color:#fff;border:none;border-radius:8px;padding:.7rem;cursor:pointer;font-size:.95rem;">
            {{ __('Continue') }}
        </button>
    </div>
</div>