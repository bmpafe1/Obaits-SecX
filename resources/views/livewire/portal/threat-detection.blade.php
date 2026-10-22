<div>
    <h1 style="color:#0B2545;">{{ __('Threat Detection') }}</h1>
    <p style="color:#5A6B82;">{{ __('What Wazuh has detected across your monitored computers in the last :n days.', ['n' => $days]) }}</p>

    {{-- REAL FIX 2026-08-16: the Sources tab is now OUTSIDE the
         $hasGroup/$monitored gate -- baseline threat-intel protection
         runs on every enrolled device regardless of whether Wazuh
         monitoring is configured for this tenant, so its own tab must
         never disappear just because Wazuh isn't set up yet. --}}
    @php $tabs = ['fim'=>__('File Integrity'),'rootkit'=>__('Rootkit & Malware'),'firewall'=>__('Firewall Blocks'),'mitre'=>__('MITRE ATT&CK'),'sources'=>__('Threat Intelligence Sources')]; @endphp
    <div style="display:flex;gap:.3rem;border-bottom:2px solid #eee;margin:1rem 0;flex-wrap:wrap;">
        @foreach ($tabs as $k => $label)
            <button wire:click="setTab('{{ $k }}')"
                style="padding:.5rem 1rem;border:none;background:none;cursor:pointer;border-bottom:3px solid {{ $tab===$k ? '#17A2B8' : 'transparent' }};color:{{ $tab===$k ? '#0B2545' : '#5A6B82' }};font-weight:{{ $tab===$k ? '600':'400' }};">
                {{ $label }}
                @if ($k !== 'sources')
                    <span style="color:#9aa;">({{ count($buckets[$k]) }})</span>
                @endif
            </button>
        @endforeach
    </div>

    @if ($tab === 'sources')
        {{-- Real, platform-wide baseline protection data -- NOT
             tenant-specific, since this protection applies to every
             enrolled device regardless of tenant. Counts come from the
             actual device that most recently refreshed each source. --}}
        <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden;">
            <div style="padding:1rem 1.2rem;color:#5A6B82;font-size:.85rem;border-bottom:1px solid #f2f2f2;">
                {{ __('These sources power baseline protection, automatically enabled on every enrolled device — independent of any Group Policy you configure yourself.') }}
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
                <thead><tr style="text-align:left;color:#5A6B82;background:#f8f9fb;">
                    <th style="padding:.6rem;">{{ __('Source') }}</th>
                    <th style="padding:.6rem;">{{ __('Type') }}</th>
                    <th style="padding:.6rem;">{{ __('Entries blocked') }}</th>
                    <th style="padding:.6rem;">{{ __('Last refreshed') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($sources as $s)
                    <tr style="border-top:1px solid #f2f2f2;">
                        <td style="padding:.6rem;">
                            <a href="{{ $s['url'] }}" target="_blank" style="color:#17A2B8;text-decoration:none;">{{ $s['name'] }}</a>
                        </td>
                        <td style="padding:.6rem;color:#5A6B82;">{{ $s['type'] }}</td>
                        <td style="padding:.6rem;">
                            @if ($s['count'] !== null)
                                <strong>{{ number_format($s['count']) }}</strong>
                            @else
                                <span style="color:#9aa;">{{ __('Not yet reported') }}</span>
                            @endif
                        </td>
                        <td style="padding:.6rem;color:#5A6B82;font-size:.82rem;">
                            @if ($s['last_reported_at'])
                                {{ \Carbon\Carbon::parse($s['last_reported_at'])->diffForHumans() }}
                                @if ($s['last_reported_by'])
                                    <span style="color:#9aa;">({{ $s['last_reported_by'] }})</span>
                                @endif
                            @else
                                <span style="color:#9aa;">{{ __('—') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @elseif (!$hasGroup || !$monitored)
        {{-- Honest state: monitoring is not actually running. Never imply otherwise. --}}
        <div style="background:#fff8e1;border-left:4px solid #b8860b;padding:1rem;border-radius:8px;">
            {{ __('No monitored computers are reporting to Wazuh yet. Once agents are enrolled, their detections appear here. Until then, this page cannot show file, rootkit, firewall, or technique data — and we will not pretend it can.') }}
        </div>
    @else
        <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden;">
            @php $rows = $buckets[$tab]; @endphp
            @if (empty($rows))
                <div style="padding:1.5rem;color:#5A6B82;">
                    {{ __('Wazuh reported no events of this kind in the last :n days.', ['n' => $days]) }}
                    <span style="color:#1e824c;">{{ __('That is good news — but it means "nothing detected", not "nothing to detect".') }}</span>
                </div>
            @else
                <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
                    <thead><tr style="text-align:left;color:#5A6B82;background:#f8f9fb;">
                        <th style="padding:.6rem;">{{ __('When') }}</th>
                        <th style="padding:.6rem;">{{ __('Computer') }}</th>
                        @if ($tab==='fim')
                            <th style="padding:.6rem;">{{ __('File') }}</th><th style="padding:.6rem;">{{ __('Change') }}</th>
                        @elseif ($tab==='firewall')
                            <th style="padding:.6rem;">{{ __('Blocked source') }}</th><th style="padding:.6rem;">{{ __('Action') }}</th>
                        @elseif ($tab==='mitre')
                            <th style="padding:.6rem;">{{ __('Technique') }}</th><th style="padding:.6rem;">{{ __('Tactic') }}</th>
                        @else
                            <th style="padding:.6rem;">{{ __('Detection') }}</th>
                        @endif
                        <th style="padding:.6rem;">{{ __('Severity') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($rows as $r)
                        <tr style="border-top:1px solid #f2f2f2;">
                            <td style="padding:.6rem;white-space:nowrap;color:#5A6B82;">{{ $r['time'] }}</td>
                            <td style="padding:.6rem;">{{ $r['agent'] }}</td>
                            @if ($tab==='fim')
                                <td style="padding:.6rem;font-family:monospace;font-size:.82rem;">{{ $r['path'] }}</td>
                                <td style="padding:.6rem;">{{ $r['event'] }}</td>
                            @elseif ($tab==='firewall')
                                <td style="padding:.6rem;font-family:monospace;">{{ $r['srcip'] }}</td>
                                <td style="padding:.6rem;">{{ $r['action'] }}</td>
                            @elseif ($tab==='mitre')
                                <td style="padding:.6rem;"><strong>{{ $r['mitre_id'] }}</strong> — {{ $r['technique'] }}</td>
                                <td style="padding:.6rem;color:#5A6B82;">{{ $r['tactic'] }}</td>
                            @else
                                <td style="padding:.6rem;">
                                    <div>{{ $r['title'] ?? $r['description'] }}</div>
                                    @if (!empty($r['detail']))
                                        <div style="color:#5A6B82;font-size:.78rem;font-family:monospace;word-break:break-all;margin-top:.2rem;">
                                            {{ \Illuminate\Support\Str::limit($r['detail'], 160) }}
                                        </div>
                                    @endif
                                </td>
                            @endif
                            <td style="padding:.6rem;">
                                <span style="background:{{ $r['severity']==='high'?'#fdecea':($r['severity']==='medium'?'#fff4e5':'#eef7f0') }};color:{{ $r['severity']==='high'?'#c0392b':($r['severity']==='medium'?'#b8860b':'#1e824c') }};border-radius:4px;padding:.1rem .5rem;font-size:.78rem;">{{ $r['severity'] }}</span>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if ($tab==='firewall' && !empty($buckets['firewall']))
            <p style="color:#5A6B82;font-size:.85rem;margin-top:.8rem;">
                {{ __('These records are evidence that your firewall actively blocked attempts. They can be exported for compliance from the Evidence page.') }}
            </p>
        @endif
    @endif
</div>
