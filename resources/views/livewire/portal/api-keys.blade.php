<div>
    <h1 style="color:#0B2545;">{{ __('API Keys') }}</h1>
    <p style="color:#5A6B82;">{{ __('Create and manage keys for programmatic access to your tenant\'s data.') }}</p>

    @if ($generatedKey)
        <div style="background:#fff3cd;border:1px solid #f0d78c;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;">
            <h3 style="margin-top:0;color:#0B2545;">{{ __('Your new API key: :name', ['name' => $generatedKeyName]) }}</h3>
            <p style="color:#8a6d1f;font-weight:600;">{{ __('Copy this key now. For your security, it will not be shown again.') }}</p>
            <code id="new-api-key" style="display:block;background:#0B2545;color:#fff;padding:.8rem;border-radius:6px;word-break:break-all;font-size:.9rem;">{{ $generatedKey }}</code>
            <div style="margin-top:.8rem;display:flex;gap:.6rem;">
                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('new-api-key').textContent.trim())" style="background:#17A2B8;color:#fff;border:none;border-radius:6px;padding:.5rem 1.2rem;font-size:.85rem;">
                    {{ __('Copy') }}
                </button>
                <button wire:click="dismissGeneratedKey" style="background:#fff;color:#0B2545;border:1px solid #dfe3ea;border-radius:6px;padding:.5rem 1.2rem;font-size:.85rem;">
                    {{ __('Done') }}
                </button>
            </div>
        </div>
    @endif

    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Create a new key') }}</h3>
        <form wire:submit="createKey" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;">
            <input wire:model="newKeyName" placeholder="{{ __('e.g. Zapier integration') }}" style="flex:1;min-width:220px;padding:.5rem;border:1px solid #dfe3ea;border-radius:6px;">
            <button type="submit" style="background:#0B2545;color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;">{{ __('Create key') }}</button>
        </form>
        @error('newKeyName') <small style="color:#c0392b;">{{ $message }}</small> @enderror
    </div>

    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Your keys') }}</h3>
        @if ($keys->isEmpty())
            <p style="color:#5A6B82;">{{ __('No API keys yet.') }}</p>
        @else
            @foreach ($keys as $key)
                <div style="border-top:1px solid #f2f2f2;padding:1rem 0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
                    <div>
                        <strong style="color:#0B2545;">{{ $key->name }}</strong>
                        <span style="margin-left:.5rem;background:{{ $key->revoked_at ? '#fdecea' : '#eef7f0' }};color:{{ $key->revoked_at ? '#c0392b' : '#1e824c' }};border-radius:4px;padding:.1rem .5rem;font-size:.78rem;">
                            {{ $key->revoked_at ? __('Revoked') : __('Active') }}
                        </span>
                        <div style="color:#8a97a8;font-size:.82rem;margin-top:.3rem;">
                            <code>{{ $key->key_prefix }}…</code>
                            &middot; {{ __('Created') }} {{ \Illuminate\Support\Carbon::parse($key->created_at)->diffForHumans() }}
                            &middot; {{ __('Last used') }}: {{ $key->last_used_at ? \Illuminate\Support\Carbon::parse($key->last_used_at)->diffForHumans() : __('Never') }}
                        </div>
                    </div>
                    @if (!$key->revoked_at)
                        <button
                            wire:click="revokeKey('{{ $key->id }}')"
                            wire:confirm="{{ __('Revoke this API key? Anything using it will stop working immediately.') }}"
                            style="background:#fff;color:#c0392b;border:1px solid #f5c6cb;border-radius:6px;padding:.4rem 1rem;font-size:.85rem;">
                            {{ __('Revoke') }}
                        </button>
                    @endif
                </div>
            @endforeach
        @endif
    </div>
</div>
