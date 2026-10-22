<!DOCTYPE html>
<html lang="{{ str_replace('_','-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SecX</title>
    @livewireStyles
</head>
<body style="margin:0; font-family:system-ui,sans-serif; background:#f4f7fb;">
    <div style="display:flex; min-height:100vh;">       
        <nav class="portal-nav" style="width:220px; background:#0B2545; color:#fff; padding:1.5rem 1rem; overflow-y:auto;">
            {{-- The PRODUCT is SecX; the company is Obaits. A user inside the
                 portal is using SecX, and the landing page they arrived from
                 says "SecX by Obaits" -- so the same identity either side of
                 the login is what makes it coherent. --}}
            <div style="text-align:center; margin-bottom:.5rem;">
                <div style="font-size:1.4rem; font-weight:bold; color:#fff; letter-spacing:-.02em;">SecX</div>
                <div style="font-size:.62rem; letter-spacing:.16em; text-transform:uppercase; color:#8fa3bf; margin-top:.1rem;">{{ __('by Obaits') }}</div>
            </div>
            @php
                // REAL FEATURE 2026-08-22, requested directly: the
                // tenant's own logo, resized/optimized on upload (see
                // Organisation::updatedLogo()), shown between the
                // "Obaits" brand label and "Dashboard", linking out to
                // their own real website.
                $tenantProfile = session('tenant_id')
                    ? \Illuminate\Support\Facades\DB::table('tenant_profiles')
                        ->where('tenant_id', session('tenant_id'))
                        ->first(['logo_path', 'website'])
                    : null;
            @endphp
            @if ($tenantProfile && $tenantProfile->logo_path)
                <div style="margin-bottom:1rem;text-align:center;">
                    @if ($tenantProfile->website)
                        <a href="{{ $tenantProfile->website }}" target="_blank" rel="noopener">
                            <img src="{{ Illuminate\Support\Facades\Storage::disk('public')->url($tenantProfile->logo_path) }}" alt="" style="max-height:48px;max-width:100%;">
                        </a>
                    @else
                        <img src="{{ Illuminate\Support\Facades\Storage::disk('public')->url($tenantProfile->logo_path) }}" alt="" style="max-height:48px;max-width:100%;">
                    @endif
                </div>
            @endif
            @php
                // Which section holds the current page -- so it starts open.
                //
                // RESTRUCTURED 2026-08-25. The old grouping was by VERB
                // (Monitoring = detection, Protection = enforcement,
                // Compliance = governance), which repeatedly failed to place
                // things: Case Management fitted none of them and needed its
                // own section in August, and Helpdesk sat under Organisation
                // purely for want of anywhere better.
                //
                // Now grouped by OBJECT -- what the page is ABOUT rather than
                // what the platform does to it. Assets, vulnerabilities,
                // incidents and certificates each have an obvious home, and a
                // new page's placement is usually self-evident.
                //
                // EVERY portal route is reachable from this menu. live-query
                // and document previously had routes but NO menu entry at all
                // and could only be reached by typing the URL.
                $path = request()->path();

                $inDashboard   = \Illuminate\Support\Str::is(['dashboard','portal/reports*'], $path);
                $inSecOps      = \Illuminate\Support\Str::is(['portal/alerts','portal/threats','portal/waf','portal/group-policies','portal/live-query'], $path);
                $inAssets      = \Illuminate\Support\Str::is(['portal/enroll','portal/assets','portal/device-groups','portal/software'], $path);
                $inVulnMgmt    = \Illuminate\Support\Str::is(['portal/scans*','portal/vulnerable-software','portal/web-scan-login'], $path);
                $inGrc         = \Illuminate\Support\Str::is(['portal/compliance','portal/evidence','portal/document*','portal/monitoring-evidence','portal/governance','portal/access','portal/posture'], $path);
                $inIncident    = \Illuminate\Support\Str::is(['portal/cases','portal/helpdesk*'], $path);
                $inInfra       = \Illuminate\Support\Str::is(['portal/health','portal/backups*'], $path);
                $inPhysical    = \Illuminate\Support\Str::is(['portal/cctv'], $path);
                $inCerts       = \Illuminate\Support\Str::is(['portal/domains','portal/ssl-certificates'], $path);
                $inAi          = \Illuminate\Support\Str::is(['portal/ai-assistant*'], $path);
                $inTrainings   = \Illuminate\Support\Str::is(['portal/trainings*'], $path);
                $inMarketplace = \Illuminate\Support\Str::is(['portal/marketplace*'], $path);
                $inAdmin       = \Illuminate\Support\Str::is(['portal/organisation','portal/team','portal/billing','portal/mail-settings','portal/api-keys','portal/help/*','portal/account'], $path);

                $navLink = function ($href, $label) {
                    $active = request()->is(ltrim($href, '/').'*') && request()->is(ltrim($href, '/'));
                    $active = request()->is(ltrim($href, '/'));
                    $color  = $active ? '#fff' : '#CADCFC';
                    $bg     = $active ? 'background:#17A2B8; border-radius:6px; padding-left:.6rem;' : 'padding-left:.6rem;';
                    return '<a href="'.$href.'" style="display:block; color:'.$color.'; padding:.45rem 0; '.$bg.' text-decoration:none; font-size:.92rem;">'.$label.'</a>';
                };

                $header = function ($label) {
                    return '<div style="display:flex; justify-content:space-between; align-items:center; cursor:pointer; color:#8fa3bf; font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; margin:1rem 0 .3rem; user-select:none;"><span>'.$label.'</span><span x-text="open ? \'−\' : \'+\'" style="font-size:1rem;"></span></div>';
                };
            @endphp

            <div x-data="{ open: {{ $inDashboard ? 'true' : 'false' }} }">
                <div @click="open = !open">{!! $header(__('Dashboard & Reports')) !!}</div>
                <div x-show="open" x-collapse>
                    {!! $navLink('/dashboard', __('Dashboard')) !!}
                    {!! $navLink('/portal/reports', __('Reports')) !!}
                </div>
            </div>

            <div x-data="{ open: {{ $inSecOps ? 'true' : 'false' }} }">
                <div @click="open = !open">{!! $header(__('Security Operations')) !!}</div>
                <div x-show="open" x-collapse>
                    {!! $navLink('/portal/alerts', __('Security alerts')) !!}
                    {!! $navLink('/portal/threats', __('Threat detection')) !!}
                    {!! $navLink('/portal/waf', __('Web firewall')) !!}
                    {!! $navLink('/portal/group-policies', __('Group Policies')) !!}
                    {!! $navLink('/portal/live-query', __('Live query')) !!}
                </div>
            </div>

            <div x-data="{ open: {{ $inAssets ? 'true' : 'false' }} }">
                <div @click="open = !open">{!! $header(__('Assets & Endpoints')) !!}</div>
                <div x-show="open" x-collapse>
                    {!! $navLink('/portal/enroll', __('Protect a computer')) !!}
                    {!! $navLink('/portal/assets', __('Assets')) !!}
                    {!! $navLink('/portal/device-groups', __('Device groups')) !!}
                    {!! $navLink('/portal/software', __('Software')) !!}
                </div>
            </div>

            <div x-data="{ open: {{ $inVulnMgmt ? 'true' : 'false' }} }">
                <div @click="open = !open">{!! $header(__('Vulnerability Management')) !!}</div>
                <div x-show="open" x-collapse>
                    {!! $navLink('/portal/scans', __('Scans')) !!}
                    {!! $navLink('/portal/vulnerable-software', __('Vulnerable software')) !!}
                    {!! $navLink('/portal/web-scan-login', __('Web scan login')) !!}
                </div>
            </div>

            <div x-data="{ open: {{ $inGrc ? 'true' : 'false' }} }">
                <div @click="open = !open">{!! $header(__('GRC & Risk')) !!}</div>
                <div x-show="open" x-collapse>
                    {!! $navLink('/portal/compliance', __('Compliance')) !!}
                    {!! $navLink('/portal/evidence', __('Policies & documents')) !!}
                    {!! $navLink('/portal/document', __('Document builder')) !!}
                    {!! $navLink('/portal/monitoring-evidence', __('Monitoring evidence')) !!}
                    {!! $navLink('/portal/governance', __('People and approvals')) !!}
                    {!! $navLink('/portal/access', __('Access review')) !!}
                    {!! $navLink('/portal/posture', __('Security posture')) !!}
                </div>
            </div>

            <div x-data="{ open: {{ $inIncident ? 'true' : 'false' }} }">
                <div @click="open = !open">{!! $header(__('Incident Response')) !!}</div>
                <div x-show="open" x-collapse>
                    {!! $navLink('/portal/cases', __('Case Management')) !!}
                    {!! $navLink('/portal/helpdesk', __('Helpdesk')) !!}
                </div>
            </div>

            <div x-data="{ open: {{ $inInfra ? 'true' : 'false' }} }">
                <div @click="open = !open">{!! $header(__('Infrastructure')) !!}</div>
                <div x-show="open" x-collapse>
                    {!! $navLink('/portal/health', __('IT asset health')) !!}
                    {!! $navLink('/portal/backups', __('Backup')) !!}
                </div>
            </div>

            <div x-data="{ open: {{ $inPhysical ? 'true' : 'false' }} }">
                <div @click="open = !open">{!! $header(__('Physical Security')) !!}</div>
                <div x-show="open" x-collapse>
                    {!! $navLink('/portal/cctv', __('CCTV Monitoring')) !!}
                </div>
            </div>

            <div x-data="{ open: {{ $inCerts ? 'true' : 'false' }} }">
                <div @click="open = !open">{!! $header(__('Certificates')) !!}</div>
                <div x-show="open" x-collapse>
                    {!! $navLink('/portal/domains', __('Domains')) !!}
                    {!! $navLink('/portal/ssl-certificates', __('SSL Certificates')) !!}
                </div>
            </div>

            <div x-data="{ open: {{ $inAi ? 'true' : 'false' }} }">
                <div @click="open = !open">{!! $header(__('AI Assistant')) !!}</div>
                <div x-show="open" x-collapse>
                    {!! $navLink('/portal/ai-assistant', __('Assistant')) !!}
                </div>
            </div>

            <div x-data="{ open: {{ $inTrainings ? 'true' : 'false' }} }">
                <div @click="open = !open">{!! $header(__('Trainings')) !!}</div>
                <div x-show="open" x-collapse>
                    {!! $navLink('/portal/trainings/awareness', __('Security Awareness')) !!}
                    {!! $navLink('/portal/trainings/certifications', __('Professional Certifications')) !!}
                    {!! $navLink('/portal/trainings/other', __('Other Trainings')) !!}
                </div>
            </div>

            <div x-data="{ open: {{ $inMarketplace ? 'true' : 'false' }} }">
                <div @click="open = !open">{!! $header(__('Marketplace')) !!}</div>
                <div x-show="open" x-collapse>
                    {!! $navLink('/portal/marketplace/services', __('Services')) !!}
                    {!! $navLink('/portal/marketplace/providers', __('Providers')) !!}
                    {!! $navLink('/portal/marketplace/requests', __('My Requests')) !!}
                </div>
            </div>

            <div x-data="{ open: {{ $inAdmin ? 'true' : 'false' }} }">
                <div @click="open = !open">{!! $header(__('Administration')) !!}</div>
                <div x-show="open" x-collapse>
                    {!! $navLink('/portal/organisation', __('Your organisation')) !!}
                    @if (session('user_role') === 'platform-admin')
                        {!! $navLink('/portal/team', __('Team')) !!}
                    @endif
                    {!! $navLink('/portal/billing', __('Billing')) !!}
                    {!! $navLink('/portal/mail-settings', __('Email delivery')) !!}
                    {!! $navLink('/portal/api-keys', __('API Keys')) !!}
                    {!! $navLink('/portal/help/mfa', __('Signing in safely')) !!}
                    {!! $navLink('/portal/account', __('Your account')) !!}
                </div>
            </div>

            <a href="/logout" style="display:block; color:#889; padding:.5rem 0 .5rem .6rem; text-decoration:none; margin-top:1.5rem; font-size:.92rem;">{{ __('Log out') }}</a>
        </nav>
        {{--
            REAL FIX 2026-08-13: sitewide layout audit. Confirmed via a
            full inventory of every one of the 31 portal views: 17 had NO
            width constraint at all (unbounded on a wide monitor), and the
            remaining 14 used FOUR different uncoordinated conventions
            (1200px, 1100px, 1000px, plus older 640/680/720px form pages)
            -- patching individual pages again would just add a fifth.
            Real, centralized fix: the width now lives HERE, once, in the
            one <main> every single portal page already renders inside.
            Two tiers, per Brian's explicit choice: a wide default for
            data-dense pages (tables, dashboards -- the vast majority),
            and an explicit narrow tier for simple single-column forms.
            A page opts into narrow via
            #[Layout('components.layouts.portal', ['maxWidth' => 'narrow'])]
            on its Livewire component class -- everything else,
            including every one of the 17 previously-unconstrained pages,
            gets the wide default automatically, with ZERO changes needed
            to those individual view files.
        --}}
        @php
            $portalMaxWidth = ($maxWidth ?? 'wide') === 'narrow' ? '720px' : '1200px';
        @endphp
        <main style="flex:1; padding:2rem;">
            <div style="max-width:{{ $portalMaxWidth }}; margin:0 auto;">
                {{ $slot }}
            </div>
        </main>
        <div style="display:flex;justify-content:flex-end;padding:.5rem 1rem;">
        @livewire('portal.notification-bell')
        </div>
    </div>
    @livewireScripts
    @livewire('brian-chat')
    <script src="{{ asset('js/chart.min.js') }}"></script>
    <script>
        function obaitsRenderCharts() {
            if (!window.Chart) return;
            document.querySelectorAll('canvas.obaits-chart').forEach(function (el) {
                if (el._chart) el._chart.destroy();
                el._chart = new Chart(el, {
                    type: el.dataset.type || 'doughnut',
                    data: {
                        labels: JSON.parse(el.dataset.labels || '[]'),
                        datasets: [{
                            data: JSON.parse(el.dataset.values || '[]'),
                            backgroundColor: JSON.parse(el.dataset.colors || '[]'),
                            borderWidth: 0
                        }]
                    },
                    options: {
                        cutout: '65%',
                        plugins: { legend: { display: el.dataset.legend === 'true', position: 'bottom' } }
                    }
                });
            });
        }

        // Draw on first page load…
        document.addEventListener('DOMContentLoaded', obaitsRenderCharts);
        // …and again after any Livewire update (this is the piece that was missing).
        document.addEventListener('livewire:navigated', obaitsRenderCharts);
        document.addEventListener('livewire:initialized', function () {
            obaitsRenderCharts();
            Livewire.hook('morphed', obaitsRenderCharts);
        });
    </script>
</body>
</html>
