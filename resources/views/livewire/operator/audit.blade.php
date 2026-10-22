<div style="max-width:1100px;margin:2rem auto;padding:0 1rem;">
    <h1 style="color:#0B2545;">{{ __('Operator audit log') }}</h1>
    <p style="color:#5A6B82;">{{ __('Every operator login, tenant action, and data access is recorded here.') }}</p>

    <div style="display:flex;gap:.8rem;margin:1rem 0;flex-wrap:wrap;">
        <input type="text" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search by operator, reason, or tenant...') }}"
            style="flex:1;min-width:240px;padding:.5rem;border:1px solid #ddd;border-radius:6px;">
        <select wire:model.live="actionFilter" style="padding:.5rem;border:1px solid #ddd;border-radius:6px;">
            <option value="">{{ __('All actions') }}</option>
            @foreach ($actions as $a)
                <option value="{{ $a }}">{{ $a }}</option>
            @endforeach
        </select>
    </div>

    <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
            <thead>
                <tr style="text-align:left;color:#5A6B82;background:#f8f9fb;">
                    <th style="padding:.6rem;">{{ __('When') }}</th>
                    <th style="padding:.6rem;">{{ __('Operator') }}</th>
                    <th style="padding:.6rem;">{{ __('Action') }}</th>
                    <th style="padding:.6rem;">{{ __('Tenant') }}</th>
                    <th style="padding:.6rem;">{{ __('Reason') }}</th>
                    <th style="padding:.6rem;">{{ __('IP') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries as $e)
                    <tr style="border-top:1px solid #f2f2f2;">
                        <td style="padding:.6rem;white-space:nowrap;color:#5A6B82;">{{ $e->created_at }}</td>
                        <td style="padding:.6rem;">{{ $e->actor }}</td>
                        <td style="padding:.6rem;">
                            <span style="background:{{ str_contains($e->action, 'viewed_tenant_data') ? '#fdecea' : '#eef3f8' }};color:{{ str_contains($e->action, 'viewed_tenant_data') ? '#c0392b' : '#17A2B8' }};border-radius:4px;padding:.1rem .5rem;font-size:.78rem;white-space:nowrap;">
                                {{ $e->action }}
                            </span>
                        </td>
                        <td style="padding:.6rem;">{{ $e->organization_name ?? '—' }}</td>
                        <td style="padding:.6rem;color:#5A6B82;">{{ $e->reason ?? '—' }}</td>
                        <td style="padding:.6rem;color:#9aa;white-space:nowrap;">{{ $e->ip ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:1rem;color:#9aa;">{{ __('No audit entries match.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top:1rem;">{{ $entries->links() }}</div>
</div>