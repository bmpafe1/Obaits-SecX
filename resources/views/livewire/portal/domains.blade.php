<div>
    <h1 style="color:#1F3864;">{{ __('Your domains') }}</h1>
    <p style="color:#555;">{{ __('Add the domains you want us to protect and scan. You only need to verify each one once.') }}</p>
 
    <div style="display:flex; gap:.5rem; margin:1rem 0;">
        <input wire:model="newDomain" placeholder="www.yourcompany.com" style="flex:1; padding:.6rem;">
        @if (\App\Support\Perm::can('manage_domains'))
        <button wire:click="addDomain" style="background:#1F3864; color:#fff; padding:.6rem 1.2rem; border:none; border-radius:8px;">{{ __('Add domain') }}</button>
        @endif
    </div>
    @error('newDomain') <small style="color:#c0392b;">{{ $message }}</small> @enderror
 
    @foreach ($domains as $d)
        <div style="border:1px solid #ddd; border-radius:10px; padding:1rem; margin:1rem 0; background:#fff;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <strong>{{ $d->domain }}</strong>
                @if ($d->verified)
                    <span style="color:#1e824c;">✓ {{ __('Verified') }}</span>
                @else
                    <span style="color:#c0392b;">{{ __('Not verified') }}</span>
                @endif
            </div>
 
            @unless ($d->verified)
                <div style="margin-top:1rem; background:#f7f9fc; padding:1rem; border-radius:8px; font-size:.9rem;">
                    <p>{{ __('To prove this domain is yours, add this record where you manage your domain (your domain registrar or hosting provider):') }}</p>
                    <div style="font-family:monospace; background:#fff; padding:.6rem; border-radius:6px; margin:.5rem 0;">
                        <div><strong>{{ __('Type') }}:</strong> TXT</div>
                        <div><strong>{{ __('Name/Host') }}:</strong> _obaits-challenge</div>
                        <div style="word-break:break-all;"><strong>{{ __('Value') }}:</strong> {{ $d->verification_token }}</div>
                    </div>
                    <p style="color:#888;">{{ __('Not sure how? Search your provider name plus "add TXT record", or ask us for help. DNS changes can take a few minutes to appear.') }}</p>
                    @if (\App\Support\Perm::can('manage_domains'))
                    <button wire:click="verify('{{ $d->id }}')" style="background:#1F3864; color:#fff; padding:.5rem 1rem; border:none; border-radius:8px; margin-top:.5rem;">{{ __("I've added the record — check now") }}</button>
                    @endif
                    @error('verify_' . $d->id) <div><small style="color:#c0392b;">{{ $message }}</small></div> @enderror
                </div>
            @endunless
        </div>
    @endforeach
</div>
