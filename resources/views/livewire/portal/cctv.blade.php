<div>
    <h1 style="color:#0B2545;">{{ __('CCTV Monitoring') }}</h1>
    <p style="color:#5A6B82;">
        {{ __('Monitor your organization\'s camera feeds.') }}
        @if ($instance && $instance->status === 'running')
            {{-- REAL FEATURE 2026-08-21, requested directly: a real,
                 unique link (auto-generated per tenant at CCTV
                 provisioning time -- see $instance->subdomain) for
                 opening the full, wider view in a new tab. Since
                 lightNVR's own session cookie is scoped to this same
                 real subdomain regardless of whether it was first
                 reached via the embedded iframe or a direct new-tab
                 visit, a tenant who has already used the embedded view
                 opens straight into it, already logged in. --}}
            &mdash;
            <a href="https://{{ $instance->subdomain }}" target="_blank" style="color:#17A2B8;">
                {{ __('Open wider view') }} &rarr;
            </a>
        @endif
    </p>

    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        @if (!$instance)
            <p style="color:#5A6B82;">{{ __('You do not have a CCTV monitoring instance set up yet.') }}</p>
            <button wire:click="setUp" wire:loading.attr="disabled" style="background:#0B2545;color:#fff;border:none;border-radius:8px;padding:.6rem 1.4rem;">
                <span wire:loading.remove wire:target="setUp">{{ __('Set up CCTV Monitoring') }}</span>
                <span wire:loading wire:target="setUp">{{ __('Setting up... this can take a minute.') }}</span>
            </button>
        @elseif ($instance->status === 'running')
            {{-- REAL FEATURE 2026-08-21, requested directly: embed the
                 real, live instance directly in the portal rather than
                 redirect out to a new tab. Platform-admin-only nav
                 items (Settings, Users, System, admin, Logout) are
                 hidden by a real script injected at the nginx layer
                 (see ProvisionCctv's own nginx template) -- this
                 iframe itself has no special logic, the hiding happens
                 entirely server-side, before the page ever reaches the
                 browser. --}}
            <iframe src="https://{{ $instance->subdomain }}" style="width:100%;height:75vh;border:none;border-radius:8px;" title="{{ __('CCTV Monitoring') }}"></iframe>
        @elseif ($instance->status === 'failed')
            <p style="color:#c0392b;">{{ __('Setup failed. Please contact support.') }}</p>
        @else
            <p style="color:#5A6B82;">{{ __('Your CCTV monitoring instance is being set up...') }}</p>
        @endif
    </div>
</div>
