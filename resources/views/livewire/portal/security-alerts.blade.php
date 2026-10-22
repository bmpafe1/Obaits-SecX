<div>
    <h1 style="color:#0B2545;">{{ __('Security alerts') }}</h1>
    <p style="color:#5A6B82;">{{ __('What your monitoring has noticed on your systems.') }}</p>

    @if ($state === 'not_configured')
        <div style="background:#fff3cd;border-left:4px solid #b8860b;padding:1rem;border-radius:6px;">
            <strong>{{ __('Monitoring is not set up yet.') }}</strong>
            <p style="color:#5A6B82;font-size:.9rem;margin:.3rem 0 0;">
                {{ __('Once your devices are reporting, this page will show what it finds.') }}
            </p>
        </div>

    @elseif ($state === 'store_unavailable')
        <div style="background:#fdecea;border-left:4px solid #c0392b;padding:1rem;border-radius:6px;">
            <strong style="color:#c0392b;">{{ __('We cannot reach your alert records.') }}</strong>
            <p style="color:#5A6B82;font-size:.9rem;margin:.3rem 0 0;">
                {{ __('Your monitoring may have stopped recording. We will not tell you everything is fine when we cannot check. Please contact support.') }}
            </p>
        </div>

    @elseif ($state === 'drift')
        <div style="background:#fdecea;border-left:4px solid #c0392b;padding:1rem;border-radius:6px;">
            <strong style="color:#c0392b;">{{ __('Your monitoring setup does not match our records.') }}</strong>
            <p style="color:#5A6B82;font-size:.9rem;margin:.3rem 0 0;">
                {{ __('We expected to find your monitoring group and could not. This needs to be fixed before we can report on your alerts. Please contact support.') }}
            </p>
        </div>

    @elseif ($state === 'unreachable')
        <div style="background:#fff3cd;border-left:4px solid #b8860b;padding:1rem;border-radius:6px;">
            <strong>{{ __('The monitoring system is not responding.') }}</strong>
            <p style="color:#5A6B82;font-size:.9rem;margin:.3rem 0 0;">{{ __('Please try again shortly.') }}</p>
        </div>

    @elseif ($state === 'no_agents')
        <div style="background:#fff3cd;border-left:4px solid #b8860b;padding:1rem;border-radius:6px;">
            <strong>{{ __('Nothing is being monitored yet.') }}</strong>
            <p style="color:#5A6B82;font-size:.9rem;margin:.3rem 0;">
                {{ __('Your monitoring is set up, but no devices are reporting to it. Until you enrol some, we cannot see what is happening on your systems — and neither can you.') }}
            </p>
            <a href="/portal/assets">{{ __('Enrol your devices') }}</a>
        </div>

    @else
        <div style="margin-bottom:1rem;">
            @foreach ([7, 30, 90] as $d)
                <button wire:click="setDays({{ $d }})"
                    style="background:{{ $days === $d ? '#0B2545' : '#f8f9fb' }};color:{{ $days === $d ? '#fff' : '#5A6B82' }};border:1px solid #ddd;border-radius:6px;padding:.3rem .8rem;margin-right:.3rem;cursor:pointer;">
                    {{ __('Last :n days', ['n' => $d]) }}
                </button>
            @endforeach
            <span style="color:#5A6B82;font-size:.85rem;margin-left:.6rem;">
                {{ __(':n device(s) monitored', ['n' => $agentCount]) }}
            </span>
        </div>

        @if (empty($alerts))
            <div style="background:#eef7f0;border-left:4px solid #1e824c;padding:1rem;border-radius:6px;">
                <strong style="color:#1e824c;">{{ __('Nothing to report.') }}</strong>
                <p style="color:#5A6B82;font-size:.9rem;margin:.3rem 0 0;">
                    {{ __('No security events on your :n monitored device(s) in the last :d days.', ['n' => $agentCount, 'd' => $days]) }}
                    {{ __('If monitoring was only recently switched on, it may simply not have seen anything yet.') }}
                </p>
            </div>
        @else
            @foreach ($alerts as $a)
                @php $c = $a['severity'] === 'high' ? '#c0392b' : ($a['severity'] === 'medium' ? '#b8860b' : '#5A6B82'); @endphp
                <div style="background:#fff;border-left:4px solid {{ $c }};border-radius:8px;padding:.9rem 1.1rem;margin-bottom:.6rem;box-shadow:0 1px 4px rgba(0,0,0,.06);">
                    <div style="display:flex;justify-content:space-between;gap:1rem;">
                        <strong style="color:#0B2545;">{{ $a['description'] }}</strong>
                        <span style="color:{{ $c }};font-size:.8rem;white-space:nowrap;">{{ __($a['severity']) }}</span>
                    </div>
                    <div style="color:#5A6B82;font-size:.82rem;margin-top:.2rem;">
                        {{ $a['agent'] }} · {{ $a['when'] }}
                    </div>
                    @if(\App\Support\Perm::can('manage_team'))
                        <div style="margin-top:.6rem;">
                            <button wire:click="incidentFromAlert(@json($a))" style="background:#c0392b;color:#fff;border:none;border-radius:6px;padding:.25rem .6rem;font-size:.8rem;">{{ __('Record this as an incident') }}</button>
                        </div>
                    @endif
                </div>
            @endforeach
        @endif
    @endif
</div>