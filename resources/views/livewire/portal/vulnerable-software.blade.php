<div>
    <h1 style="color:#0B2545;">{{ __('Vulnerable software') }}</h1>
    <p style="color:#5A6B82;">{{ __('Installed software on your computers that has known security flaws (CVEs), as detected by Fleet. Update or remove these first.') }}</p>
 
    @if (!$hasSecret || !$enrolled)
        <div style="background:#fff8e1;border-left:4px solid #b8860b;padding:1rem;border-radius:8px;">
            {{ __('No computers are enrolled yet, so there is no installed-software data to check. Once devices are enrolled, their vulnerable software appears here.') }}
        </div>
    @elseif (!$reachable)
        <div style="background:#fdecea;border-left:4px solid #c0392b;padding:1rem;border-radius:8px;color:#c0392b;">
            {{ __('We could not reach the device service just now. Please try again shortly.') }}
        </div>
    @elseif (empty($items))
        <div style="background:#eef7f0;border-left:4px solid #1e824c;padding:1rem;border-radius:8px;">
            {{ __('Fleet found no known-vulnerable software in what your machines currently report.') }}
            <span style="color:#5A6B82;">{{ __('This means "nothing flagged" — keep software updated, as new vulnerabilities are discovered over time.') }}</span>
        </div>
    @else
        <div style="margin:1rem 0;color:#5A6B82;">
            {{ trans_choice('{1}:count vulnerable package|[2,*]:count vulnerable packages', count($items), ['count' => count($items)]) }},
            {{ trans_choice('{1}:n known CVE|[2,*]:n known CVEs', $totalCves, ['n' => $totalCves]) }}.
        </div>
 
        <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
                <thead><tr style="text-align:left;color:#5A6B82;background:#f8f9fb;">
                    <th style="padding:.6rem;">{{ __('Software') }}</th>
                    <th style="padding:.6rem;">{{ __('Version') }}</th>
                    <th style="padding:.6rem;">{{ __('Known vulnerabilities') }}</th>
                    <th style="padding:.6rem;">{{ __('On devices') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($items as $it)
                    <tr style="border-top:1px solid #f2f2f2;">
                        <td style="padding:.6rem;"><strong style="color:#0B2545;">{{ $it['name'] }}</strong>
                            <div style="color:#9aa;font-size:.75rem;">{{ $it['source'] }}</div></td>
                        <td style="padding:.6rem;font-family:monospace;">{{ $it['version'] }}</td>
                        <td style="padding:.6rem;">
                            <div style="display:flex;gap:.3rem;flex-wrap:wrap;margin-bottom:.4rem;">
                                @if ($it['severity_counts']['critical'] > 0)
                                    <span style="display:inline-block;background:#fdecea;color:#c0392b;border-radius:4px;padding:.1rem .5rem;font-size:.78rem;font-weight:600;">{{ $it['severity_counts']['critical'] }} {{ __('critical') }}</span>
                                @endif
                                @if ($it['severity_counts']['high'] > 0)
                                    <span style="display:inline-block;background:#fff4e5;color:#a3620b;border-radius:4px;padding:.1rem .5rem;font-size:.78rem;font-weight:600;">{{ $it['severity_counts']['high'] }} {{ __('high') }}</span>
                                @endif
                                @if ($it['severity_counts']['other'] > 0)
                                    <span style="display:inline-block;background:#eef2f7;color:#5A6B82;border-radius:4px;padding:.1rem .5rem;font-size:.78rem;font-weight:600;">{{ $it['severity_counts']['other'] }} {{ __('other/unrated') }}</span>
                                @endif
                            </div>
                            <button type="button"
                                onclick="var l=document.getElementById('cves-{{ $loop->index }}'); var open = l.style.display !== 'none'; l.style.display = open ? 'none' : 'block'; this.textContent = open ? '{{ __('Show') }} {{ $it['cve_count'] }} {{ __('CVEs') }}' : '{{ __('Hide CVEs') }}';"
                                style="background:none;border:1px solid #dfe3ea;border-radius:4px;padding:.15rem .6rem;font-size:.75rem;color:#0B2545;cursor:pointer;">
                                {{ __('Show') }} {{ $it['cve_count'] }} {{ __('CVEs') }}
                            </button>
                            <div id="cves-{{ $loop->index }}" style="display:none;margin-top:.4rem;">
                                @foreach ($it['cves'] as $c)
                                    <a href="{{ $c['link'] }}" target="_blank" rel="noopener"
                                       title="{{ $c['severity'] ? $c['severity'] . ' (' . $c['score'] . ')' : __('Severity not yet rated') }}"
                                       style="display:inline-block;background:#fdecea;color:#c0392b;border-radius:4px;padding:.1rem .5rem;margin:.1rem;font-size:.78rem;text-decoration:none;">
                                        {{ $c['cve'] }}
                                    </a>
                                @endforeach
                            </div>
                        </td>
                        <td style="padding:.6rem;">
                            @foreach ($it['hosts'] as $hostName)
                                <span style="display:inline-block;background:#eef2f7;color:#0B2545;border-radius:4px;padding:.1rem .5rem;margin:.1rem;font-size:.78rem;">{{ $hostName }}</span>
                            @endforeach
                            <div style="color:#9aa;font-size:.72rem;margin-top:.2rem;">
                                {{ trans_choice('{1}:c device|[2,*]:c devices', $it['hosts_count'], ['c' => $it['hosts_count']]) }}
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
 
        <p style="color:#5A6B82;font-size:.85rem;margin-top:.8rem;">
            {{ __('Each CVE links to its official NVD entry, where you can read the severity and fix guidance. Packages affecting the most devices, or with the most CVEs, are listed first.') }}
        </p>
    @endif
</div>
