<div>
    <h1 style="color:#0B2545;">{{ __('Welcome') }}, {{ session('auth_name') }}</h1>
    <p style="color:#5A6B82;">{{ $tenant->organization_name }}</p>

    {{-- ============ HEADLINE BANNER ============ --}}
    @if ($urgent > 0)
        <div style="background:#fdecea;border-left:4px solid #c0392b;padding:1rem;border-radius:8px;margin:1rem 0;">
            <strong style="color:#c0392b;">{{ $urgent }} {{ __('finding(s) need urgent attention.') }}</strong>
            @if ($kev > 0)
                — <strong style="color:#c0392b;">{{ $kev }} {{ __('actively exploited') }}</strong>
            @endif
            <a href="/portal/scans" style="color:#17A2B8;margin-left:.5rem;">{{ __('Review now') }}</a>
        </div>
    @else
        <div style="background:#eef7f0;border-left:4px solid #1e824c;padding:1rem;border-radius:8px;margin:1rem 0;">
            <strong style="color:#1e824c;">{{ __('No urgent findings right now.') }}</strong>
        </div>
    @endif

    {{-- ============ STAT CARDS ============ --}}
    <div style="display:flex; gap:1.2rem; margin-top:1rem; flex-wrap:wrap;">
        @php
            $cards = [
                ['label' => __('Urgent findings'), 'value' => $urgent, 'color' => $urgent ? '#c0392b' : '#1e824c'],
                ['label' => __('Actively exploited'), 'value' => $kev, 'color' => $kev ? '#c0392b' : '#1e824c'],
                ['label' => __('Documents finalised'), 'value' => $docsFinal, 'color' => '#17A2B8'],
                ['label' => __('Open incidents'), 'value' => $openIncidents, 'color' => $openIncidents ? '#b8860b' : '#1e824c'],
                ['label' => __('Monitoring'), 'value' => $monitored ? __('On') : __('Off'), 'color' => $monitored ? '#1e824c' : '#c0392b'],
            ];
            // Real IT asset health card: X of Y reporting devices are
            // genuinely healthy (no resource warning). Only shown once at
            // least one real device is reporting -- an empty "0/0" card
            // is noise, not honest signal. Links straight to the real
            // health page, matching Brian's own request to make it
            // actionable, not just informational.
            if ($healthDevices > 0) {
                $cards[] = [
                    'label' => __('Computers healthy'),
                    'value' => "{$healthyDevices}/{$healthDevices}",
                    'color' => $healthWarnings > 0 ? '#c0392b' : '#1e824c',
                    'href'  => $healthWarnings > 0 ? '/portal/health' : null,
                ];
            }
        @endphp
        @foreach ($cards as $c)
            @php $cardTag = !empty($c['href']) ? 'a' : 'div'; @endphp
            <{{ $cardTag }} @if(!empty($c['href'])) href="{{ $c['href'] }}" @endif
                style="background:#fff; border-radius:12px; padding:1.3rem 1.8rem; box-shadow:0 2px 8px rgba(0,0,0,.06); min-width:150px; text-decoration:none; display:block;">
                <div style="font-size:1.8rem; font-weight:bold; color:{{ $c['color'] }};">{{ $c['value'] }}</div>
                <div style="color:#5A6B82; font-size:.9rem;">{{ $c['label'] }}</div>
                @if (!empty($c['href']))
                    <div style="color:#c0392b;font-size:.78rem;margin-top:.3rem;">{{ __('Review now') }} →</div>
                @endif
            </{{ $cardTag }}>
        @endforeach
    </div>

    <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-top:1.5rem;align-items:flex-start;">

        {{-- ============ SEVERITY DONUT (Chart.js) ============ --}}
        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);flex:1;min-width:320px;">
            <h3 style="margin-top:0;color:#0B2545;">{{ __('Findings by severity') }}</h3>
            @php $sevTotal = array_sum($severity); @endphp
            @if ($sevTotal === 0)
                <p style="color:#9aa;font-size:.9rem;">{{ __('No findings recorded yet. Run a scan to populate this.') }}</p>
            @else
                @php
                    $sevLabels = [__('Critical'), __('High'), __('Medium'), __('Low'), __('Info')];
                    $sevValues = [$severity['critical'], $severity['high'], $severity['medium'], $severity['low'], $severity['info']];
                    $sevColors = ['#c0392b', '#e67e22', '#b8860b', '#17A2B8', '#95a5a6'];
                @endphp
                <div wire:ignore style="max-width:280px;margin:0 auto;">
                    <canvas
                        class="obaits-chart"
                        data-type="doughnut"
                        data-labels='@json($sevLabels)'
                        data-values='@json($sevValues)'
                        data-colors='@json($sevColors)'
                        data-legend="true"></canvas>
                </div>
            @endif
        </div>

        {{-- ============ MOST AT-RISK SYSTEMS ============ --}}
        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);flex:1;min-width:300px;">
            <h3 style="margin-top:0;color:#0B2545;">{{ __('Most at-risk systems') }}</h3>
            @forelse ($topHosts as $h)
                <div style="display:flex;justify-content:space-between;padding:.5rem 0;border-top:1px solid #f4f4f4;">
                    <span style="font-family:monospace;font-size:.88rem;">{{ $h->host }}</span>
                    <span style="color:#5A6B82;font-size:.85rem;">
                        {{ $h->total }} {{ __('findings') }}
                        @if ($h->urgent > 0)<span style="color:#c0392b;"> · {{ $h->urgent }} {{ __('urgent') }}</span>@endif
                    </span>
                </div>
            @empty
                <p style="color:#9aa;font-size:.9rem;">{{ __('No host-specific findings yet.') }}</p>
            @endforelse
        </div>
    </div>

    {{-- ============ COMPLIANCE PER FRAMEWORK (donut each) ============ --}}
    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-top:1.5rem;">
        <h3 style="margin-top:0;color:#0B2545;">{{ __('Compliance progress') }}</h3>
        @forelse ($frameworks as $i => $fw)
            <div style="display:flex;gap:1.5rem;align-items:center;flex-wrap:wrap;padding:1rem 0;border-top:{{ $i ? '1px solid #f2f2f2' : 'none' }};">
                @php
                    $fwLabels = [__('Finalised'), __('In progress'), __('Not started')];
                    $fwValues = [$fw['final'], $fw['partial'], $fw['notStarted']];
                    $fwColors = ['#1e824c', '#b8860b', '#e3e8ee'];
                @endphp
                <div wire:ignore style="width:130px;flex-shrink:0;">
                    <canvas
                        class="obaits-chart"
                        data-type="doughnut"
                        data-labels='@json($fwLabels)'
                        data-values='@json($fwValues)'
                        data-colors='@json($fwColors)'
                        data-legend="false"></canvas>
                </div>

                <div style="flex:1;min-width:240px;">
                    <div style="display:flex;justify-content:space-between;">
                        <strong style="color:#0B2545;">{{ $fw['name'] }}</strong>
                        <span style="color:#5A6B82;font-weight:600;">{{ $fw['percent'] }}% {{ __('complete') }}</span>
                    </div>
                    <div style="color:#5A6B82;font-size:.85rem;margin-top:.4rem;line-height:1.7;">
                        {{ $fw['required'] }} {{ __('documents required') }}<br>
                        <span style="color:#1e824c;">■</span> {{ $fw['final'] }} {{ __('finalised') }} ·
                        <span style="color:#b8860b;">■</span> {{ $fw['partial'] }} {{ __('in progress') }} ·
                        <span style="color:#c9d2de;">■</span> {{ $fw['notStarted'] }} {{ __('not started') }}
                    </div>
                </div>
            </div>
        @empty
            <p style="color:#9aa;font-size:.9rem;">
                {{ __('No compliance framework selected yet.') }}
                <a href="/portal/compliance" style="color:#17A2B8;">{{ __('Choose one') }}</a>
            </p>
        @endforelse
    </div>

    {{-- ============ COVERAGE (the honesty panel) ============ --}}
    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-top:1.5rem;">
        <h3 style="margin-top:0;color:#0B2545;">{{ __('Coverage') }}</h3>
        <p style="color:#5A6B82;font-size:.88rem;margin-top:0;">{{ __('What is actually protected — not what is assumed.') }}</p>
        {{-- Computers first: it is the largest part of most tenants' estate
             and was the one thing this panel never mentioned. --}}
        <div style="display:flex;justify-content:space-between;padding:.5rem 0;border-top:1px solid #f4f4f4;">
            <span>{{ __('Computers with the agent installed') }}</span>
            <span style="color:{{ $agentHosts > 0 ? '#1e824c' : '#c0392b' }};font-weight:600;">
                {{ $agentHosts > 0 ? $agentHosts : __('none') }}
            </span>
        </div>
        @php
            $lines = [
                ['label'=>__('Domains scanned'),'have'=>$coverage['domainsScanned'],'total'=>$coverage['domainsTotal']],
                ['label'=>__('Web firewall blocking'),'have'=>$coverage['wafBlocking'],'total'=>$coverage['wafTotal']],
            ];
        @endphp
        @foreach ($lines as $l)
            @php $none = $l['total'] === 0; $ok = !$none && $l['have'] >= $l['total']; @endphp
            <div style="display:flex;justify-content:space-between;padding:.5rem 0;border-top:1px solid #f4f4f4;">
                <span>{{ $l['label'] }}</span>
                <span style="color:{{ $none ? '#9aa' : ($ok ? '#1e824c' : '#b8860b') }};font-weight:600;">
                    @if ($none) {{ __('none set up') }} @else {{ $l['have'] }} / {{ $l['total'] }} @endif
                </span>
            </div>
        @endforeach
        <div style="display:flex;justify-content:space-between;padding:.5rem 0;border-top:1px solid #f4f4f4;">
            <span>{{ __('Continuous monitoring') }}</span>
            <span style="color:{{ $monitored ? '#1e824c' : '#c0392b' }};font-weight:600;">
                {{ $monitored ? __('active') : __('not configured') }}
            </span>
        </div>
        <div style="display:flex;justify-content:space-between;padding:.5rem 0;border-top:1px solid #f4f4f4;">
            <span>{{ __('Computers reporting health data') }}</span>
            <span style="color:{{ $healthDevices > 0 ? '#1e824c' : '#c0392b' }};font-weight:600;">
                {{ $healthDevices > 0 ? $healthDevices : __('none') }}
            </span>
        </div>
        @if ($healthWarnings > 0)
            <div style="display:flex;justify-content:space-between;padding:.5rem 0;border-top:1px solid #f4f4f4;">
                <span>{{ __('Computers with a resource warning (CPU/memory/disk)') }}</span>
                <span style="color:#c0392b;font-weight:600;">
                    {{ $healthWarnings }} —
                    <a href="/portal/health" style="color:#c0392b;text-decoration:underline;">{{ __('view') }}</a>
                </span>
            </div>
        @endif
        {{-- The limit of what any of the above means. --}}
        <div style="background:#f8f9fb;border-left:3px solid #b8860b;padding:.8rem;border-radius:6px;margin-top:1rem;font-size:.82rem;color:#5A6B82;">
            <strong style="color:#0B2545;">{{ __('What we cannot see') }}</strong><br>
            {{ __('We can only report on computers where the agent is installed, and on websites you have verified. A computer on your network without the agent is not “showing no problems” — it is invisible to us, and we cannot tell you anything about it.') }}
            @if ($agentHosts === 0)
                <div style="margin-top:.5rem;">
                    <a href="/portal/enroll" style="color:#17A2B8;font-weight:600;">{{ __('Protect a computer →') }}</a>
                </div>
            @else
                <div style="margin-top:.5rem;">
                    <a href="/portal/enroll" style="color:#17A2B8;">{{ __('Protect another computer →') }}</a>
                </div>
            @endif
        </div>
    </div>

    {{-- ============ INCIDENTS + RECENT SCANS ============ --}}
    <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-top:1.5rem;align-items:flex-start;">
        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);flex:1;min-width:280px;">
            <h3 style="margin-top:0;color:#0B2545;">{{ __('Incidents') }}</h3>
            <p style="color:#5A6B82;">
                <strong style="color:{{ $openIncidents ? '#b8860b' : '#1e824c' }};">{{ $openIncidents }}</strong> {{ __('open') }}
                @if ($avgHours !== null)
                    · {{ __('average resolution') }}: <strong>{{ $avgHours }}h</strong>
                @endif
            </p>
            <a href="/portal/helpdesk" style="color:#17A2B8;font-size:.9rem;">{{ __('Go to helpdesk') }}</a>
        </div>

        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);flex:1;min-width:280px;">
            <h3 style="margin-top:0;color:#0B2545;">{{ __('Recent scans') }}</h3>
            @forelse ($recentScans as $s)
                <div style="border-top:1px solid #f2f2f2;padding:.6rem 0;display:flex;justify-content:space-between;">
                    <span>{{ $s->target ?? '—' }}</span>
                    <span style="color:#5A6B82;font-size:.85rem;">{{ ucfirst($s->status) }} · {{ substr((string)$s->created_at,0,10) }}</span>
                </div>
            @empty
                <p style="color:#9aa;">{{ __('No scans yet.') }} <a href="/portal/scans" style="color:#17A2B8;">{{ __('Run one') }}</a></p>
            @endforelse
        </div>
    </div>
</div>