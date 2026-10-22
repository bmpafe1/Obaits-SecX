<div>
    <h1 style="color:#0B2545;">{{ __('Security posture') }}</h1>
    <p style="color:#5A6B82;">{{ __('How well your systems are protected and hardened.') }}</p>
 
    @if (!$group)
        <div style="background:#fff3cd;padding:1rem;border-radius:8px;">{{ __('Security monitoring is still being set up. Please check back shortly.') }}</div>
    @endif
 
    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Hardening score') }}</h3>
        @if (is_null($posture['avg_score']))
            <p style="color:#5A6B82;">{{ __('No hardening data yet. Once your devices are monitored, their security configuration score appears here.') }}</p>
        @else
            @php $score = $posture['avg_score']; $col = $score >= 80 ? '#1e824c' : ($score >= 50 ? '#b8860b' : '#c0392b'); @endphp
            <div style="font-size:2.5rem;font-weight:700;color:{{ $col }};">{{ $score }}%</div>
            <p style="color:#5A6B82;">{{ __('Average configuration-hardening score across your monitored devices.') }}</p>
        @endif
    </div>
 
    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Monitored devices') }}</h3>
        @if (empty($posture['agents']))
            <p style="color:#5A6B82;">{{ __('No devices are being monitored yet.') }}</p>
        @else
            <table style="width:100%;border-collapse:collapse;">
                <thead><tr style="text-align:left;color:#5A6B82;border-bottom:1px solid #eee;">
                    <th style="padding:.5rem;">{{ __('Device') }}</th>
                    <th style="padding:.5rem;">{{ __('Status') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($posture['agents'] as $a)
                        <tr style="border-bottom:1px solid #f2f2f2;">
                            <td style="padding:.5rem;">{{ $a['name'] }}</td>
                            <td style="padding:.5rem;">
                                <span style="color:{{ in_array($a['status'], ['active','connected']) ? '#1e824c' : '#5A6B82' }};">{{ __($a['status']) }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
 
    @if (!empty($posture['policies']))
    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Hardening checks') }}</h3>
        <table style="width:100%;border-collapse:collapse;">
            <thead><tr style="text-align:left;color:#5A6B82;border-bottom:1px solid #eee;">
                <th style="padding:.5rem;">{{ __('Check') }}</th>
                <th style="padding:.5rem;">{{ __('Device') }}</th>
                <th style="padding:.5rem;">{{ __('Score') }}</th>
                <th style="padding:.5rem;">{{ __('Passed') }}</th>
                <th style="padding:.5rem;">{{ __('Failed') }}</th>
            </tr></thead>
            <tbody>
                @foreach ($posture['policies'] as $p)
                    <tr style="border-bottom:1px solid #f2f2f2;">
                        <td style="padding:.5rem;">{{ $p['name'] }}</td>
                        <td style="padding:.5rem;">{{ $p['agent'] ?? '—' }}</td>
                        <td style="padding:.5rem;">{{ is_null($p['score']) ? '—' : $p['score'].'%' }}</td>
                        <td style="padding:.5rem;color:#1e824c;">{{ $p['pass'] ?? '—' }}</td>
                        <td style="padding:.5rem;color:#c0392b;">{{ $p['fail'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
