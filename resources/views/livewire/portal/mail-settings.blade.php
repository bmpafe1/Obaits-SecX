<div style="max-width:720px;">
    <h1 style="font-size:1.6rem;color:#1F3864;margin-bottom:.3rem;">{{ __('Email delivery') }}</h1>
    <p style="color:#5A6B82;margin-bottom:1.4rem;line-height:1.6;">
        {{ __('By default we send your alerts and reports from Obaits. You can send them from your own mail server instead, so they arrive from your domain.') }}
    </p>

    {{-- What this does and does not cover. Being explicit avoids the
         reasonable assumption that it covers everything. --}}
    <div style="background:#E8EDF8;border:1px solid #CBD8EC;border-radius:10px;padding:1rem 1.2rem;margin-bottom:1.4rem;">
        <p style="color:#1F3864;font-size:.9rem;margin:0 0 .4rem;font-weight:600;">{{ __('What your server will send') }}</p>
        <p style="color:#3d4d61;font-size:.88rem;margin:0;line-height:1.6;">
            {{ __('Security alerts, scan reports, notifications and team invitations — mail about your estate, to your people.') }}
        </p>
        <p style="color:#5A6B82;font-size:.85rem;margin:.6rem 0 0;line-height:1.6;">
            {{ __('Sign-up verification, billing and marketplace introductions continue to come from Obaits. Those are from us, not from you.') }}
        </p>
    </div>

    @if ($settings && $settings->enabled && $settings->failure_count > 0)
        <div style="background:#FFF6DC;border:1px solid #EBDCAE;border-radius:10px;padding:1rem 1.2rem;margin-bottom:1.4rem;">
            <strong style="color:#7A5C00;">{{ __('Your mail server is having trouble') }}</strong>
            <p style="color:#7A5C00;font-size:.9rem;margin:.35rem 0 0;line-height:1.55;">
                {{ __(':n recent failure(s). Messages are still being delivered, but from Obaits rather than your domain.', ['n' => $settings->failure_count]) }}
                @if ($settings->last_failure_error) <br><span style="font-size:.85rem;">{{ $settings->last_failure_error }}</span> @endif
            </p>
        </div>
    @endif

    @if ($testResult)
        <div style="background:{{ $testOk ? '#E3F3EC' : '#F7E4E4' }};border:1px solid {{ $testOk ? '#C4DECD' : '#E6C9C9' }};color:{{ $testOk ? '#0B6E4F' : '#8B2F2F' }};border-radius:10px;padding:.9rem 1.2rem;margin-bottom:1.4rem;font-size:.92rem;">
            {{ $testResult }}
        </div>
    @endif

    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.08);">
        <div style="display:flex;gap:.8rem;flex-wrap:wrap;">
            <div style="flex:2;min-width:240px;">
                <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('SMTP host') }}</label>
                <input wire:model="host" placeholder="mail.example.com" style="width:100%;padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">
                @error('host') <small style="color:#c0392b;">{{ $message }}</small> @enderror
            </div>
            <div style="flex:1;min-width:100px;">
                <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Port') }}</label>
                <input wire:model="port" inputmode="numeric" style="width:100%;padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">
                @error('port') <small style="color:#c0392b;">{{ $message }}</small> @enderror
            </div>
            <div style="flex:1;min-width:120px;">
                <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Encryption') }}</label>
                <select wire:model="encryption" style="width:100%;padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">
                    <option value="tls">STARTTLS</option>
                    <option value="ssl">SSL</option>
                    <option value="none">{{ __('None') }}</option>
                </select>
            </div>
        </div>

        <div style="display:flex;gap:.8rem;flex-wrap:wrap;margin-top:.8rem;">
            <div style="flex:1;min-width:220px;">
                <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Username') }}</label>
                <input wire:model="username" autocomplete="off" style="width:100%;padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">
            </div>
            <div style="flex:1;min-width:220px;">
                <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Password') }}</label>
                <input wire:model="password" type="password" autocomplete="new-password"
                       placeholder="{{ $hasStoredPassword ? __('Stored — leave blank to keep') : '' }}"
                       style="width:100%;padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">
                @error('password') <small style="color:#c0392b;">{{ $message }}</small> @enderror
            </div>
        </div>

        <div style="display:flex;gap:.8rem;flex-wrap:wrap;margin-top:.8rem;">
            <div style="flex:1;min-width:220px;">
                <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Send from address') }}</label>
                <input wire:model="from_address" placeholder="security@example.com" style="width:100%;padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">
                @error('from_address') <small style="color:#c0392b;">{{ $message }}</small> @enderror
            </div>
            <div style="flex:1;min-width:220px;">
                <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Send from name') }}</label>
                <input wire:model="from_name" placeholder="{{ __('Your organisation') }}" style="width:100%;padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">
            </div>
        </div>

        <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:1.3rem;align-items:center;">
            <button wire:click="save(true)" style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.6rem 1.3rem;cursor:pointer;">
                {{ __('Save and send a test') }}
            </button>
            <button wire:click="save(false)" style="background:#fff;color:#5A6B82;border:1px solid #dde3ea;border-radius:8px;padding:.6rem 1.3rem;cursor:pointer;">
                {{ __('Save only') }}
            </button>

            @if ($settings)
                <label style="display:flex;align-items:center;gap:.5rem;margin-left:auto;color:#3d4d61;font-size:.92rem;cursor:pointer;">
                    <input type="checkbox" wire:click="toggleEnabled" @checked($enabled)>
                    {{ __('Use my server') }}
                </label>
            @endif
        </div>

        @if ($settings && $settings->last_tested_at)
            <p style="color:#8fa3bf;font-size:.82rem;margin:.9rem 0 0;">
                {{ __('Last tested :when — :result', [
                    'when' => \Carbon\Carbon::parse($settings->last_tested_at)->diffForHumans(),
                    'result' => $settings->last_test_ok ? __('succeeded') : __('failed'),
                ]) }}
            </p>
        @endif

        <p style="color:#8fa3bf;font-size:.82rem;margin:.6rem 0 0;line-height:1.5;">
            {{ __('A successful test is required before you can enable this. If your server later stops working, we keep delivering your mail from Obaits and tell you.') }}
        </p>
    </div>
</div>
