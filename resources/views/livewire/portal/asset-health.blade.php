<div>
    <h1 style="color:#0B2545;">{{ __('IT asset health') }}</h1>
    <p style="color:#5A6B82;">{{ __('Real-time CPU, memory, and uptime for your enrolled devices, as reported by Zabbix.') }}</p>

    @if (!$hasGroup)
        <div style="background:#fff8e1;border-left:4px solid #b8860b;padding:1rem;border-radius:8px;">
            {{ __('IT asset health monitoring is not yet set up for this organization.') }}
        </div>
    @elseif (!$reachable)
        <div style="background:#fdecea;border-left:4px solid #c0392b;padding:1rem;border-radius:8px;color:#c0392b;">
            {{ __('We could not reach the health monitoring service just now. Please try again shortly.') }}
        </div>
    @elseif (empty($devices))
        <div style="background:#eef7f0;border-left:4px solid #1e824c;padding:1rem;border-radius:8px;">
            {{ __('No devices are reporting health data yet. Newly enrolled devices can take a few minutes to appear here.') }}
        </div>
    @else
        <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
                <thead><tr style="text-align:left;color:#5A6B82;background:#f8f9fb;">
                    <th style="padding:.6rem;">{{ __('Device') }}</th>
                    <th style="padding:.6rem;">{{ __('CPU') }}</th>
                    <th style="padding:.6rem;">{{ __('Memory') }}</th>
                    <th style="padding:.6rem;">{{ __('Disk') }}</th>
                    <th style="padding:.6rem;">{{ __('Uptime') }}</th>
                    <th style="padding:.6rem;">{{ __('Last seen') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($devices as $d)
                    <tr style="border-top:1px solid #f2f2f2;">
                        <td style="padding:.6rem;">
                            <strong style="color:#0B2545;">{{ $d['host'] }}</strong>
                            @if ($d['os'])
                                <div style="color:#9aa;font-size:.75rem;">{{ $d['os'] }}</div>
                            @endif
                        </td>
                        <td style="padding:.6rem;">
                            @if ($d['cpu_percent'] !== null)
                                @php
                                    $cpuColor = $d['cpu_percent'] >= 90 ? '#c0392b' : ($d['cpu_percent'] >= 70 ? '#a3620b' : '#1e824c');
                                @endphp
                                <span style="color:{{ $cpuColor }};font-weight:600;">{{ $d['cpu_percent'] }}%</span>
                            @else
                                <span style="color:#9aa;">{{ __('Not yet reported') }}</span>
                            @endif
                        </td>
                        <td style="padding:.6rem;">
                            @if ($d['memory_percent'] !== null)
                                @php
                                    $memColor = $d['memory_percent'] >= 90 ? '#c0392b' : ($d['memory_percent'] >= 70 ? '#a3620b' : '#1e824c');
                                @endphp
                                <span style="color:{{ $memColor }};font-weight:600;">{{ $d['memory_percent'] }}%</span>
                            @else
                                <span style="color:#9aa;">{{ __('Not yet reported') }}</span>
                            @endif
                        </td>
                        <td style="padding:.6rem;">
                            @if ($d['disk_percent'] !== null)
                                @php
                                    $diskColor = $d['disk_percent'] >= 90 ? '#c0392b' : ($d['disk_percent'] >= 70 ? '#a3620b' : '#1e824c');
                                @endphp
                                <span style="color:{{ $diskColor }};font-weight:600;">{{ $d['disk_percent'] }}%</span>
                            @else
                                <span style="color:#9aa;">{{ __('Not yet reported') }}</span>
                            @endif
                        </td>
                        <td style="padding:.6rem;color:#5A6B82;">
                            @if ($d['uptime_seconds'] !== null)
                                {{ \Carbon\CarbonInterval::seconds($d['uptime_seconds'])->cascade()->forHumans(['parts' => 2, 'short' => true]) }}
                            @else
                                <span style="color:#9aa;">{{ __('Not yet reported') }}</span>
                            @endif
                        </td>
                        <td style="padding:.6rem;color:#5A6B82;font-size:.8rem;">
                            @if ($d['last_seen'])
                                {{ \Carbon\Carbon::createFromTimestamp($d['last_seen'])->diffForHumans() }}
                            @else
                                {{ __('Never') }}
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
