<div>
    <h1 style="color:#0B2545;">{{ __('Device groups') }}</h1>
    <p style="color:#5A6B82;">{{ __('Organize your enrolled devices into groups (e.g. "Windows 10", "Windows Server", "Linux Server") so software can be pushed to them automatically.') }}</p>

    @if ($fleetError)
        <div style="background:#fdecea;border-left:4px solid #c0392b;padding:1rem;border-radius:8px;color:#c0392b;margin-bottom:1rem;">
            {{ $fleetError }}
        </div>
    @endif

    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:1.5rem;">
        <h3 style="margin-top:0;color:#0B2545;">{{ __('Create a group') }}</h3>
        <form wire:submit="createGroup" style="display:flex;gap:.6rem;">
            <input type="text" wire:model="newGroupName" placeholder="{{ __('e.g. Windows 10') }}"
                style="flex:1;padding:.5rem .7rem;border:1px solid #dfe3ea;border-radius:6px;">
            <select wire:model="newGroupAutoAssign" style="padding:.5rem .7rem;border:1px solid #dfe3ea;border-radius:6px;">
                @foreach (self::AUTO_ASSIGN_OPTIONS as $val => $label)
                    <option value="{{ $val }}">{{ __($label) }}</option>
                @endforeach
            </select>
            <button type="submit" style="background:#0B2545;color:#fff;border:none;border-radius:6px;padding:.5rem 1.2rem;cursor:pointer;">
                {{ __('Create') }}
            </button>
        </form>
        <p style="color:#9aa;font-size:.78rem;margin:.4rem 0 0;">
            {{ __('Choosing an auto-assign rule will automatically add matching newly enrolled devices to this group, and push any software already assigned to it.') }}
        </p>
        @error('newGroupName') <p style="color:#c0392b;font-size:.85rem;margin-top:.4rem;">{{ $message }}</p> @enderror
    </div>

    @if ($groups->isEmpty())
        <div style="background:#eef7f0;border-left:4px solid #1e824c;padding:1rem;border-radius:8px;">
            {{ __('No device groups yet. Create one above to get started.') }}
        </div>
    @else
        @foreach ($groups as $g)
            @php $members = $membersByGroup->get($g->id, collect()); @endphp
            <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:1rem;overflow:hidden;">
                <div style="padding:1rem 1.5rem;display:flex;justify-content:space-between;align-items:center;cursor:pointer;"
                     wire:click="toggleExpanded('{{ $g->id }}')">
                    <div>
                        <strong style="color:#0B2545;font-size:1.05rem;">{{ $g->name }}</strong>
                        <span style="color:#5A6B82;font-size:.85rem;margin-left:.6rem;">
                            {{ trans_choice('{1}:c device|[2,*]:c devices', $members->count(), ['c' => $members->count()]) }}
                        </span>
                        @if ($g->auto_assign_wazuh_group)
                            <span style="background:#eef7f0;color:#1e824c;font-size:.72rem;padding:.15rem .5rem;border-radius:10px;margin-left:.5rem;">
                                {{ __('Auto-assign: :rule', ['rule' => self::AUTO_ASSIGN_OPTIONS[$g->auto_assign_wazuh_group] ?? $g->auto_assign_wazuh_group]) }}
                            </span>
                        @endif
                    </div>
                    <button type="button" wire:click.stop="deleteGroup('{{ $g->id }}')"
                        onclick="return confirm('{{ __('Delete this group? Devices themselves are not affected.') }}')"
                        style="background:none;border:1px solid #c0392b;color:#c0392b;border-radius:6px;padding:.3rem .8rem;font-size:.8rem;cursor:pointer;">
                        {{ __('Delete') }}
                    </button>
                </div>

                @if ($expandedGroupId === $g->id)
                    <div style="border-top:1px solid #f2f2f2;padding:1rem 1.5rem;">
                        @if (empty($devices))
                            <p style="color:#9aa;font-size:.85rem;">{{ __('No enrolled devices found.') }}</p>
                        @else
                            {{-- Real search/filter, needed once a real tenant has many
                                 devices -- confirmed directly by Brian, an unbounded
                                 checkbox list does not scale. --}}
                            <div style="display:flex;gap:.6rem;margin-bottom:.8rem;">
                                <input type="text" wire:model.live.debounce.300ms="memberSearch"
                                    placeholder="{{ __('Search by hostname...') }}"
                                    style="flex:1;padding:.4rem .6rem;border:1px solid #dfe3ea;border-radius:6px;font-size:.85rem;">
                                <select wire:model.live="memberPlatformFilter"
                                    style="padding:.4rem .6rem;border:1px solid #dfe3ea;border-radius:6px;font-size:.85rem;">
                                    <option value="">{{ __('All platforms') }}</option>
                                    @foreach (collect($devices)->pluck('platform')->unique()->filter()->sort() as $p)
                                        <option value="{{ $p }}">{{ $p }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @php
                                $filteredDevices = collect($devices)->filter(function ($d) {
                                    $matchesSearch = $this->memberSearch === '' || str_contains(strtolower($d['hostname'] ?? ''), strtolower($this->memberSearch));
                                    $matchesPlatform = $this->memberPlatformFilter === '' || ($d['platform'] ?? '') === $this->memberPlatformFilter;
                                    return $matchesSearch && $matchesPlatform;
                                });
                            @endphp
                            <p style="color:#9aa;font-size:.78rem;margin:0 0 .4rem;">
                                {{ trans_choice('{1}:c device shown|[2,*]:c devices shown', $filteredDevices->count(), ['c' => $filteredDevices->count()]) }}
                                ({{ count($devices) }} {{ __('total') }})
                            </p>
                            {{-- Bounded height + scroll: the PAGE itself must never
                                 grow unboundedly with a real, large device count. --}}
                            <div style="max-height:320px;overflow-y:auto;border:1px solid #f2f2f2;border-radius:6px;">
                                @forelse ($filteredDevices as $d)
                                    @php
                                        $isMember = $members->contains(fn ($m) => (int) $m->fleet_host_id === (int) ($d['id'] ?? 0));
                                    @endphp
                                    <label style="display:flex;align-items:center;gap:.6rem;padding:.4rem .6rem;border-top:1px solid #f8f9fb;cursor:pointer;">
                                        <input type="checkbox" @checked($isMember)
                                            wire:click="toggleMember('{{ $g->id }}', {{ $d['id'] ?? 0 }}, '{{ $d['hostname'] ?? '' }}')">
                                        <span style="color:#0B2545;">{{ $d['hostname'] ?? __('Unknown device') }}</span>
                                        <span style="color:#9aa;font-size:.78rem;">{{ $d['platform'] ?? '' }}</span>
                                    </label>
                                @empty
                                    <p style="color:#9aa;font-size:.85rem;padding:.6rem;">{{ __('No devices match this search.') }}</p>
                                @endforelse
                            </div>
                        @endif

                        {{-- Real Phase C: auto-assign rule, editable. --}}
                        <div style="margin-top:1.2rem;padding-top:1rem;border-top:1px solid #f2f2f2;">
                            <h4 style="margin:0 0 .5rem;color:#0B2545;font-size:.95rem;">{{ __('Auto-assign rule') }}</h4>
                            <select wire:change="updateAutoAssign('{{ $g->id }}', $event.target.value)"
                                style="width:100%;padding:.4rem .6rem;border:1px solid #dfe3ea;border-radius:6px;font-size:.85rem;">
                                @foreach (self::AUTO_ASSIGN_OPTIONS as $val => $label)
                                    <option value="{{ $val }}" @selected($g->auto_assign_wazuh_group === $val)>{{ __($label) }}</option>
                                @endforeach
                            </select>
                            <p style="color:#9aa;font-size:.75rem;margin:.3rem 0 0;">
                                {{ __('Matching newly enrolled devices are added automatically (checked every 15 minutes), and any software already assigned to this group is pushed to them.') }}
                            </p>
                        </div>

                        {{-- Real Phase B: assigned software + assignment form. --}}
                        <div style="margin-top:1.2rem;padding-top:1rem;border-top:1px solid #f2f2f2;">
                            <h4 style="margin:0 0 .5rem;color:#0B2545;font-size:.95rem;">{{ __('Assigned software') }}</h4>
                            @php $assignments = $assignmentsByGroup->get($g->id, collect()); @endphp
                            @if ($assignments->isEmpty())
                                <p style="color:#9aa;font-size:.85rem;">{{ __('No software assigned to this group yet.') }}</p>
                            @else
                                @foreach ($assignments as $a)
                                    <div style="display:flex;justify-content:space-between;align-items:center;padding:.4rem 0;border-top:1px solid #f8f9fb;">
                                        <span style="color:#0B2545;font-size:.88rem;">{{ $a->software_name }} <span style="color:#9aa;">({{ $a->original_name }})</span></span>
                                        <button type="button" wire:click="removeSoftwareAssignment('{{ $a->id }}')"
                                            style="background:none;border:1px solid #c0392b;color:#c0392b;border-radius:6px;padding:.2rem .6rem;font-size:.75rem;cursor:pointer;">
                                            {{ __('Remove') }}
                                        </button>
                                    </div>
                                @endforeach
                            @endif

                            <form wire:submit="assignSoftware('{{ $g->id }}')" style="margin-top:.8rem;">
                                <select wire:model="assignSoftwareFileId" style="width:100%;padding:.4rem .6rem;border:1px solid #dfe3ea;border-radius:6px;font-size:.85rem;">
                                    <option value="">{{ __('Choose an uploaded installer...') }}</option>
                                    @foreach ($cleanFiles as $f)
                                        <option value="{{ $f->id }}">{{ $f->software_name }} — {{ $f->original_name }}</option>
                                    @endforeach
                                </select>
                                @error('assignSoftwareFileId') <p style="color:#c0392b;font-size:.8rem;margin:.3rem 0;">{{ $message }}</p> @enderror

                                <input type="text" wire:model="assignInstallCommand"
                                    placeholder="{{ __('Silent-install command (only needed for non-MSI installers, e.g. .exe)') }}"
                                    style="width:100%;padding:.4rem .6rem;border:1px solid #dfe3ea;border-radius:6px;font-size:.85rem;margin-top:.5rem;">
                                @error('assignInstallCommand') <p style="color:#c0392b;font-size:.8rem;margin:.3rem 0;">{{ $message }}</p> @enderror
                                <p style="color:#9aa;font-size:.75rem;margin:.3rem 0;">
                                    {{ __('Use %%INSTALLER_PATH%% in your command to refer to the downloaded file.') }}
                                </p>

                                <button type="submit" style="background:#0B2545;color:#fff;border:none;border-radius:6px;padding:.4rem 1rem;font-size:.85rem;cursor:pointer;margin-top:.4rem;">
                                    {{ __('Assign and push now') }}
                                </button>
                            </form>
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    @endif
</div>
