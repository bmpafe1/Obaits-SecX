<div style="max-width:1100px;margin:2rem auto;padding:0 1rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <h1 style="color:#0B2545;margin:0;">{{ __('Tenants') }}</h1>
        <div>
            <a href="/operator/analytics" style="color:#17A2B8;text-decoration:none;font-size:.9rem;margin-right:1rem;">{{ __('Analytics') }}</a>
            <a href="/operator/marketplace/requests" style="color:#17A2B8;text-decoration:none;font-size:.9rem;margin-right:1rem;">{{ __('Referrals') }}</a>
            <a href="/operator/marketplace/providers" style="color:#17A2B8;text-decoration:none;font-size:.9rem;margin-right:1rem;">{{ __('Marketplace') }}</a>
            <a href="/operator/trainings" style="color:#17A2B8;text-decoration:none;font-size:.9rem;margin-right:1rem;">{{ __('Trainings') }}</a>
            <a href="/operator/api-keys" style="color:#17A2B8;text-decoration:none;font-size:.9rem;margin-right:1rem;">{{ __('API Keys') }}</a>
            <a href="/operator/audit" style="color:#17A2B8;text-decoration:none;font-size:.9rem;margin-right:1rem;">{{ __('Audit log') }}</a>
            <a href="/operator/logout" style="color:#5A6B82;text-decoration:none;font-size:.9rem;">{{ __('Sign out') }}</a>
        </div>
    </div>

    @if (session('op_ok'))
        <div style="background:#e6ffe6;padding:.8rem;border-radius:6px;margin:1rem 0;">{{ session('op_ok') }}</div>
    @endif

    <input type="text" wire:model.live.debounce.300ms="search"
        placeholder="{{ __('Search by name or domain...') }}"
        style="width:100%;padding:.6rem;border:1px solid #ddd;border-radius:6px;margin:1rem 0;">

    <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
            <thead>
                <tr style="text-align:left;color:#5A6B82;background:#f8f9fb;">
                    <th style="padding:.7rem;">{{ __('Organisation') }}</th>
                    <th style="padding:.7rem;">{{ __('Domain') }}</th>
                    <th style="padding:.7rem;">{{ __('Status') }}</th>
                    <th style="padding:.7rem;text-align:center;">{{ __('Users') }}</th>
                    <th style="padding:.7rem;text-align:center;">{{ __('Findings') }}</th>
                    <th style="padding:.7rem;text-align:center;">{{ __('Docs') }}</th>
                    <th style="padding:.7rem;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tenants as $t)
                    <tr style="border-top:1px solid #f2f2f2;">
                        <td style="padding:.7rem;"><strong style="color:#0B2545;">{{ $t->organization_name }}</strong></td>
                        <td style="padding:.7rem;color:#5A6B82;">{{ $t->primary_domain }}</td>
                        <td style="padding:.7rem;">
                            <span style="background:{{ $t->status === 'suspended' ? '#fdecea' : '#eef7f0' }};color:{{ $t->status === 'suspended' ? '#c0392b' : '#1e824c' }};border-radius:4px;padding:.15rem .5rem;font-size:.78rem;">
                                {{ $t->status }}
                            </span>
                        </td>
                        <td style="padding:.7rem;text-align:center;">{{ $t->users }}</td>
                        <td style="padding:.7rem;text-align:center;">{{ $t->findings }}</td>
                        <td style="padding:.7rem;text-align:center;">{{ $t->documents }}</td>
                        <td style="padding:.7rem;">
                            <a href="/operator/tenant/{{ $t->id }}" style="color:#17A2B8;text-decoration:none;">{{ __('Open') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="padding:1rem;color:#9aa;">{{ __('No tenants match.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>