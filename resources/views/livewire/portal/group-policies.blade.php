<div>
    <h1 style="color:#0B2545;margin-bottom:.2rem;">{{ __('Group Policies') }}</h1>
    <p style="color:#5A6B82;font-size:.88rem;margin:0 0 1rem;">{{ __('Assign configuration checks to a device group, then optionally enforce them.') }}</p>

    {{-- REAL FEATURE 2026-08-13: the kill switch. Deliberately unmissable
         when paused -- red, top of page, above everything else -- since
         this state means every enforce/revert action across every
         assignment is currently suspended, and that must never be a
         state a tenant admin could fail to notice. --}}
    @if ($enforcementPausedAt)
        <div style="background:#fdecea;border:1px solid #f5c6cb;border-radius:10px;padding:.9rem 1.1rem;margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
            <div>
                <strong style="color:#c0392b;font-size:.9rem;">{{ __('All enforcement is paused.') }}</strong>
                <span style="color:#5A6B82;font-size:.82rem;">
                    {{ __('No policy can enforce or revert changes on any device right now. Paused by :who on :when.', ['who' => $enforcementPausedBy ?? __('unknown'), 'when' => \Carbon\Carbon::parse($enforcementPausedAt)->format('M j, g:i A')]) }}
                </span>
            </div>
            <button type="button" wire:click="resumeEnforcement" style="background:#c0392b;color:#fff;border:none;border-radius:6px;padding:.45rem 1rem;font-size:.85rem;cursor:pointer;white-space:nowrap;">
                {{ __('Resume enforcement') }}
            </button>
        </div>
    @else
        <div style="display:flex;justify-content:flex-end;margin-bottom:.6rem;">
            <button type="button" wire:click="pauseEnforcement"
                onclick="return confirm('{{ __('Pause ALL policy enforcement for this tenant? No policy will be able to enforce or revert changes on any device until resumed. Existing assignment settings are not changed.') }}')"
                style="background:none;border:1px solid #c0392b;color:#c0392b;border-radius:6px;padding:.35rem .8rem;font-size:.8rem;cursor:pointer;">
                {{ __('Pause all enforcement') }}
            </button>
        </div>
    @endif


    <div style="background:#fff;border-radius:12px;padding:1rem 1.25rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:1rem;">
        {{-- REAL FIX 2026-08-20: parameterized policies (IP Block, Website
             Block, Port Allow, Port Block) previously had NO way to enter
             their real parameters anywhere in the portal at all. --}}
        @php $selectedPolicy = $policies->firstWhere('id', $assignPolicyId); @endphp
        <form wire:submit="assignPolicy" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;">
            <strong style="color:#0B2545;font-size:.85rem;white-space:nowrap;">{{ __('Assign:') }}</strong>
            <select wire:model.live="assignPolicyId" style="flex:1;min-width:200px;padding:.4rem .6rem;border:1px solid #dfe3ea;border-radius:6px;font-size:.85rem;">
                <option value="">{{ __('Choose a policy...') }}</option>
                @foreach ($policies as $p)
                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                @endforeach
            </select>
            <strong style="color:#0B2545;font-size:.85rem;white-space:nowrap;">{{ __('to:') }}</strong>
            <select wire:model="assignGroupId" style="flex:1;min-width:160px;padding:.4rem .6rem;border:1px solid #dfe3ea;border-radius:6px;font-size:.85rem;">
                <option value="">{{ __('Choose a device group...') }}</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}">{{ $g->name }}</option>
                @endforeach
            </select>
            @if ($selectedPolicy && $selectedPolicy->requires_parameters)
                <div style="flex-basis:100%;">
                    <label style="color:#5A6B82;font-size:.8rem;display:block;margin-bottom:.3rem;">
                        {{ __('Enter one value per line') }}
                        @if ($selectedPolicy->resource_key === 'ip.block' || $selectedPolicy->resource_key === 'port.allow' || $selectedPolicy->resource_key === 'port.block')
                            ({{ __('e.g. 198.51.100.10, or 443/tcp for port policies') }})
                        @elseif ($selectedPolicy->resource_key === 'web.block')
                            ({{ __('e.g. example.com') }})
                        @endif
                    </label>
                    <textarea wire:model="assignParameters" rows="3" placeholder="{{ __('One value per line...') }}"
                        style="width:100%;padding:.5rem;border:1px solid #dfe3ea;border-radius:6px;font-size:.85rem;font-family:monospace;"></textarea>
                    @error('assignParameters') <p style="color:#c0392b;font-size:.8rem;margin:.3rem 0 0;">{{ $message }}</p> @enderror
                </div>
            @endif
            <button type="submit" style="background:#0B2545;color:#fff;border:none;border-radius:6px;padding:.45rem 1rem;font-size:.85rem;cursor:pointer;white-space:nowrap;">
                {{ __('Assign & check now') }}
            </button>
        </form>
        @error('assignPolicyId') <p style="color:#c0392b;font-size:.8rem;margin:.4rem 0 0;">{{ $message }}</p> @enderror
    </div>

    {{-- Filter bar -- render-time only, no new queries. Hidden entirely
         once there's nothing worth filtering, so a tenant with one or
         two assignments isn't shown controls with no real purpose yet. --}}
    @if ($totalAssignmentCount > 3)
        <div style="background:#fff;border-radius:12px;padding:.7rem 1rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:1rem;display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search by policy name...') }}"
                style="flex:1;min-width:180px;padding:.4rem .6rem;border:1px solid #dfe3ea;border-radius:6px;font-size:.83rem;">
            <select wire:model.live="statusFilter" style="padding:.4rem .6rem;border:1px solid #dfe3ea;border-radius:6px;font-size:.83rem;">
                <option value="">{{ __('All statuses') }}</option>
                <option value="needs_attention">{{ __('Needs attention') }}</option>
                <option value="enforced">{{ __('Enforced') }}</option>
                <option value="audit">{{ __('Audit only') }}</option>
            </select>
            <select wire:model.live="groupFilter" style="padding:.4rem .6rem;border:1px solid #dfe3ea;border-radius:6px;font-size:.83rem;">
                <option value="">{{ __('All device groups') }}</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}">{{ $g->name }}</option>
                @endforeach
            </select>
            @if ($search !== '' || $statusFilter !== '' || $groupFilter !== '')
                <button type="button" wire:click="clearFilters" style="background:none;border:1px solid #dfe3ea;color:#5A6B82;border-radius:6px;padding:.4rem .8rem;font-size:.8rem;cursor:pointer;">
                    {{ __('Clear') }}
                </button>
                <span style="color:#9aa;font-size:.78rem;">{{ __(':n of :total', ['n' => $assignments->count(), 'total' => $totalAssignmentCount]) }}</span>
            @endif
        </div>
    @endif

    @if ($assignments->isEmpty())
        <div style="background:#eef7f0;border-left:4px solid #1e824c;padding:.8rem 1rem;border-radius:8px;font-size:.88rem;">
            {{ $totalAssignmentCount === 0 ? __('No policies assigned yet. Assign one above to get started.') : __('No assignments match the current filter.') }}
        </div>
    @else
        @foreach ($assignments as $a)
            @php
                $runs = $runsByAssignment->get($a->id, collect());
                $success = $runs->where('status', 'success')->count();
                $failed = $runs->where('status', 'failed')->count();
                $pending = $runs->whereIn('status', ['pending', 'running'])->count();
                $nonCompliant = $runs->where('status', 'success')->where('result', 'firewall_disabled')->count();
                $isEnforced = $a->mode === 'enforce';
                $isExpanded = $expandedAssignmentId === $a->id;
            @endphp
            <div style="background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:.6rem;overflow:hidden;">
                {{-- Summary row -- always visible, click to expand. Mirrors
                     DeviceGroups' own expand/collapse pattern. --}}
                <div style="padding:.75rem 1.25rem;display:flex;justify-content:space-between;align-items:center;cursor:pointer;gap:1rem;"
                     wire:click="toggleExpanded('{{ $a->id }}')">
                    <div style="display:flex;align-items:center;gap:.6rem;min-width:0;flex-wrap:wrap;">
                        <strong style="color:#0B2545;font-size:.95rem;">{{ $a->policy_name }}</strong>
                        @if ($isEnforced)
                            <span style="background:#fdecea;color:#c0392b;font-size:.7rem;padding:.1rem .45rem;border-radius:10px;">{{ __('Enforced') }}</span>
                        @else
                            <span style="background:#eef7f0;color:#1e824c;font-size:.7rem;padding:.1rem .45rem;border-radius:10px;">{{ __('Audit') }}</span>
                        @endif
                        <span style="color:#9aa;font-size:.78rem;">{{ $a->group_name }}</span>
                        @if ($nonCompliant > 0 && !$isEnforced)
                            <span style="color:#c0392b;font-size:.78rem;">{{ trans_choice('{1}:c needs attention|[2,*]:c need attention', $nonCompliant, ['c' => $nonCompliant]) }}</span>
                        @endif
                        @if ($pending > 0)
                            <span style="color:#9aa;font-size:.78rem;">{{ trans_choice('{1}:c running|[2,*]:c running', $pending, ['c' => $pending]) }}</span>
                        @endif
                    </div>
                    <div style="display:flex;gap:.4rem;flex-shrink:0;" wire:click.stop>
                        <button type="button" wire:click="rerunCheck('{{ $a->id }}')"
                            style="background:none;border:1px solid #0B2545;color:#0B2545;border-radius:6px;padding:.25rem .65rem;font-size:.78rem;cursor:pointer;">{{ __('Re-check') }}</button>
                        @if (!$isEnforced)
                            <button type="button" wire:click="confirmEnableEnforcement('{{ $a->id }}')"
                                style="background:#c0392b;color:#fff;border:none;border-radius:6px;padding:.25rem .65rem;font-size:.78rem;cursor:pointer;">{{ __('Enforce') }}</button>
                        @else
                            <button type="button" wire:click="confirmDisableEnforcement('{{ $a->id }}')"
                                style="background:none;border:1px solid #5A6B82;color:#5A6B82;border-radius:6px;padding:.25rem .65rem;font-size:.78rem;cursor:pointer;">{{ __('Disable') }}</button>
                        @endif
                        <button type="button" wire:click="removeAssignment('{{ $a->id }}')"
                            onclick="return confirm('{{ __('Remove this policy assignment? Its check history is kept.') }}')"
                            style="background:none;border:1px solid #c0392b;color:#c0392b;border-radius:6px;padding:.25rem .65rem;font-size:.78rem;cursor:pointer;">{{ __('Remove') }}</button>
                    </div>
                </div>

                @if ($isExpanded)
                    <div style="border-top:1px solid #f2f2f2;padding:.9rem 1.25rem;">
                        <p style="color:#5A6B82;font-size:.83rem;margin:0 0 .6rem;">{{ $a->policy_description }}</p>

                        @if ($confirmingEnforceId === $a->id)
                            <div style="margin-bottom:.8rem;padding:.8rem;background:#fdecea;border-radius:8px;">
                                <p style="color:#0B2545;font-weight:600;font-size:.88rem;margin:0 0 .3rem;">{{ __('Enable enforcement for :policy?', ['policy' => $a->policy_name]) }}</p>
                                <p style="color:#5A6B82;font-size:.83rem;margin:0 0 .6rem;">
                                    @if ($confirmingEnforceCount > 0)
                                        {{ trans_choice('This will turn the firewall ON on {1}:c device that currently has it off|[2,*]:c devices that currently have it off', $confirmingEnforceCount, ['c' => $confirmingEnforceCount]) }}. {{ __('Devices already compliant are left alone.') }}
                                    @else
                                        {{ __('No devices currently need a change. Enforcement will still be enabled and will act on any device found out of compliance in future checks.') }}
                                    @endif
                                </p>
                                <div style="display:flex;gap:.5rem;">
                                    <button type="button" wire:click="enableEnforcement('{{ $a->id }}')" style="background:#c0392b;color:#fff;border:none;border-radius:6px;padding:.35rem .9rem;font-size:.82rem;cursor:pointer;">{{ __('Yes, enable enforcement') }}</button>
                                    <button type="button" wire:click="cancelEnableEnforcement" style="background:none;border:1px solid #dfe3ea;color:#5A6B82;border-radius:6px;padding:.35rem .9rem;font-size:.82rem;cursor:pointer;">{{ __('Cancel') }}</button>
                                </div>
                            </div>
                        @endif

                        @if ($confirmingDisableId === $a->id)
                            <div style="margin-bottom:.8rem;padding:.8rem;background:#fff8e1;border-radius:8px;">
                                <p style="color:#0B2545;font-weight:600;font-size:.88rem;margin:0 0 .3rem;">{{ __('Disable enforcement for :policy?', ['policy' => $a->policy_name]) }}</p>
                                @if ($a->policy_reversibility === 'full')
                                    <p style="color:#5A6B82;font-size:.83rem;margin:0 0 .6rem;">{{ __('Any device where this policy made a change will be reverted. Devices already compliant beforehand are not affected. The assignment returns to audit mode.') }}</p>
                                @else
                                    {{-- REAL FIX 2026-08-12: this branch exists because "will be
                                         reverted" is actively FALSE for a policy declared
                                         reversibility=none (e.g. OS Update Enforcement -- an
                                         installed system update genuinely cannot be undone by
                                         this platform). The honest text below applies to both
                                         'none' and 'partial', since neither promises a full,
                                         clean revert the way 'full' does. --}}
                                    <p style="color:#8a6d3b;font-size:.83rem;margin:0 0 .6rem;font-weight:600;">{{ __('This cannot be undone.') }}</p>
                                    <p style="color:#5A6B82;font-size:.83rem;margin:0 0 .6rem;">{{ __('Any changes this policy already made to a device will remain in place. Disabling only stops it from acting on any device going forward. The assignment returns to audit mode.') }}</p>
                                @endif
                                <div style="display:flex;gap:.5rem;">
                                    <button type="button" wire:click="disableEnforcement('{{ $a->id }}')" style="background:#5A6B82;color:#fff;border:none;border-radius:6px;padding:.35rem .9rem;font-size:.82rem;cursor:pointer;">
                                        {{ $a->policy_reversibility === 'full' ? __('Yes, disable and revert') : __('Yes, disable enforcement') }}
                                    </button>
                                    <button type="button" wire:click="cancelDisableEnforcement" style="background:none;border:1px solid #dfe3ea;color:#5A6B82;border-radius:6px;padding:.35rem .9rem;font-size:.82rem;cursor:pointer;">{{ __('Cancel') }}</button>
                                </div>
                            </div>
                        @endif

                        @if ($runs->isEmpty())
                            <p style="color:#9aa;font-size:.83rem;">{{ __('No check results yet.') }}</p>
                        @else
                            <p style="color:#9aa;font-size:.76rem;margin:0 0 .4rem;">{{ __(':s succeeded, :f failed, :p running', ['s' => $success, 'f' => $failed, 'p' => $pending]) }}</p>
                            <div style="max-height:280px;overflow-y:auto;">
                                @php
                                    $excludedHostIds = $exclusionsByAssignment[$a->id] ?? [];
                                @endphp
                                @foreach ($runs->sortBy('hostname') as $r)
                                    @php $isExcluded = in_array((int) $r->fleet_host_id, $excludedHostIds, true); @endphp
                                    <div style="display:flex;justify-content:space-between;align-items:center;padding:.3rem 0;border-top:1px solid #f8f9fb;font-size:.83rem;{{ $isExcluded ? 'opacity:.55;' : '' }}">
                                        <span style="color:#0B2545;">
                                            {{ $r->hostname }}
                                            @if ($isExcluded)
                                                <span style="color:#9aa;font-size:.7rem;font-style:italic;">{{ __('(excluded)') }}</span>
                                            @endif
                                        </span>
                                        <span style="display:flex;align-items:center;gap:.4rem;">
                                            @if ($r->status === 'success')
                                                <span style="color:{{ $r->result === 'firewall_disabled' ? '#c0392b' : '#1e824c' }};">{{ $r->result ?? __('(no result)') }}</span>
                                                @if ($r->phase !== 'check')
                                                    <span style="color:#9aa;font-size:.7rem;">({{ __($r->phase) }})</span>
                                                @endif
                                            @elseif ($r->status === 'failed')
                                                <span style="color:#c0392b;" title="{{ $r->output }}">{{ __('Failed') }}</span>
                                            @else
                                                <span style="color:#9aa;">{{ __('Running...') }}</span>
                                            @endif
                                            @if ($isExcluded)
                                                <button type="button" wire:click="includeDevice('{{ $a->id }}', {{ $r->fleet_host_id }})"
                                                    style="background:none;border:1px solid #dfe3ea;color:#5A6B82;border-radius:5px;padding:.1rem .4rem;font-size:.7rem;cursor:pointer;">{{ __('Include') }}</button>
                                            @else
                                                <button type="button" wire:click="excludeDevice('{{ $a->id }}', {{ $r->fleet_host_id }}, '{{ $r->hostname }}')"
                                                    style="background:none;border:1px solid #dfe3ea;color:#5A6B82;border-radius:5px;padding:.1rem .4rem;font-size:.7rem;cursor:pointer;">{{ __('Exclude') }}</button>
                                            @endif
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    @endif
</div>
