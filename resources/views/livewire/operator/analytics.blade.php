<div style="padding:1.5rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.2rem;">
        <a href="/operator" style="color:#17A2B8;text-decoration:none;font-size:.9rem;">&larr; {{ __('Operator dashboard') }}</a>
        <div>
            <a href="/operator/marketplace/providers" style="color:#17A2B8;text-decoration:none;font-size:.9rem;margin-right:1rem;">{{ __('Marketplace') }}</a>
            <a href="/operator/logout" style="color:#5A6B82;text-decoration:none;font-size:.9rem;">{{ __('Sign out') }}</a>
        </div>
    </div>

    <h1 style="font-size:1.5rem;color:#1F3864;margin-bottom:.2rem;">{{ __('Analytics') }}</h1>
    <p style="color:#5A6B82;margin-bottom:1.4rem;font-size:.9rem;">
        @if ($capturedOn)
            {{ __('Snapshot captured :date. Collected daily, so figures may be up to a day old.', ['date' => $capturedOn]) }}
        @else
            {{ __('No snapshot captured yet. Run obaits:capture-snapshots.') }}
        @endif
    </p>

    {{-- Headline numbers --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;margin-bottom:1.5rem;">
        @foreach ([
            ['Tenants', $totals['tenants'], '#1F3864'],
            ['Devices', $totals['devices'], '#1F3864'],
            ['Users', $totals['users'], '#1F3864'],
            ['Findings', $totals['findings'], '#1F3864'],
            ['Not logged in (30d)', $totals['dormant'], $totals['dormant'] > 0 ? '#8B2F2F' : '#0B6E4F'],
        ] as [$label, $value, $colour])
            <div style="background:#fff;border-radius:12px;padding:1.1rem;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                <div style="font-size:1.8rem;font-weight:600;color:{{ $colour }};">{{ $value }}</div>
                <div style="color:#8fa3bf;font-size:.82rem;">{{ __($label) }}</div>
            </div>
        @endforeach
    </div>

    {{-- Engagement. Leads deliberately: this is the signal worth acting on. --}}
    <div style="background:#fff;border-radius:12px;padding:1.2rem;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:1.5rem;">
        <h2 style="font-size:1.05rem;color:#1F3864;margin:0 0 .3rem;">{{ __('Tenant engagement') }}</h2>
        <p style="color:#8fa3bf;font-size:.82rem;margin:0 0 .9rem;">
            {{ __('A tenant paying for capabilities it never uses is the clearest churn signal the platform has. Zero is the interesting value here.') }}
        </p>

        @if ($snapshots->isEmpty())
            <p style="color:#5A6B82;margin:0;">{{ __('No snapshots yet.') }}</p>
        @else
            <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
                <thead>
                    <tr style="text-align:left;color:#8fa3bf;font-size:.78rem;">
                        <th style="padding:.5rem .4rem;">{{ __('Tenant') }}</th>
                        <th style="padding:.5rem .4rem;">{{ __('Engagement') }}</th>
                        <th style="padding:.5rem .4rem;">{{ __('Devices') }}</th>
                        <th style="padding:.5rem .4rem;">{{ __('Users') }}</th>
                        <th style="padding:.5rem .4rem;">{{ __('Logins 30d') }}</th>
                        <th style="padding:.5rem .4rem;">{{ __('Scans') }}</th>
                        <th style="padding:.5rem .4rem;">{{ __('Policies') }}</th>
                        <th style="padding:.5rem .4rem;">{{ __('Backups') }}</th>
                        <th style="padding:.5rem .4rem;">{{ __('Findings') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($snapshots as $s)
                        @php [$label, $fg, $bg, $used] = \App\Livewire\Operator\Analytics::engagement($s); @endphp
                        <tr style="border-top:1px solid #eef2f6;">
                            <td style="padding:.7rem .4rem;">
                                <strong style="color:#3d4d61;">{{ $s->organization_name ?: $s->slug }}</strong><br>
                                <small style="color:#8fa3bf;">{{ $s->slug }}{{ $s->country ? ' · ' . $s->country : '' }}</small>
                            </td>
                            <td style="padding:.7rem .4rem;">
                                <span style="font-size:.75rem;padding:.15rem .55rem;border-radius:999px;color:{{ $fg }};background:{{ $bg }};">{{ $label }}</span>
                                <br><small style="color:#8fa3bf;">{{ __(':n of 6 features', ['n' => $used]) }}</small>
                            </td>
                            @foreach (['device_count','user_count','logins_30d','scans_total','policy_assignments','backup_selections','findings_total'] as $f)
                                <td style="padding:.7rem .4rem;color:{{ ($s->$f ?? 0) === 0 ? '#c0392b' : '#3d4d61' }};">{{ $s->$f ?? 0 }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Feature adoption. Zero is the interesting value. --}}
    <div style="background:#fff;border-radius:12px;padding:1.2rem;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:1.5rem;">
        <h2 style="font-size:1.05rem;color:#1F3864;margin:0 0 .3rem;">{{ __('Feature adoption, last 30 days') }}</h2>
        <p style="color:#8fa3bf;font-size:.82rem;margin:0 0 .9rem;">
            {{ __('An area no tenant has opened is a capability nobody wants, or one nobody can find. Recording began 30 August 2026.') }}
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:.6rem;">
            @foreach ($adoption as $area => $a)
                @php $dead = $a['tenants'] === 0; @endphp
                <div style="border:1px solid {{ $dead ? '#E6C9C9' : '#eef2f6' }};background:{{ $dead ? '#FDF6F6' : '#fff' }};border-radius:8px;padding:.7rem .9rem;">
                    <div style="font-size:.86rem;color:#3d4d61;text-transform:capitalize;">{{ str_replace('-', ' ', $area) }}</div>
                    <div style="font-size:1.1rem;font-weight:600;color:{{ $dead ? '#8B2F2F' : '#1F3864' }};margin-top:.2rem;">
                        {{ $a['tenants'] }}<span style="font-size:.8rem;color:#8fa3bf;font-weight:400;"> / {{ $tenantCount }} {{ __('tenants') }}</span>
                    </div>
                    <div style="font-size:.76rem;color:#8fa3bf;">{{ $a['views'] }} {{ __('views') }}</div>
                </div>
            @endforeach
        </div>
    </div>

    <div style="display:flex;gap:1.2rem;flex-wrap:wrap;">
        {{-- OS distribution --}}
        <div style="flex:1;min-width:340px;background:#fff;border-radius:12px;padding:1.2rem;box-shadow:0 1px 3px rgba(0,0,0,.08);">
            <h2 style="font-size:1.05rem;color:#1F3864;margin:0 0 .9rem;">{{ __('Operating systems across the platform') }}</h2>
            @if (empty($osDistribution))
                <p style="color:#5A6B82;margin:0;font-size:.9rem;">{{ __('No host inventory available.') }}</p>
            @else
                @php $max = max(array_column($osDistribution, 'count')); @endphp
                @foreach ($osDistribution as $name => $g)
                    <div style="margin-bottom:.8rem;">
                        <div style="display:flex;justify-content:space-between;font-size:.85rem;color:#3d4d61;">
                            <span>
                                {{ $name }}
                                @if (count($g['builds']) > 1)
                                    {{-- Several builds of one release is a patch consistency
                                         problem worth seeing, not noise to hide. --}}
                                    <span style="font-size:.72rem;color:#7A5C00;background:#FFF6DC;padding:.05rem .4rem;border-radius:999px;margin-left:.3rem;">
                                        {{ __(':n builds', ['n' => count($g['builds'])]) }}
                                    </span>
                                @endif
                            </span>
                            <span style="color:#8fa3bf;">{{ $g['count'] }}</span>
                        </div>
                        <div style="height:6px;background:#eef2f6;border-radius:3px;margin-top:.2rem;">
                            <div style="height:6px;background:#17A2B8;border-radius:3px;width:{{ round($g['count'] / $max * 100) }}%;"></div>
                        </div>
                        @if (count($g['builds']) > 1)
                            <div style="margin-top:.25rem;padding-left:.6rem;">
                                @foreach ($g['builds'] as $build => $n)
                                    <div style="color:#8fa3bf;font-size:.74rem;">{{ $build }} <span style="color:#b9c4d0;">&times;{{ $n }}</span></div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            @endif
        </div>

        {{-- Login trend --}}
        <div style="flex:1;min-width:340px;background:#fff;border-radius:12px;padding:1.2rem;box-shadow:0 1px 3px rgba(0,0,0,.08);">
            <h2 style="font-size:1.05rem;color:#1F3864;margin:0 0 .3rem;">{{ __('Logins, last 30 days') }}</h2>
            <p style="color:#8fa3bf;font-size:.82rem;margin:0 0 .9rem;">
                {{ __('Recording began 28 August 2026. Earlier activity was never captured and cannot be recovered.') }}
            </p>
            @if (empty($trend))
                <p style="color:#5A6B82;margin:0;font-size:.9rem;">{{ __('No logins recorded yet.') }}</p>
            @else
                @php $maxT = max($trend); @endphp
                <div style="display:flex;align-items:flex-end;gap:3px;height:120px;">
                    @foreach ($trend as $day => $n)
                        <div title="{{ $day }}: {{ $n }}"
                             style="flex:1;background:#17A2B8;border-radius:2px 2px 0 0;height:{{ max(4, round($n / $maxT * 110)) }}px;"></div>
                    @endforeach
                </div>
                <div style="display:flex;justify-content:space-between;color:#8fa3bf;font-size:.72rem;margin-top:.3rem;">
                    <span>{{ array_key_first($trend) }}</span><span>{{ array_key_last($trend) }}</span>
                </div>
            @endif
        </div>
    </div>
</div>
