<div>
    <h1 style="color:#0B2545;">{{ __('Software & Licenses') }}</h1>
    <p style="color:#5A6B82;">{{ __('The software your organization owns, with license and renewal details.') }}</p>

    @if (session('sw_ok'))
        <div style="background:#e6ffe6;padding:.8rem;border-radius:6px;margin-bottom:1rem;">{{ session('sw_ok') }}</div>
    @endif

    @if (\App\Support\Perm::can('manage_software'))
    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ $editingId ? __('Edit entry') : __('Add software') }}</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.8rem;">
            <label>{{ __('Name') }}<input wire:model="name" style="width:100%;padding:.4rem;"></label>
            <label>{{ __('Vendor') }}<input wire:model="vendor" style="width:100%;padding:.4rem;"></label>
            <label>{{ __('Licensed version') }}<input wire:model="version" style="width:100%;padding:.4rem;"></label>
            <label>{{ __('Seats owned') }}<input type="number" wire:model="seats_owned" style="width:100%;padding:.4rem;"></label>
            <label>{{ __('License type') }}
                <select wire:model="license_type" style="width:100%;padding:.4rem;">
                    <option value="subscription">{{ __('Subscription') }}</option>
                    <option value="perpetual">{{ __('Perpetual') }}</option>
                    <option value="open-source">{{ __('Open source') }}</option>
                </select>
            </label>
            <label>{{ __('Annual cost') }}<input wire:model="annual_cost" style="width:100%;padding:.4rem;"></label>
            <label>{{ __('Renews on') }}<input type="date" wire:model="renews_at" style="width:100%;padding:.4rem;"></label>
            <label>{{ __('Owner') }}<input wire:model="owner" style="width:100%;padding:.4rem;"></label>
            <label>{{ __('Name as Fleet reports it') }}<input wire:model="match_name" placeholder="{{ __('optional') }}" style="width:100%;padding:.4rem;"></label>
            <label>{{ __('Licence key') }}
                <input wire:model="license_key" placeholder="{{ __('optional') }}" style="width:100%;padding:.4rem;">
                <small style="color:#9aa;font-size:.75rem;">{{ __('Stored securely. Only administrators can view it, and every view is logged.') }}</small>
            </label>
            <label style="display:flex;align-items:center;gap:.4rem;margin-top:1.2rem;">
                <input type="checkbox" wire:model="approved"> {{ __('Approved for use') }}
            </label>
        </div>
        <div style="margin-top:.8rem;">
            <button wire:click="save" style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;">{{ $editingId ? __('Update') : __('Add') }}</button>
            @if ($editingId)
                <button wire:click="resetForm" style="background:#eee;border:none;border-radius:8px;padding:.5rem 1.2rem;margin-left:.5rem;">{{ __('Cancel') }}</button>
            @endif
        </div>
        @error('name') <small style="color:#c0392b;">{{ $message }}</small> @enderror
    </div>
    @endif

    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Software catalog') }}</h3>

        {{-- ② Storage meter --}}
        <div style="color:#5A6B82;font-size:.85rem;margin-bottom:.5rem;">
            {{ __('Installer storage') }}:
            <strong>{{ number_format($storageUsed / 1048576, 0) }} MB</strong>
            / {{ number_format($storageQuota / 1048576, 0) }} MB
        </div>

        {{-- Filters --}}
        <div style="display:flex;gap:.8rem;margin:1rem 0 .5rem;flex-wrap:wrap;">
            <input type="text" wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search by name, vendor, or owner...') }}"
                style="flex:1;min-width:220px;padding:.5rem;border:1px solid #ddd;border-radius:6px;">
            <select wire:model.live="approvedFilter" style="padding:.5rem;border:1px solid #ddd;border-radius:6px;">
                <option value="">{{ __('All software') }}</option>
                <option value="yes">{{ __('Approved') }}</option>
                <option value="no">{{ __('Not approved') }}</option>
            </select>
            <input type="date" wire:model.live="dateFrom" style="padding:.5rem;border:1px solid #ddd;border-radius:6px;">
            <span style="align-self:center;color:#5A6B82;">{{ __('to') }}</span>
            <input type="date" wire:model.live="dateTo" style="padding:.5rem;border:1px solid #ddd;border-radius:6px;">
        </div>
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
                    <th style="padding:.5rem;">{{ __('Name') }}</th>
                    <th style="padding:.5rem;">{{ __('Vendor') }}</th>
                    <th style="padding:.5rem;">{{ __('Seats') }}</th>
                    <th style="padding:.5rem;">{{ __('Licence key') }}</th>
                    <th style="padding:.5rem;">{{ __('Renews') }}</th>
                    <th style="padding:.5rem;">{{ __('Owner') }}</th>
                    <th style="padding:.5rem;">{{ __('Approved') }}</th>
                    {{-- ③ Installers column header --}}
                    <th style="padding:.5rem;">{{ __('Installers') }}</th>
                    @if (\App\Support\Perm::can('manage_software'))<th style="padding:.5rem;"></th>@endif
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $it)
                    <tr style="border-bottom:1px solid #f2f2f2;">
                        <td style="padding:.5rem;">{{ $it->name }}</td>
                        <td style="padding:.5rem;">{{ $it->vendor ?? '—' }}</td>
                        <td style="padding:.5rem;">{{ $it->seats_owned }}</td>

                        {{-- Licence key --}}
                        <td style="padding:.5rem;">
                            @if (empty($it->license_key))
                                <span style="color:#9aa;font-size:.85rem;">{{ __('none') }}</span>
                            @elseif (\App\Support\Perm::can('manage_software'))
                                @if ($revealedKeyId === $it->id)
                                    <span style="font-family:monospace;font-size:.8rem;background:#f8f9fb;padding:.2rem .4rem;border-radius:4px;">{{ $revealedKey }}</span>
                                    <button wire:click="hideKey" style="background:none;border:none;color:#17A2B8;cursor:pointer;font-size:.78rem;">{{ __('hide') }}</button>
                                @else
                                    <button wire:click="revealKey('{{ $it->id }}')" style="background:none;border:1px solid #ddd;border-radius:6px;padding:.2rem .6rem;color:#0B2545;cursor:pointer;font-size:.78rem;">{{ __('Reveal') }}</button>
                                @endif
                            @else
                                <span style="color:#1e824c;font-size:.85rem;">{{ __('on file') }}</span>
                            @endif
                        </td>

                        <td style="padding:.5rem;">
                            @if ($it->renews_at)
                                <span style="color:{{ $it->renews_at < $today ? '#c0392b' : ($it->renews_at <= $soon ? '#b8860b' : '#5A6B82') }};">
                                    {{ $it->renews_at }}@if ($it->renews_at < $today) ({{ __('expired') }})@endif
                                </span>
                            @else — @endif
                        </td>
                        <td style="padding:.5rem;">{{ $it->owner ?? '—' }}</td>
                        <td style="padding:.5rem;">{!! $it->approved ? '&#10003;' : '<span style=\'color:#c0392b;\'>&#10007;</span>' !!}</td>

                        {{-- ④ Installers cell --}}
                        <td style="padding:.5rem;">
                            @php $swFiles = $files[$it->id] ?? collect(); @endphp
                            <button wire:click="toggleFiles('{{ $it->id }}')"
                                style="background:none;border:1px solid #ddd;border-radius:6px;padding:.2rem .6rem;color:#0B2545;cursor:pointer;font-size:.8rem;">
                                {{ $swFiles->count() }} {{ __('file(s)') }}
                                {{ $expandedSoftware === $it->id ? '▲' : '▼' }}
                            </button>
                        </td>

                        @if (\App\Support\Perm::can('manage_software'))
                        <td style="padding:.5rem;">
                            <button wire:click="edit('{{ $it->id }}')" style="background:none;border:none;color:#17A2B8;cursor:pointer;">{{ __('Edit') }}</button>
                            <button wire:click="delete('{{ $it->id }}')" wire:confirm="{{ __('Delete this entry?') }}" style="background:none;border:none;color:#c0392b;cursor:pointer;">{{ __('Delete') }}</button>
                        </td>
                        @endif
                    </tr>

                    {{-- ⑤ Expandable installers panel --}}
                    @if ($expandedSoftware === $it->id)
                        <tr>
                            <td colspan="9" style="padding:0;background:#f8f9fb;">
                                <div style="padding:1rem 1.5rem;">
                                    @php $swFiles = $files[$it->id] ?? collect(); @endphp

                                    @if ($swFiles->isNotEmpty())
                                        <strong style="font-size:.85rem;color:#0B2545;">{{ __('Installers for') }} {{ $it->name }}</strong>
                                        @foreach ($swFiles as $f)
                                            <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.5rem 0;border-top:1px solid #eef1f5;">
                                                <div style="font-size:.85rem;">
                                                    {{ $f->original_name }}
                                                    @if ($f->version)<span style="color:#5A6B82;"> · v{{ $f->version }}</span>@endif
                                                    <span style="color:#9aa;"> · {{ number_format($f->size / 1048576, 1) }} MB</span>
                                                    <span style="background:#eef7f0;color:#1e824c;border-radius:4px;padding:.1rem .4rem;font-size:.72rem;margin-left:.3rem;">&#10003; {{ __('scanned') }}</span>
                                                    <div style="color:#9aa;font-family:monospace;font-size:.7rem;margin-top:.2rem;word-break:break-all;">SHA-256: {{ $f->sha256 }}</div>
                                                </div>
                                                <div style="white-space:nowrap;">
                                                    @if (\App\Support\Perm::can('manage_software'))
                                                        <button wire:click="download('{{ $f->id }}')" style="background:#17A2B8;color:#fff;border:none;border-radius:6px;padding:.3rem .8rem;font-size:.8rem;cursor:pointer;">{{ __('Download') }}</button>
                                                    @endif
                                                    @if (\App\Support\Perm::can('manage_team'))
                                                        <button wire:click="deleteFile('{{ $f->id }}')" wire:confirm="{{ __('Remove this installer?') }}" style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:.8rem;">{{ __('Remove') }}</button>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    @else
                                        <p style="color:#9aa;font-size:.85rem;margin:0;">{{ __('No installers uploaded yet.') }}</p>
                                    @endif

                                    {{-- Upload — ADMINISTRATORS ONLY --}}
                                    @if (\App\Support\Perm::can('manage_team'))
                                        <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid #e3e8ee;">
                                            <div style="background:#fff8e1;border-left:3px solid #b8860b;padding:.7rem;border-radius:6px;font-size:.82rem;color:#5A6B82;margin-bottom:.8rem;">
                                                {{ __('Only upload installers you obtained from the vendor and trust. Every file is scanned for malware before it is stored, and every upload and download is recorded.') }}
                                            </div>
                                            <input type="file" wire:model="upload" style="margin-bottom:.5rem;">
                                            <div wire:loading wire:target="upload" style="color:#5A6B82;font-size:.85rem;">{{ __('Uploading…') }}</div>
                                            @error('upload') <div style="color:#c0392b;font-size:.85rem;">{{ $message }}</div> @enderror
                                            <div style="display:flex;gap:.5rem;margin-top:.5rem;flex-wrap:wrap;">
                                                <input wire:model="uploadVersion" placeholder="{{ __('Version (optional)') }}" style="padding:.4rem;border:1px solid #ddd;border-radius:6px;">
                                                <input wire:model="uploadNotes" placeholder="{{ __('Notes (optional)') }}" style="flex:1;min-width:200px;padding:.4rem;border:1px solid #ddd;border-radius:6px;">
                                            </div>
                                            <div style="margin-top:.6rem;">
                                                <button wire:click="uploadFile" wire:loading.attr="disabled" wire:target="uploadFile" style="background:#0B2545;color:#fff;border:none;border-radius:6px;padding:.4rem 1rem;cursor:pointer;">{{ __('Scan and upload') }}</button>
                                                <span wire:loading wire:target="uploadFile" style="color:#5A6B82;font-size:.85rem;margin-left:.5rem;">{{ __('Scanning…') }}</span>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="9" style="padding:1rem;color:#9aa;">
                            {{ __('No software matches your filters.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="margin-top:1rem;">
            {{ $items->links() }}
        </div>
    </div>
</div>