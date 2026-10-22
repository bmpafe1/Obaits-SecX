<div>
    <h1 style="color:#0B2545;">{{ __('Case Management') }}</h1>
    <p style="color:#5A6B82;">{{ __('Track and manage security incidents for your organization.') }}</p>

    @if (session('case_ok'))
        <div style="background:#e6ffe6;padding:.8rem;border-radius:6px;margin-bottom:1rem;">{{ session('case_ok') }}</div>
    @endif

    @if (!$linked)
        <div style="background:#fff8e1;border-left:4px solid #b8860b;padding:1rem;border-radius:8px;">
            {{ __('Your organization is not yet linked to the case management system. Please contact support.') }}
        </div>
    @else
        <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
            <h3 style="margin-top:0;">{{ __('Open a new case') }}</h3>
            <form wire:submit="createCase" style="display:flex;flex-direction:column;gap:.6rem;">
                <input wire:model="newCaseName" placeholder="{{ __('Case name') }}" style="padding:.5rem;border:1px solid #dfe3ea;border-radius:6px;">
                @error('newCaseName') <small style="color:#c0392b;">{{ $message }}</small> @enderror
                <textarea wire:model="newCaseDescription" rows="2" placeholder="{{ __('Description (optional)') }}" style="padding:.5rem;border:1px solid #dfe3ea;border-radius:6px;"></textarea>
                <button type="submit" style="align-self:flex-start;background:#0B2545;color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;">{{ __('Create case') }}</button>
            </form>
        </div>

        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
            <h3 style="margin-top:0;">{{ __('Your cases') }}</h3>
            @if (empty($cases))
                <p style="color:#5A6B82;">{{ __('No cases yet. Open one above when something needs investigation.') }}</p>
            @else
                <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
                    <thead><tr style="text-align:left;color:#5A6B82;background:#f8f9fb;">
                        <th style="padding:.6rem;">{{ __('Case') }}</th>
                        <th style="padding:.6rem;">{{ __('Opened') }}</th>
                        <th style="padding:.6rem;">{{ __('Status') }}</th>
                        <th style="padding:.6rem;">{{ __('Owner') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($cases as $c)
                        <tr style="border-top:1px solid #f2f2f2;">
                            <td style="padding:.6rem;">
                                <a href="https://iris.{{ config('platform.base_domain') }}/case?cid={{ $c['case_id'] }}" target="_blank" style="color:#17A2B8;text-decoration:none;">{{ $c['case_name'] }}</a>
                            </td>
                            <td style="padding:.6rem;color:#5A6B82;">{{ $c['case_open_date'] }}</td>
                            <td style="padding:.6rem;">{{ $c['state_name'] ?? __('Open') }}</td>
                            <td style="padding:.6rem;color:#5A6B82;">{{ $c['owner'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <p style="color:#5A6B82;font-size:.8rem;margin-top:.8rem;">
                    {{ __('Clicking a case opens it in the full case management system, where you can add notes, evidence, and tasks.') }}
                </p>
            @endif
        </div>
    @endif
</div>
