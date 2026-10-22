<div style="max-width:1000px;margin:2rem auto;padding:0 1rem;">
    <a href="/operator/tenant/{{ $tenantId }}" style="color:#17A2B8;text-decoration:none;font-size:.9rem;">&larr; {{ __('Back to tenant') }}</a>

    <h1 style="color:#0B2545;">{{ __('Tenant data') }}: {{ $tenant->organization_name ?? '' }}</h1>

    @if (session('op_err'))
        <div style="background:#fdecea;border-left:4px solid #c0392b;padding:.7rem;border-radius:6px;margin:1rem 0;color:#c0392b;">{{ session('op_err') }}</div>
    @endif

    @if (!$unlocked)
        {{-- Reason gate — nothing loads until a reason is given and recorded. --}}
        <div style="background:#fff8e1;border-left:4px solid #b8860b;padding:1.5rem;border-radius:8px;margin-top:1rem;">
            <strong style="color:#0B2545;">{{ __('Accessing a tenant\'s data is recorded.') }}</strong>
            <p style="color:#5A6B82;font-size:.9rem;margin:.5rem 0;">
                {{ __('This is customer data. Please state why you need to view it. Your reason, identity, and the time are logged, and may be shown to the tenant.') }}
            </p>
            <textarea wire:model="reason" rows="2"
                placeholder="{{ __('e.g. Investigating support ticket #123 about a failed scan.') }}"
                style="width:100%;padding:.6rem;border:1px solid #ddd;border-radius:6px;margin:.5rem 0;"></textarea>
            <button wire:click="unlock"
                style="background:#0B2545;color:#fff;border:none;border-radius:8px;padding:.6rem 1.4rem;cursor:pointer;">
                {{ __('Access this tenant\'s data') }}
            </button>
        </div>
    @else
        <div style="background:#eef7f0;border-left:4px solid #1e824c;padding:.6rem 1rem;border-radius:6px;margin:1rem 0;font-size:.85rem;color:#1e824c;">
            {{ __('Access recorded. This is a read-only view.') }}
        </div>

        {{-- Findings --}}
        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin:1rem 0;">
            <h3 style="margin-top:0;">{{ __('Findings') }} ({{ count($data['findings']) }})</h3>
            @forelse ($data['findings'] as $f)
                <div style="border-top:1px solid #f2f2f2;padding:.4rem 0;font-size:.85rem;">
                    <span style="color:{{ $f->criticality >= 4 ? '#c0392b' : '#5A6B82' }};font-weight:600;">C{{ $f->criticality ?? '?' }}</span>
                    · {{ $f->name ?? $f->cve ?? __('finding') }}
                    @if ($f->host)<span style="color:#9aa;"> · {{ $f->host }}</span>@endif
                </div>
            @empty
                <p style="color:#9aa;font-size:.85rem;">{{ __('No findings.') }}</p>
            @endforelse
        </div>

        {{-- Documents --}}
        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin:1rem 0;">
            <h3 style="margin-top:0;">{{ __('Documents') }} ({{ count($data['documents']) }})</h3>
            @forelse ($data['documents'] as $d)
                <div style="border-top:1px solid #f2f2f2;padding:.4rem 0;font-size:.85rem;">
                    {{ $d->title }} <span style="color:#9aa;">· {{ $d->status }} (v{{ $d->version }})</span>
                </div>
            @empty
                <p style="color:#9aa;font-size:.85rem;">{{ __('No documents.') }}</p>
            @endforelse
        </div>

        {{-- Scans --}}
        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin:1rem 0;">
            <h3 style="margin-top:0;">{{ __('Scans') }} ({{ count($data['scans']) }})</h3>
            @forelse ($data['scans'] as $s)
                <div style="border-top:1px solid #f2f2f2;padding:.4rem 0;font-size:.85rem;">
                    {{ $s->target ?? $s->scan_type }} <span style="color:#9aa;">· {{ $s->created_at }}</span>
                </div>
            @empty
                <p style="color:#9aa;font-size:.85rem;">{{ __('No scans.') }}</p>
            @endforelse
        </div>
    @endif
</div>