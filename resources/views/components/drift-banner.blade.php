@props(['tenantId', 'tool', 'type', 'label'])

@php
    $status = \App\Support\Reconciliation\ArtifactStatus::statusFor($tenantId, $tool, $type);
@endphp

@if ($status === 'missing')
    <div style="background:#fdecea;border-left:4px solid #c0392b;padding:1rem;border-radius:6px;margin-bottom:1.5rem;">
        <strong style="color:#c0392b;">
            {{ __('Your :label is not connected as expected.', ['label' => $label]) }}
        </strong>
        <p style="color:#5A6B82;font-size:.9rem;margin:.3rem 0 0;">
            {{ __('Our records show this should be active, but the system does not have it set up. We will not tell you it is working when we cannot confirm it. Please contact support to restore it.') }}
        </p>
    </div>

@elseif ($status === 'unreachable')
    <div style="background:#fff3cd;border-left:4px solid #b8860b;padding:1rem;border-radius:6px;margin-bottom:1.5rem;">
        <strong>{{ __('We could not check your :label just now.', ['label' => $label]) }}</strong>
        <p style="color:#5A6B82;font-size:.9rem;margin:.3rem 0 0;">
            {{ __('The system did not respond to our last check. This does not mean anything is wrong — we will check again shortly.') }}
        </p>
    </div>
@endif