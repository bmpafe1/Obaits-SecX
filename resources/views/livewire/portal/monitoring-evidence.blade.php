<div>
    <h1 style="color:#0B2545;">{{ __('Monitoring evidence') }}</h1>
    <p style="color:#5A6B82;">
        {{ __('What your monitored computers have actually recorded, grouped by the compliance references the detection rules carry. Useful when an assessor asks you to demonstrate that monitoring is in place and working.') }}
    </p>

    <div style="display:flex;gap:.4rem;margin:1rem 0;">
        @foreach ([30, 90, 180] as $d)
            <button wire:click="setDays({{ $d }})"
                style="padding:.4rem 1rem;border:1px solid #17A2B8;border-radius:6px;cursor:pointer;
                       background:{{ $days === $d ? '#17A2B8' : '#fff' }};
                       color:{{ $days === $d ? '#fff' : '#0B2545' }};">
                {{ __('Last :n days', ['n' => $d]) }}
            </button>
        @endforeach
    </div>

    @if (!$hasAgents)
        <div style="background:#fff8e1;border-left:4px solid #b8860b;padding:1rem;border-radius:8px;">
            {{ __('No computers are enrolled yet, so there is nothing to evidence.') }}
            <a href="/portal/enroll" style="color:#17A2B8;">{{ __('Protect a computer →') }}</a>
        </div>
    @elseif (!$reachable)
        <div style="background:#fdecea;border-left:4px solid #c0392b;padding:1rem;border-radius:8px;color:#c0392b;">
            {{ __('We could not reach the monitoring service just now. Please try again shortly.') }}
        </div>
    @elseif (empty($evidence))
        <div style="background:#eef7f0;border-left:4px solid #1e824c;padding:1rem;border-radius:8px;">
            {{ __('No events carrying compliance references were recorded in this period.') }}
            <span style="color:#5A6B82;">{{ __('This means nothing was recorded — not that nothing happened.') }}</span>
        </div>
    @else
        <p style="color:#5A6B82;font-size:.85rem;">
            {{ trans_choice('{1}From :n monitored computer.|[2,*]From :n monitored computers.', $agentCount, ['n' => $agentCount]) }}
        </p>

        @foreach ($evidence as $fw => $refs)
            @php $meta = $frameworkMeta[$fw] ?? ['label' => $fw, 'version' => '', 'caveat' => false]; @endphp

            <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin:1.2rem 0;padding:1.2rem;">
                <h3 style="color:#0B2545;margin:0 0 .2rem;">{{ $meta['label'] }}</h3>
                <div style="color:#9aa;font-size:.78rem;margin-bottom:.6rem;">
                    {{ __('Rule references follow') }} {{ $meta['version'] }}
                </div>

                @if ($meta['caveat'])
                    <div style="background:#fdf6e3;border-left:4px solid #b8860b;padding:.7rem;border-radius:6px;margin-bottom:.8rem;font-size:.82rem;color:#7a5c00;">
                        {{ __('These references come from the detection rules, which follow PCI DSS 3.2.1. Requirement numbering changed in PCI DSS 4.0, so these are NOT a direct match for your own control numbers. Treat this as evidence of monitoring activity and let your assessor determine which requirements it supports.') }}
                    </div>
                @endif

                <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
                    <thead><tr style="text-align:left;color:#5A6B82;background:#f8f9fb;">
                        <th style="padding:.5rem;">{{ __('Reference') }}</th>
                        <th style="padding:.5rem;text-align:right;">{{ __('Events recorded') }}</th>
                        <th style="padding:.5rem;"></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($refs as $ref => $count)
                        <tr style="border-top:1px solid #f2f2f2;">
                            <td style="padding:.5rem;font-family:monospace;color:#0B2545;">{{ $ref }}</td>
                            <td style="padding:.5rem;text-align:right;">{{ number_format($count) }}</td>
                            <td style="padding:.5rem;text-align:right;">
                                <button wire:click="openControl('{{ $fw }}', '{{ $ref }}')"
                                    style="background:none;border:none;color:#17A2B8;cursor:pointer;font-size:.82rem;">
                                    {{ $openRef === $fw.'|'.$ref ? __('hide') : __('what was recorded?') }}
                                </button>
                            </td>
                        </tr>

                        @if ($openRef === $fw.'|'.$ref)
                            <tr><td colspan="3" style="padding:.6rem 1rem 1rem;background:#fafbfc;">
                                @if ($detail === null)
                                    <span style="color:#5A6B82;">{{ __('Loading…') }}</span>
                                @elseif (empty($detail['rules']))
                                    <span style="color:#5A6B82;">{{ __('No further detail available.') }}</span>
                                @else
                                    <div style="color:#5A6B82;font-size:.8rem;margin-bottom:.4rem;">
                                        {{ __('First recorded') }}: {{ $detail['first'] ?? '—' }} ·
                                        {{ __('Most recent') }}: {{ $detail['last'] ?? '—' }}
                                    </div>
                                    <strong style="font-size:.82rem;color:#0B2545;">{{ __('What was detected') }}</strong>
                                    <ul style="margin:.3rem 0 .6rem;padding-left:1.2rem;font-size:.82rem;color:#333;">
                                        @foreach ($detail['rules'] as $r)
                                            <li>{{ $r['description'] }} <span style="color:#9aa;">({{ number_format($r['count']) }})</span></li>
                                        @endforeach
                                    </ul>
                                    <strong style="font-size:.82rem;color:#0B2545;">{{ __('On which computers') }}</strong>
                                    <div style="font-size:.82rem;color:#333;">
                                        @foreach ($detail['hosts'] as $h)
                                            <span style="display:inline-block;background:#eef2f7;border-radius:4px;padding:.1rem .5rem;margin:.1rem;">
                                                {{ $h['name'] }} ({{ number_format($h['count']) }})
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </td></tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    @endif
</div>