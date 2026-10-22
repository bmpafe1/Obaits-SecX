@if (\App\Support\Perm::can('manage_software'))
<div style="background:#fff;border-radius:12px;padding:1.5rem;margin-top:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
    <h3 style="margin-top:0;">{{ __('Compare with installed software') }}</h3>
    <p style="color:#5A6B82;font-size:.9rem;">{{ __('Checks what is actually installed on your devices against what you own and approve.') }}</p>
    <button wire:click="reconcile" style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;">{{ __('Run comparison') }}</button>

    @if ($reconciled)
        <div style="margin-top:1rem;">
            <h4 style="color:#c0392b;">{{ __('Possible license shortfalls') }} ({{ count($unlicensed) }})</h4>
            @forelse ($unlicensed as $u)
                <p>{{ $u['name'] }}: {{ __('installed on') }} {{ $u['installed'] }} {{ __('devices, but only') }} {{ $u['owned'] }} {{ __('licenses owned') }} ({{ $u['over'] }} {{ __('over') }})</p>
            @empty
                <p style="color:#1e824c;">{{ __('No license shortfalls found.') }}</p>
            @endforelse

            <h4 style="color:#b8860b;">{{ __('Unapproved / untracked software') }} ({{ count($unapproved) }})</h4>
            @forelse ($unapproved as $u)
                <p>{{ $u['name'] }} {{ $u['version'] ?? '' }} — {{ __('on') }} {{ $u['hosts'] }} {{ __('devices') }} {{ $u['note'] ?? '' }}</p>
            @empty
                <p style="color:#1e824c;">{{ __('Everything installed is in your catalog.') }}</p>
            @endforelse
        </div>
    @endif
</div>
@endif