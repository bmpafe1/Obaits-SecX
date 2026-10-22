<div wire:poll.10s="refreshState">
    <h1 style="color:#0B2545;">{{ __('Backup') }}</h1>

    @if ($notice)
        <div style="background:{{ $noticeOk ? '#eef7f0' : '#fdecea' }};color:{{ $noticeOk ? '#1e5631' : '#7a2019' }};padding:.8rem;border-radius:6px;margin:1rem 0;">
            {{ $notice }}
        </div>
    @endif

    @if (!$connectionId)
        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
            <h3 style="margin-top:0;color:#0B2545;">{{ __('Protect your data') }}</h3>
            <p style="color:#5A6B82;">
                {{ __('Back up files from your enrolled devices. Every organisation gets 10GB free — add more storage any time.') }}
            </p>
            <button wire:click="connect" wire:loading.attr="disabled" wire:target="connect"
                style="background:#0B2545;color:#fff;border:none;border-radius:8px;padding:.6rem 1.2rem;cursor:pointer;">
                {{ __('Turn on backup') }}
            </button>
            <div wire:loading wire:target="connect" style="color:#5A6B82;font-size:.85rem;margin-top:.4rem;">
                {{ __('Setting up your backup storage…') }}
            </div>
        </div>
    @else

        {{-- Storage status — figures can lag IDrive's own reporting by up to
             24 hours; say so plainly rather than implying anything live. --}}
        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:1.5rem;">
            <h3 style="margin-top:0;color:#0B2545;">{{ __('Storage') }}</h3>
            @if ($status)
                @php
                    $usedBytes  = $status['storage_used'] ?? 0;
                    $quotaGb    = $status['storage_quota'] ?? null;
                    $usedGb     = round(($usedBytes ?? 0) / 1073741824, 2);
                    $readOnly   = $status['read_only_mode'] ?? false;
                @endphp
                <p style="color:#5A6B82;">
                    {{ __(':used GB used of :quota GB', ['used' => $usedGb, 'quota' => $quotaGb ?? '—']) }}
                    <span style="font-size:.8rem;color:#8a97a8;">{{ __('(figures may be up to 24h behind)') }}</span>
                </p>
                @if ($readOnly)
                    <p style="color:#b8860b;">{{ __('Your storage is over its limit — new backups are paused. Upgrade your plan to resume.') }}</p>
                @endif
            @else
                <p style="color:#5A6B82;">{{ __('Storage details are not available right now.') }}</p>
            @endif
        </div>

        {{-- Device / folder picker --}}
        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:1.5rem;">
            <h3 style="margin-top:0;color:#0B2545;">{{ __('Choose what to back up') }}</h3>

            @if (empty($hosts))
                <p style="color:#5A6B82;">{{ __('No devices are currently enrolled and online.') }}</p>
            @else
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;">
                    @foreach ($hosts as $h)
                        <button wire:click="selectHost({{ $h['id'] }})"
                            style="padding:.5rem 1rem;border:1px solid {{ $selectedHostId===$h['id'] ? '#0B2545' : '#ddd' }};border-radius:6px;background:{{ $selectedHostId===$h['id'] ? '#0B2545' : '#fff' }};color:{{ $selectedHostId===$h['id'] ? '#fff' : '#333' }};cursor:pointer;">
                            {{ $h['hostname'] }}
                            @unless($h['online'])<span style="opacity:.6;">({{ __('offline') }})</span>@endunless
                        </button>
                    @endforeach
                </div>

                @if ($selectedHostId)
                    <div wire:loading wire:target="selectHost,loadFolders,toggleExpand" style="color:#5A6B82;font-size:.85rem;margin-bottom:.5rem;">
                        {{ __('Asking the device…') }}
                    </div>

                    @foreach ($folderCache as $path => $folders)
                        @if (!empty($expandedPaths[$path]))
                            <div style="margin-bottom:.4rem;">
                                <div style="font-family:monospace;color:#8a97a8;font-size:.85rem;">{{ $path }}</div>
                                @if (empty($folders))
                                    <p style="color:#8a97a8;font-size:.85rem;padding-left:1rem;">{{ __('No subfolders found here.') }}</p>
                                @endif
                                @foreach ($folders as $f)
                                    <div style="display:flex;align-items:center;gap:.5rem;padding:.3rem 0 .3rem 1rem;">
                                        <input type="checkbox"
                                            {{ $this->isSelected($selectedHostId, $f['path']) ? 'checked' : '' }}
                                            wire:click="toggleSelection('{{ $f['path'] }}')">
                                        <button wire:click="toggleExpand('{{ $f['path'] }}')"
                                            style="background:none;border:none;color:#0B2545;cursor:pointer;text-align:left;">
                                            📁 {{ $f['name'] }}
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                @endif
            @endif
        </div>

        {{-- Selected folders + run controls --}}
        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:1.5rem;">
            <h3 style="margin-top:0;color:#0B2545;">{{ __('Protected folders') }}</h3>
            @php $active = collect($selections)->where('enabled', 1); @endphp
            @if ($active->isEmpty())
                <p style="color:#5A6B82;">{{ __('Nothing is selected yet — choose folders above.') }}</p>
            @else
                <ul style="color:#333;">
                    @foreach ($active as $s)
                        <li style="font-family:monospace;font-size:.9rem;">{{ $s->folder_path }}</li>
                    @endforeach
                </ul>
                <div style="display:flex;gap:.8rem;align-items:center;flex-wrap:wrap;">
                    <button wire:click="runNow" wire:loading.attr="disabled" wire:target="runNow"
                        style="background:#0B2545;color:#fff;border:none;border-radius:8px;padding:.6rem 1.2rem;cursor:pointer;">
                        {{ __('Back up now') }}
                    </button>

                    <label style="color:#5A6B82;font-size:.9rem;">
                        {{ __('Automatic backups:') }}
                        <select wire:change="setSchedule($event.target.value)"
                            style="margin-left:.4rem;padding:.4rem;border:1px solid #ddd;border-radius:6px;">
                            <option value="hourly"   {{ $currentSchedule==='hourly'   ? 'selected' : '' }}>{{ __('Every hour') }}</option>
                            <option value="every_6h" {{ $currentSchedule==='every_6h' ? 'selected' : '' }}>{{ __('Every 6 hours') }}</option>
                            <option value="daily"    {{ $currentSchedule==='daily'    ? 'selected' : '' }}>{{ __('Once a day') }}</option>
                            <option value="weekly"   {{ $currentSchedule==='weekly'   ? 'selected' : '' }}>{{ __('Once a week') }}</option>
                        </select>
                    </label>
                </div>
                <div wire:loading wire:target="runNow" style="color:#5A6B82;font-size:.85rem;margin-top:.4rem;">
                    {{ __('Starting backup…') }}
                </div>
            @endif
        </div>

        {{-- Run history --}}
        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
            <h3 style="margin-top:0;color:#0B2545;">{{ __('Recent backups') }}</h3>
            @if (empty($runs))
                <p style="color:#5A6B82;">{{ __('No backups have run yet.') }}</p>
            @else
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="text-align:left;color:#8a97a8;font-size:.85rem;">
                            <th style="padding:.4rem 0;">{{ __('Started') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th>{{ __('Size') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($runs as $r)
                            <tr style="border-top:1px solid #eee;">
                                <td style="padding:.5rem 0;">{{ \Illuminate\Support\Carbon::parse($r->started_at)->diffForHumans() }}</td>
                                <td>
                                    @if ($r->status === 'success')
                                        <span style="color:#1e5631;">✓ {{ __('Success') }}</span>
                                    @elseif ($r->status === 'running')
                                        <span style="color:#8a97a8;">{{ __('Running…') }}</span>
                                    @else
                                        <span style="color:#7a2019;" title="{{ $r->error }}">✕ {{ __('Failed') }}</span>
                                    @endif
                                </td>
                                <td>{{ $r->bytes_transferred ? number_format($r->bytes_transferred / 1048576, 1) . ' MB' : '—' }}</td>
                                <td>
                                    @if ($r->status === 'success' && $r->snapshot_id)
                                        <button wire:click="restoreRun('{{ $r->id }}')" wire:loading.attr="disabled" wire:target="restoreRun('{{ $r->id }}')"
                                            style="background:none;border:1px solid #0B2545;color:#0B2545;border-radius:6px;padding:.3rem .8rem;cursor:pointer;font-size:.85rem;">
                                            {{ __('Restore') }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        {{-- Restore history --}}
        @if (!empty($restores))
            <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-top:1.5rem;">
                <h3 style="margin-top:0;color:#0B2545;">{{ __('Restores') }}</h3>
                <p style="color:#5A6B82;font-size:.85rem;">
                    {{ __('Restored files are placed in a temporary folder on the device, never overwriting your current files. Your original folder structure is kept underneath that path — for example, /home/you/Documents becomes {path}/home/you/Documents.') }}
                </p>
                <table style="width:100%;border-collapse:collapse;">
                    <tbody>
                        @foreach ($restores as $rs)
                            <tr style="border-top:1px solid #eee;">
                                <td style="padding:.5rem 0;">{{ \Illuminate\Support\Carbon::parse($rs->started_at)->diffForHumans() }}</td>
                                <td>
                                    @if ($rs->status === 'success')
                                        <span style="color:#1e5631;">✓ {{ __('Complete') }}</span> —
                                        <span style="font-family:monospace;font-size:.85rem;color:#5A6B82;">{{ $rs->destination_path }}</span>
                                    @elseif ($rs->status === 'running')
                                        <span style="color:#8a97a8;">{{ __('In progress…') }}</span>
                                    @else
                                        <span style="color:#7a2019;" title="{{ $rs->error }}">✕ {{ __('Failed') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Upgrade storage — no automated checkout yet; this notifies an
             operator to invoice and provision manually rather than showing
             a button that does nothing. --}}
        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-top:1.5rem;">
            <h3 style="margin-top:0;color:#0B2545;">{{ __('Need more storage?') }}</h3>
            <p style="color:#5A6B82;font-size:.9rem;">{{ __('Choose a plan and our team will follow up to arrange payment.') }}</p>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                @foreach ($plans as $p)
                    <a href="{{ route('backup-checkout.show', $p->id) }}"
                        style="display:inline-block;padding:.5rem 1rem;border:1px solid #ddd;border-radius:6px;background:#fff;cursor:pointer;text-decoration:none;color:#333;">
                        {{ number_format($p->gb_size / 1024, 0) }} TB — ${{ number_format($p->price_usd_cents / 100, 0) }}/yr
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</div>
