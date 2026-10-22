<div wire:poll.10s>
    <h1 style="color:#0B2545;">{{ __('Scans') }}</h1>
    @if (session('scan_ok'))
        <div style="background:#e6ffe6;padding:.8rem;border-radius:6px;margin-bottom:1rem;">{{ session('scan_ok') }}</div>
    @endif

    @if (\App\Support\Perm::can('run_scans'))
    {{-- REAL FIX 2026-08-18, requested directly: "Run a scan" and "Scan
         a website" now sit side by side on wide screens (flex:1 each,
         shared container), wrapping to a single stacked column on
         narrow screens instead of always stacking regardless of
         available width. --}}
    {{-- REQUESTED 2026-08-25: tell the user the rules BEFORE they hit them.
         Scans are serialised per tenant (see Scans::scanInProgress) because two
         concurrent scans of one estate is rarely intended, and the scanner pool
         is built on one member running one scan at a time. Saying so up front is
         better than refusing after the click. --}}
    <p style="color:#5A6B82;font-size:.9rem;background:#eef4fb;padding:.7rem .9rem;border-radius:8px;margin-bottom:1rem;">
        {{ __('One scan runs at a time for your organisation. A full scan of a device can take a couple of hours, and while it runs anyone else here will need to wait before starting another.') }}
    </p>

    <div style="display:flex;gap:1.2rem;flex-wrap:wrap;margin-bottom:1.5rem;">
    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);flex:1;min-width:320px;">
        <h3 style="margin-top:0;">{{ __('Run a scan') }}</h3>
        {{-- REAL FIX 2026-08-25, reported directly: the "no verified domains"
             message used to sit HERE, at the top of the "Run a scan" card --
             above the device dropdown, which works perfectly well without any
             domain. A customer with devices but no domains was told their
             options were unavailable when they were not. The message belongs in
             "Scan a website", which is the card that actually needs a domain. --}}
        @if ($verifiedDomains->isNotEmpty())
            <div style="display:flex;gap:.5rem;align-items:center;">
                <select wire:model="selectedDomain" style="padding:.5rem;">
                    <option value="">{{ __('Choose a verified domain') }}</option>
                    @foreach ($verifiedDomains as $d)
                        <option value="{{ $d->domain }}">{{ $d->domain }}</option>
                    @endforeach
                </select>
                <button wire:click="runScan" style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;">{{ __('Run scan') }}</button>
            </div>
            @error('selectedDomain') <small style="color:#c0392b;">{{ $message }}</small> @enderror
        @endif

        @php $assets = $this->assetTargets(); @endphp
        @if (!empty($assets))
            @php $scannable = array_filter($assets, fn($a) => $a['scannable']); @endphp
            <div style="margin-top:.8rem;padding-top:.8rem;border-top:1px solid #eee;">
                <label style="color:#5A6B82;font-size:.9rem;display:block;margin-bottom:.3rem;">{{ __('…or scan one of your enrolled devices:') }}</label>
                <div style="display:flex;gap:.5rem;align-items:center;">
                    <select wire:model="selectedAsset" style="padding:.5rem;">
                        <option value="">{{ __('Choose a device') }}</option>
                        @foreach ($assets as $a)
                            <option value="{{ $a['value'] }}" @disabled(!$a['scannable'])>
                                {{ $a['label'] }}@if(!$a['scannable']) — {{ __('not reachable') }}@endif
                            </option>
                        @endforeach
                    </select>
                    <button wire:click="runAssetScan" style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;">{{ __('Run scan') }}</button>
                </div>

                @if (count($scannable) < count($assets))
                    <p style="color:#5A6B82;font-size:.85rem;margin-top:.5rem;background:#fff3cd;padding:.6rem;border-radius:6px;">
                        {{ __('Some devices are on your internal network, so we cannot reach them from the internet to scan them. To scan them, connect them to the SecX secure network.') }}
                        <a href="/portal/enroll" style="color:#17A2B8;">{{ __('We will guide you through this.') }}</a>
                    </p>
                @endif
            </div>
        @endif
    </div>

    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);flex:1;min-width:320px;">
        <h3 style="margin-top:0;">{{ __('Scan a website') }}</h3>
        <p style="color:#5A6B82;font-size:.9rem;">{{ __('Tests a web application on one of your verified domains for vulnerabilities.') }}</p>
        @if ($verifiedDomains->isEmpty())
            <p style="color:#5A6B82;font-size:.9rem;background:#fff3cd;padding:.6rem;border-radius:6px;">
                {{ __('You have no verified domains yet. Add and verify a domain first.') }}
                <a href="/portal/domains" style="color:#17A2B8;">{{ __('Go to Domains') }}</a>
            </p>
        @endif
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
            <input wire:model="webUrl" placeholder="https://yoursite.com" style="padding:.5rem;min-width:260px;">
            <label style="color:#5A6B82;font-size:.9rem;">
                <input type="checkbox" wire:model="webActive"> {{ __('Deep scan (more thorough, more intrusive — only on sites you control)') }}
            </label>
            <button wire:click="runWebScan" style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;">{{ __('Scan website') }}</button>
        </div>
        @error('webUrl') <small style="color:#c0392b;">{{ $message }}</small> @enderror
    </div>
    </div>
    @endif

    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Recent scans') }}</h3>

        {{-- FILTERS: always visible, so a filter that returns nothing can still be cleared --}}
        <div style="display:flex; gap:.8rem; margin:1rem 0 .5rem; flex-wrap:wrap;">
            <input type="text" wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search by target...') }}"
                style="flex:1; min-width:200px; padding:.5rem; border:1px solid #ddd; border-radius:6px;">
            <select wire:model.live="statusFilter" style="padding:.5rem; border:1px solid #ddd; border-radius:6px;">
                <option value="">{{ __('All statuses') }}</option>
                <option value="queued">{{ __('Queued') }}</option>
                <option value="running">{{ __('Running') }}</option>
                <option value="done">{{ __('Done') }}</option>
                <option value="cancelled">{{ __('Cancelled') }}</option>
            </select>
            <input type="date" wire:model.live="dateFrom"
                style="padding:.5rem; border:1px solid #ddd; border-radius:6px;">
            <span style="align-self:center;color:#5A6B82;">{{ __('to') }}</span>
            <input type="date" wire:model.live="dateTo"
                style="padding:.5rem; border:1px solid #ddd; border-radius:6px;">
        </div>

        {{-- QUICK RANGES --}}
        <div style="display:flex;gap:.4rem;margin-bottom:1rem;flex-wrap:wrap;">
            @foreach (['today'=>__('Today'),'7'=>__('Last 7 days'),'30'=>__('Last 30 days'),'all'=>__('All')] as $r => $label)
                <button wire:click="quickRange('{{ $r }}')"
                    style="padding:.35rem .8rem;border:1px solid #ddd;border-radius:6px;background:#fff;color:#0B2545;cursor:pointer;font-size:.85rem;">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="text-align:left;color:#5A6B82;border-bottom:1px solid #eee;">
                    <th style="padding:.5rem;">{{ __('Target') }}</th>
                    <th style="padding:.5rem;">{{ __('Type') }}</th>
                    <th style="padding:.5rem;">{{ __('Date') }}</th>
                    <th style="padding:.5rem;">{{ __('Status') }}</th>
                    <th style="padding:.5rem;">{{ __('Progress') }}</th>
                    <th style="padding:.5rem;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($scans as $s)
                    <tr style="border-bottom:1px solid #f2f2f2;">
                        <td style="padding:.5rem;">{{ $s->target }}</td>
                        <td style="padding:.5rem;">
                            {{ $s->mode ?? '' }}
                            @if (($s->scan_type ?? 'network') === 'web')
                                <span style="background:#e0f7fa;color:#17A2B8;border-radius:4px;padding:.1rem .4rem;font-size:.75rem;margin-left:.3rem;">{{ __('web') }}</span>

                                {{-- How much of the site this scan could actually see. A short
                                     findings list from a scanner that never got past the login
                                     reads like good news and is not. --}}
                                @if (($s->authenticated ?? 'none') === 'yes')
                                    <div style="color:#1e824c;font-size:.72rem;margin-top:.25rem;">{{ __('scanned logged in') }}</div>
                                @elseif (($s->authenticated ?? 'none') === 'failed')
                                    <div style="color:#c0392b;font-size:.72rem;margin-top:.25rem;font-weight:bold;">
                                        {{ __('login failed — public pages only') }}
                                    </div>
                                    <div style="color:#c0392b;font-size:.7rem;">
                                        {{ __('Anything behind the login was not tested.') }}
                                        <a href="/portal/web-scan-login" style="color:#c0392b;">{{ __('check the login') }}</a>
                                    </div>
                                @else
                                    <div style="color:#9aa;font-size:.72rem;margin-top:.25rem;">{{ __('public pages only') }}</div>
                                @endif
                            @endif
                        </td>
                        <td style="padding:.5rem;">{{ \Illuminate\Support\Carbon::parse($s->created_at)->format('Y-m-d H:i') }}</td>
                        <td style="padding:.5rem;">
                            <span style="color:{{ $s->status === 'done' ? '#1e824c' : ($s->status === 'failed' ? '#c0392b' : '#b8860b') }};">
                                {{ $s->status }}
                            </span>
                        </td>
                        <td style="padding:.5rem;">{{ $s->progress ?? 0 }}%</td>
                        <td style="padding:.5rem;">
                            @if ($s->status === 'done')
                                <a href="/portal/scans/{{ $s->id }}/findings">{{ __('View findings') }}@if(($s->risk_count ?? 0) > 0) ({{ $s->risk_count }})@endif</a>
                            @elseif (in_array($s->status, ['queued','running']) && \App\Support\Perm::can('run_scans'))
                                <button wire:click="cancel('{{ $s->id }}')"
                                        wire:confirm="{{ __('Stop this scan?') }}"
                                        style="background:#F97068;color:#fff;border:none;border-radius:6px;padding:.3rem .7rem;">
                                    {{ __('Stop') }}
                                </button>
                            @endif
                            @if (\App\Support\Perm::can('delete_scans'))
                                <button wire:click="deleteScan('{{ $s->id }}')"
                                        wire:confirm="{{ __('Permanently delete this scan and all its findings? This cannot be undone.') }}"
                                        style="background:#c0392b;color:#fff;border:none;border-radius:6px;padding:.3rem .7rem;margin-left:.5rem;">
                                    {{ __('Delete') }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="padding:1rem;color:#9aa;">
                            {{ __('No scans match your filters.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="margin-top:1rem;">
            {{ $scans->links() }}
        </div>
    </div>
</div>