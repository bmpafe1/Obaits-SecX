<div>
    <p><a href="/portal/scans">&larr; {{ __('Back to scans') }}</a></p>
    <h1 style="color:#0B2545;">{{ __('Findings') }}</h1>
    <p style="color:#5A6B82;">{{ __('Target') }}: {{ $scan->target }}</p>

    {{-- Headline: what actually matters --}}
    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin:1rem 0;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        @if ($counts['priority'] === 0)
            <p style="margin:0;color:#1e824c;font-size:1.1rem;">
                <strong>{{ __('Nothing needs your urgent attention.') }}</strong>
            </p>
            <p style="margin:.3rem 0 0;color:#5A6B82;">
                {{ __('We checked :total items. None of them are being exploited by attackers or are likely to be.', ['total' => $counts['total']]) }}
            </p>
        @else
            <p style="margin:0;color:#c0392b;font-size:1.1rem;">
                <strong>{{ __(':count of :total findings need your attention.', ['count' => $counts['priority'], 'total' => $counts['total']]) }}</strong>
            </p>
            <p style="margin:.3rem 0 0;color:#5A6B82;">
                {{ __('We ranked everything by real-world risk, not just technical severity. Start at the top.') }}
            </p>
        @endif
    </div>

    {{-- ============ FILTERS (new) ============ --}}
    <div style="display:flex;gap:.8rem;margin:1rem 0;flex-wrap:wrap;align-items:center;">
        <input type="text" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search by name, CVE, or host...') }}"
            style="flex:1;min-width:240px;padding:.5rem;border:1px solid #ddd;border-radius:6px;">

        <select wire:model.live="severityFilter" style="padding:.5rem;border:1px solid #ddd;border-radius:6px;">
            <option value="">{{ __('All severities') }}</option>
            <option value="5">{{ __('Critical') }}</option>
            <option value="4">{{ __('High') }}</option>
            <option value="3">{{ __('Medium') }}</option>
            <option value="2">{{ __('Low') }}</option>
            <option value="1">{{ __('Informational') }}</option>
        </select>

        @if ($counts['filtered'])
            <button wire:click="clearFilters"
                style="padding:.5rem 1rem;border:1px solid #ddd;border-radius:6px;background:#fff;color:#0B2545;cursor:pointer;">
                {{ __('Clear filters') }}
            </button>
        @endif
    </div>

    @if ($counts['filtered'])
        <p style="color:#5A6B82;font-size:.88rem;margin-top:-.4rem;">
            {{ __('Showing') }} <strong>{{ $counts['total'] }}</strong> {{ __('of') }} {{ $counts['grandTotal'] }} {{ __('findings.') }}
        </p>
        @if ($counts['total'] === 0)
            <div style="background:#fff;border-radius:12px;padding:2rem;text-align:center;color:#9aa;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:1rem;">
                {{ __('No findings match your filters.') }}
            </div>
        @endif
    @endif

    {{-- NEEDS ATTENTION --}}
    @if ($counts['priority'] > 0)
    <div style="background:#fff;border-left:4px solid #c0392b;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;color:#c0392b;">{{ __('Needs your attention') }} ({{ $counts['priority'] }})</h3>

        @foreach ($priority as $f)
            <div style="border-top:1px solid #f2f2f2;padding:1rem 0;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
                    <strong style="color:#0B2545;">{{ $f->name }}</strong>
                    <span style="background:{{ $f->criticality >= 5 ? '#c0392b' : ($f->criticality == 4 ? '#e67e22' : '#b8860b') }};color:#fff;border-radius:4px;padding:.15rem .6rem;font-size:.8rem;white-space:nowrap;">
                        {{ __(\App\Support\RiskEngine::label($f->criticality)) }}
                    </span>
                </div>

                <p style="color:#5A6B82;font-size:.85rem;margin:.3rem 0;">
                    {{ $f->host ?? '—' }}@if($f->port) : {{ $f->port }}@endif
                    @if($f->cve) · {{ $f->cve }} @elseif($f->cwe) · CWE-{{ $f->cwe }} @endif
                </p>

                @if (!empty($f->factors))
                    <ul style="color:#5A6B82;font-size:.9rem;margin:.5rem 0 0;padding-left:1.1rem;">
                        @foreach ($f->factors as $fa)
                            <li>
                                {{ __($fa['meaning'] ?? '') }}
                                <span style="color:#9aa;font-size:.85rem;">({{ $fa['source'] ?? '' }})</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @php $cwe = (int) preg_replace('/\D/', '', (string)($f->cwe ?? '')); @endphp
                @if ($f->scan_type === 'web' && in_array($cwe, [89, 79, 22, 98]))
                    <button wire:click="protectFromFinding('{{ $f->id }}')"
                        style="background:#17A2B8;color:#fff;border:none;border-radius:6px;padding:.3rem .8rem;font-size:.8rem;cursor:pointer;margin-top:.75rem;">
                        {{ __('Protect me from this') }}
                    </button>
                    @if(\App\Support\Perm::can('manage_team'))
                        <button wire:click="incidentFromFinding('{{ $f->id }}')" style="background:#c0392b;color:#fff;border:none;border-radius:6px;padding:.3rem .8rem;font-size:.8rem;cursor:pointer;margin-top:.75rem;margin-left:.5rem;">{{ __('Record this as an incident') }}</button>
                    @endif
                @endif
            </div>
        @endforeach
    </div>
    @endif

    @if ($patch && ($patch['supported'] ?? false))
        <div style="background:#fff;border:1px solid #ddd;border-radius:10px;padding:1.2rem;margin-top:1rem;">
            <h4 style="margin:0 0 .5rem;color:#0B2545;">{{ __('A rule to block this attack') }}</h4>

            <div style="background:#fff8e1;border-left:3px solid #b8860b;padding:.8rem;border-radius:6px;margin-bottom:.8rem;">
                {{ __($patch['caveat']) }}
            </div>

            <p style="color:#5A6B82;font-size:.85rem;">{{ __($patch['install']) }}</p>

            <textarea readonly rows="8"
                style="width:100%;font-family:monospace;font-size:.78rem;padding:.6rem;border:1px solid #ddd;border-radius:6px;background:#f8f9fb;">{{ $patch['rule'] }}</textarea>

            <button wire:click="dismissPatch" style="margin-top:.5rem;background:none;border:1px solid #ccc;border-radius:6px;padding:.3rem .8rem;cursor:pointer;">{{ __('Close') }}</button>
        </div>
    @elseif ($patch && !($patch['supported'] ?? false))
        <div style="background:#f8f9fb;border-radius:10px;padding:1rem;margin-top:1rem;">
            <p style="margin:0;color:#5A6B82;">{{ __($patch['note']) }}</p>
            <button wire:click="dismissPatch" style="margin-top:.5rem;background:none;border:1px solid #ccc;border-radius:6px;padding:.3rem .8rem;cursor:pointer;">{{ __('Close') }}</button>
        </div>
    @endif

    {{-- WORTH KNOWING (collapsible) --}}
    @if ($counts['other'] > 0)
    <div style="margin-bottom:1rem;">
        <button wire:click="toggleOther" style="background:none;border:none;color:#17A2B8;cursor:pointer;padding:.5rem 0;font-size:1rem;">
            {{ $showOther ? '▼' : '▶' }} {{ $counts['other'] }} {{ __('lower-priority issues') }}
        </button>
        @if ($showOther)
        <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-top:.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
            <p style="color:#5A6B82;font-size:.9rem;">{{ __('Real issues, but nobody is exploiting them and they are not exposed to the internet. Fix them when you can.') }}</p>
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="text-align:left;color:#5A6B82;border-bottom:1px solid #eee;">
                        <th style="padding:.5rem;">{{ __('Priority') }}</th>
                        <th style="padding:.5rem;">{{ __('Name') }}</th>
                        <th style="padding:.5rem;">{{ __('Host') }}</th>
                        <th style="padding:.5rem;">{{ __('Reference') }}</th>
                        <th style="padding:.5rem;">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($other as $f)
                        <tr style="border-bottom:1px solid #f2f2f2;">
                            <td style="padding:.5rem;">
                                <span style="color:#5A6B82;">{{ __(\App\Support\RiskEngine::label($f->criticality)) }}</span>
                            </td>
                            <td style="padding:.5rem;">{{ $f->name }}</td>
                            <td style="padding:.5rem;">{{ $f->host ?? '—' }}</td>
                            <td style="padding:.5rem;">
                                @if($f->cve) {{ $f->cve }} @elseif($f->cwe) CWE-{{ $f->cwe }} @else — @endif
                            </td>
                            <td style="padding:.5rem;">
                                @php $cwe = (int) preg_replace('/\D/', '', (string)($f->cwe ?? '')); @endphp
                                @if ($f->scan_type === 'web' && in_array($cwe, [89, 79, 22, 98]))
                                    <button wire:click="protectFromFinding('{{ $f->id }}')" style="background:#17A2B8;color:#fff;border:none;border-radius:6px;padding:.3rem .8rem;font-size:.8rem;cursor:pointer;">
                                        {{ __('Protect me from this') }}
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    @endif

    {{-- INFORMATIONAL (collapsible) --}}
    @if ($counts['info'] > 0)
    <div style="margin-top:1rem;">
        <button wire:click="toggleInfo" style="background:none;border:none;color:#17A2B8;cursor:pointer;padding:.5rem 0;">
            {{ $showInfo ? '▼' : '▶' }} {{ $counts['info'] }} {{ __('informational items (not risks)') }}
        </button>
        @if ($showInfo)
        <div style="background:#f8f9fb;border-radius:12px;padding:1.5rem;margin-top:.5rem;">
            <p style="color:#5A6B82;font-size:.9rem;">{{ __('These are details the scanner recorded about your system. They are not security risks.') }}</p>
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="text-align:left;color:#5A6B82;border-bottom:1px solid #eee;">
                        <th style="padding:.5rem;">{{ __('Name') }}</th>
                        <th style="padding:.5rem;">{{ __('Host') }}</th>
                        <th style="padding:.5rem;">{{ __('Port') }}</th>
                        <th style="padding:.5rem;">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($info as $f)
                        <tr style="border-bottom:1px solid #f2f2f2;">
                            <td style="padding:.5rem;">{{ $f->name }}</td>
                            <td style="padding:.5rem;">{{ $f->host ?? '—' }}</td>
                            <td style="padding:.5rem;">{{ $f->port ?? '—' }}</td>
                            <td style="padding:.5rem;">
                                @php $cwe = (int) preg_replace('/\D/', '', (string)($f->cwe ?? '')); @endphp
                                @if ($f->scan_type === 'web' && in_array($cwe, [89, 79, 22, 98]))
                                    <button wire:click="protectFromFinding('{{ $f->id }}')" style="background:#17A2B8;color:#fff;border:none;border-radius:6px;padding:.3rem .8rem;font-size:.8rem;cursor:pointer;">
                                        {{ __('Protect me from this') }}
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    @endif
</div>