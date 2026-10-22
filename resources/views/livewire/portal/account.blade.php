<div>
    <h1 style="color:#0B2545;">{{ __('Your account') }}</h1>
    @if (session('acct_ok'))
        <div style="background:#eef7f0;padding:.8rem;border-radius:6px;margin:1rem 0;">{{ session('acct_ok') }}</div>
    @endif

    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin:1rem 0;">
        <h3 style="margin-top:0;">{{ __('Language') }}</h3>
        <p style="color:#5A6B82;">{{ __('Choose the language for your portal.') }}</p>
        <div style="display:flex;gap:.6rem;">
            @foreach (['en'=>'English','fr'=>'Français','es'=>'Español','de'=>'Deutsch','nl'=>'Nederlands'] as $code => $name)
                <button wire:click="setLocale('{{ $code }}')"
                    style="padding:.5rem 1rem;border:1px solid {{ $locale===$code ? '#0B2545' : '#ddd' }};border-radius:6px;background:{{ $locale===$code ? '#0B2545' : '#fff' }};color:{{ $locale===$code ? '#fff' : '#333' }};cursor:pointer;">
                    {{ $name }}
                </button>
            @endforeach
        </div>
    </div>

    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Password') }}</h3>
        <p style="color:#5A6B82;">{{ __('Your password is managed by our secure sign-in system. Use the button below to change it.') }}</p>
        <a href="{{ rtrim(env('KEYCLOAK_BASE_URL'),'/') }}/realms/{{ env('KEYCLOAK_REALM') }}/account/#/security/signingin"
            target="_blank"
            style="display:inline-block;background:#0B2545;color:#fff;padding:.5rem 1.2rem;border-radius:8px;text-decoration:none;">
            {{ __('Change password') }}
        </a>
    </div>

    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-top:1.5rem;">
        <h3 style="margin-top:0;color:#0B2545;">{{ __('Licence') }}</h3>

        @php $lc = \Illuminate\Support\Facades\DB::table('license_cache')->where('tenant_id', session('tenant_id'))->first(); @endphp

        @if ($lc && $lc->expires_on)
            @php $exp = \Illuminate\Support\Carbon::parse($lc->expires_on); @endphp
            <p style="color:#5A6B82;">
                {{ __('Your licence is valid until') }}
                <strong style="color:{{ $exp->isPast() ? '#c0392b' : '#0B2545' }};">{{ $exp->toFormattedDateString() }}</strong>.
                @if ($lc->grace_ends_at)<span style="color:#b8860b;">{{ __('(In grace period — please renew.)') }}</span>@endif
            </p>
        @else
            <p style="color:#5A6B82;">{{ __('We do not have current licence details for your account.') }}</p>
        @endif

        <p style="color:#5A6B82;font-size:.9rem;">
            {{ __('Renewed your licence? Enter the key you were issued to activate it right away. (It will also activate automatically within the hour.)') }}
        </p>

        <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
            <input type="text" wire:model="licenceKey" placeholder="{{ __('Your licence key') }}"
                style="flex:1;min-width:240px;padding:.6rem;border:1px solid #ddd;border-radius:6px;font-family:monospace;">
            <button wire:click="activateLicence" wire:loading.attr="disabled"
                style="background:#0B2545;color:#fff;border:none;border-radius:8px;padding:.6rem 1.2rem;cursor:pointer;">
                {{ __('Verify & activate') }}
            </button>
        </div>
        <div wire:loading wire:target="activateLicence" style="color:#5A6B82;font-size:.85rem;margin-top:.4rem;">{{ __('Checking your key…') }}</div>

        @if ($licenceMsg)
            <div style="margin-top:.6rem;padding:.6rem;border-radius:6px;font-size:.9rem;background:{{ $licenceOk ? '#e6ffe6' : '#fdecea' }};color:{{ $licenceOk ? '#1e824c' : '#c0392b' }};">
                {{ $licenceMsg }}
            </div>
        @endif
    </div>

    {{-- ============ AI ASSISTANT ============ --}}
    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-top:1.5rem;">
        <h3 style="margin-top:0;color:#0B2545;">{{ __('AI assistant') }}</h3>
        <p style="color:#5A6B82;font-size:.9rem;margin-top:0;">
            {{ __('Brian and the compliance document drafting are powered by an AI service. You can use ours, or connect your own account.') }}
        </p>

        @if ($aiNotice)
            <div style="background:{{ $aiNoticeOk ? '#eef7f0' : '#fdecea' }};border-left:4px solid {{ $aiNoticeOk ? '#1e824c' : '#c0392b' }};color:{{ $aiNoticeOk ? '#0B2545' : '#c0392b' }};padding:.7rem;border-radius:6px;margin:.8rem 0;font-size:.9rem;">
                {{ $aiNotice }}
            </div>
        @endif

        <label style="display:block;margin:.8rem 0 .2rem;color:#0B2545;font-size:.88rem;">{{ __('Which AI service') }}</label>
        <select wire:model.live="aiProvider" style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:6px;">
            <option value="platform">{{ __('Use the platform’s AI (included)') }}</option>
            <option value="claude">{{ __('My own Claude account (Anthropic)') }}</option>
            <option value="deepseek">{{ __('My own DeepSeek account') }}</option>
        </select>

        @if ($aiProvider === 'platform')
            <div style="color:#5A6B82;font-size:.85rem;margin-top:.6rem;">
                {{ __('We cover the cost, within reasonable use. Your content is processed by the provider we have chosen.') }}
            </div>
        @else
            {{-- Say plainly what changes: both who pays, and who sees the content. --}}
            <div style="background:#f8f9fb;border-left:3px solid #17A2B8;padding:.8rem;border-radius:6px;margin:.8rem 0;font-size:.85rem;color:#5A6B82;">
                {{ __('With your own account, your compliance content and security data are sent to the provider you have chosen, on your account, and you pay them directly for what you use. Nothing is sent to our AI provider.') }}
            </div>

            <label style="display:block;margin:.6rem 0 .2rem;color:#0B2545;font-size:.88rem;">{{ __('API key') }}</label>
            <input type="password" wire:model="aiKey"
                placeholder="{{ ($aiSettings && $aiSettings->api_key) ? __('leave blank to keep the saved key') : ($aiProvider === 'claude' ? 'sk-ant-…' : 'sk-…') }}"
                style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:6px;font-family:monospace;">

            <label style="display:block;margin:.6rem 0 .2rem;color:#0B2545;font-size:.88rem;">{{ __('Model (optional)') }}</label>
            <input type="text" wire:model="aiModel"
                placeholder="{{ $aiProvider === 'claude' ? 'claude-sonnet-5' : 'deepseek-chat' }}"
                style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:6px;font-family:monospace;font-size:.88rem;">

            <div style="color:#9aa;font-size:.8rem;margin-top:.4rem;">
                {{ __('Your key is stored encrypted and is never shown again once saved. We never include it in logs or reports.') }}
            </div>
        @endif

        <div style="margin-top:1rem;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
            <button wire:click="saveAiSettings"
                style="background:#0B2545;color:#fff;border:none;border-radius:8px;padding:.6rem 1.2rem;cursor:pointer;">
                {{ __('Save') }}
            </button>

            @if ($aiSettings && $aiSettings->provider !== 'platform' && $aiSettings->api_key)
                <button wire:click="testAiSettings" wire:loading.attr="disabled"
                    style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.6rem 1.2rem;cursor:pointer;">
                    {{ __('Test') }}
                </button>
                <span wire:loading wire:target="testAiSettings" style="color:#5A6B82;font-size:.85rem;">{{ __('asking…') }}</span>
            @endif
        </div>

        {{-- A saved key proves nothing. Show whether it has actually worked, and
             when — otherwise an expired key fails silently at the moment someone
             is trying to generate a document. --}}
        @if ($aiSettings && $aiSettings->provider !== 'platform')
            <div style="margin-top:.8rem;font-size:.85rem;">
                @if ($aiSettings->last_verify_result === 'ok')
                    <span style="color:#1e824c;">✓ {{ __('Tested and working') }}</span>
                    <span style="color:#9aa;">— {{ $aiSettings->last_verified_at }}</span>
                @elseif ($aiSettings->last_verify_result === 'failed')
                    <span style="color:#c0392b;font-weight:600;">✗ {{ __('The last test failed') }}</span>
                    <span style="color:#c0392b;">{{ __('AI features will not work until this is fixed.') }}</span>
                @else
                    <span style="color:#b8860b;">{{ __('Not tested yet — press Test to confirm it works.') }}</span>
                @endif
            </div>
        @endif
    </div>
</div>