<div wire:poll.5s>
    <h1 style="color:#0B2545;">{{ __('Assets') }}</h1>
    <p style="color:#5A6B82;">{{ __('The devices enrolled in your organization, discovered automatically.') }}</p>

    @if (!$fleetSecret)
        <div style="background:#fff3cd;padding:1rem;border-radius:8px;">{{ __('Device monitoring is still being set up. Please check back shortly.') }}</div>
    @endif

    {{-- HONEST ERROR: an outage is NOT the same as "no devices" --}}
    @if ($fleetError)
        <div style="background:#fff8e1;border-left:4px solid #b8860b;padding:1rem;border-radius:8px;margin:1rem 0;color:#5A6B82;">
            {{ $fleetError }}
        </div>
    @endif

    @if ($removalNotice)
        <div style="background:#e8f5e9;border-left:4px solid #1e824c;padding:1rem;border-radius:8px;margin-bottom:1rem;color:#1e824c;">
            {{ $removalNotice }}
        </div>
    @endif
    @if ($removalError)
        <div style="background:#fdecea;border-left:4px solid #c0392b;padding:1rem;border-radius:8px;margin-bottom:1rem;color:#c0392b;">
            {{ $removalError }}
        </div>
    @endif
    @if ($roleMessage)
        <div style="background:#e8f5e9;border-left:4px solid #1e824c;padding:1rem;border-radius:8px;margin-bottom:1rem;color:#1e824c;">
            {{ $roleMessage }}
        </div>
    @endif
    @if ($rolePrerequisite)
        <div style="background:#fff8e1;border-left:4px solid #b8860b;padding:1rem;border-radius:8px;margin-bottom:1rem;">
            <strong style="color:#8a6500;">{{ __('Before this monitoring shows real data, run this on the device:') }}</strong>
            <pre style="background:#2d2d2d;color:#e0e0e0;padding:.8rem;border-radius:6px;margin-top:.5rem;overflow-x:auto;font-size:.82rem;white-space:pre-wrap;">{{ $rolePrerequisite }}</pre>
        </div>
    @endif

    @if ($credentialFormRoleGroup)
        <div style="background:#fff;border:2px solid #17A2B8;border-radius:12px;padding:1.5rem;margin-bottom:1rem;">
            <strong style="color:#0B2545;">
                @if ($editingCredentialOnly)
                    {{ __('Update stored credential for :role', ['role' => $tier2Roles[$credentialFormRoleGroup] ?? $credentialFormRoleGroup]) }}
                @else
                    {{ __('Database credential needed for :host', ['host' => $credentialFormHostname]) }}
                @endif
            </strong>
            <p style="color:#5A6B82;font-size:.9rem;margin:.4rem 0 1rem;">
                {{ __('This database requires a username and password to set up audit logging — stored securely and used only for this setup.') }}
            </p>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                <input type="text" wire:model="credentialUsername" placeholder="{{ __('Username (e.g. root)') }}"
                    style="padding:.5rem .7rem;border:1px solid #ddd;border-radius:6px;flex:1;min-width:150px;">
                <input type="password" wire:model="credentialPassword" placeholder="{{ __('Password') }}"
                    style="padding:.5rem .7rem;border:1px solid #ddd;border-radius:6px;flex:1;min-width:150px;">
            </div>
            <div style="margin-top:.8rem;">
                <button wire:click="saveRoleCredential"
                    style="padding:.4rem .9rem;border:1px solid #17A2B8;background:#17A2B8;color:#fff;border-radius:6px;cursor:pointer;">
                    {{ __('Save and continue') }}
                </button>
                <button wire:click="cancelCredentialForm"
                    style="padding:.4rem .9rem;border:1px solid #ddd;background:#fff;color:#5A6B82;border-radius:6px;cursor:pointer;margin-left:.4rem;">
                    {{ __('Cancel') }}
                </button>
            </div>
        </div>
    @endif
    @if ($storedCredentials->count())
        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:1rem;">
            <h3 style="margin-top:0;">{{ __('Stored database credentials') }}</h3>
            <p style="color:#5A6B82;font-size:.85rem;">{{ __('Used automatically when setting up database roles on Windows devices. Each device has its own, separate credential — never shared with any other device, even for the same database engine. If a credential was entered incorrectly, update it here.') }}</p>
            <p style="color:#9aa;font-size:.8rem;">{{ __('Linux devices do not appear here: they authenticate automatically using a secure, local method that never needs a stored username or password.') }}</p>
            <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
                <thead><tr style="text-align:left;color:#5A6B82;">
                    <th style="padding:.4rem 0;">{{ __('Role') }}</th>
                    <th style="padding:.4rem 0;">{{ __('Device') }}</th>
                    <th style="padding:.4rem 0;">{{ __('Username') }}</th>
                    <th></th>
                </tr></thead>
                <tbody>
                @foreach ($storedCredentials as $i => $cred)
                    <tr wire:key="cred-{{ $i }}" style="border-top:1px solid #f2f2f2;">
                        <td style="padding:.4rem 0;">{{ $tier2Roles[$cred->role_group] ?? $cred->role_group }}</td>
                        <td style="padding:.4rem 0;color:#5A6B82;">{{ $cred->device_label }}</td>
                        <td style="padding:.4rem 0;color:#5A6B82;">{{ $cred->username }}</td>
                        <td style="padding:.4rem 0;text-align:right;">
                            <button wire:click="editRoleCredential('{{ $cred->role_group }}', {{ $cred->fleet_host_id ?? 'null' }}, '{{ $cred->device_label }}')"
                                style="padding:.3rem .7rem;border:1px solid #17A2B8;background:#fff;color:#17A2B8;border-radius:6px;cursor:pointer;font-size:.82rem;">
                                {{ __('Update') }}
                            </button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Enrolled devices') }}</h3>

        {{-- ============ FILTERS ============ --}}
        <div style="display:flex;gap:.8rem;margin:1rem 0 .5rem;flex-wrap:wrap;align-items:center;">
            <input type="text" wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search by hostname, OS, or IP...') }}"
                style="flex:1;min-width:220px;padding:.5rem;border:1px solid #ddd;border-radius:6px;">

            <select wire:model.live="platformFilter" style="padding:.5rem;border:1px solid #ddd;border-radius:6px;">
                <option value="">{{ __('All platforms') }}</option>
                <option value="darwin">{{ __('macOS') }}</option>
                <option value="windows">{{ __('Windows') }}</option>
                <option value="ubuntu">{{ __('Linux') }}</option>
                <option value="chrome">{{ __('ChromeOS') }}</option>
            </select>

            <select wire:model.live="seenFilter" style="padding:.5rem;border:1px solid #ddd;border-radius:6px;">
                <option value="">{{ __('All devices') }}</option>
                <option value="recent">{{ __('Seen in last 30 days') }}</option>
                <option value="stale">{{ __('Not seen in 30+ days') }}</option>
            </select>

            @if ($filtered)
                <button wire:click="clearFilters"
                    style="padding:.5rem 1rem;border:1px solid #ddd;border-radius:6px;background:#fff;color:#0B2545;cursor:pointer;">
                    {{ __('Clear filters') }}
                </button>
            @endif
        </div>

        @if ($filtered)
            <p style="color:#5A6B82;font-size:.88rem;">
                {{ __('Showing') }} <strong>{{ $showing }}</strong> {{ __('of') }} {{ $totalEnrolled }} {{ __('enrolled devices.') }}
            </p>
        @endif

        {{-- A device that stopped reporting is a security gap, not just a stale row. --}}
        @if ($seenFilter === 'stale' && $showing > 0)
            <div style="background:#fdecea;border-left:3px solid #c0392b;padding:.7rem .9rem;border-radius:6px;font-size:.88rem;color:#5A6B82;margin-bottom:1rem;">
                {{ __('These devices have not reported in over 30 days. A device that stops reporting may be switched off, lost, stolen, or compromised — each one is worth checking.') }}
            </div>
        @endif

        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="text-align:left;color:#5A6B82;border-bottom:1px solid #eee;">
                    <th style="padding:.5rem;">{{ __('Device') }}</th>
                    <th style="padding:.5rem;">{{ __('Operating system') }}</th>
                    <th style="padding:.5rem;">{{ __('IP address') }}</th>
                    <th style="padding:.5rem;">{{ __('VPN address') }}</th>
                    <th style="padding:.5rem;">{{ __('Last seen') }}</th>
                    <th style="padding:.5rem;">{{ __('Status') }}</th>
                    <th style="padding:.5rem;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($hosts as $h)
                    <tr style="border-bottom:1px solid #f2f2f2;">
                        <td style="padding:.5rem;">{{ $h['hostname'] }}</td>
                        <td style="padding:.5rem;">{{ $h['os'] }}</td>
                        <td style="padding:.5rem;">{{ $h['ips'] ? implode(', ', $h['ips']) : __('Not yet detected') }}</td>
                        <td style="padding:.5rem;">
                            @if ($h['vpn_ip'])
                                <span style="font-family:monospace;color:#0B2545;" title="{{ __('Connected to your organization\'s private network — useful for troubleshooting.') }}">{{ $h['vpn_ip'] }}</span>
                            @else
                                <span style="color:#9aa;font-size:.85rem;">{{ __('not connected') }}</span>
                            @endif
                        </td>
                        <td style="padding:.5rem;">
                            @php
                                $seen = $h['last_seen'];
                                $isStale = $seen === null || $seen < now()->subDays(30)->toDateString();
                            @endphp
                            <span style="color:{{ $isStale ? '#c0392b' : '#5A6B82' }};">
                                {{ $seen ?? __('never') }}
                            </span>
                        </td>
                        <td style="padding:.5rem;">
                            <span style="color:{{ $h['status'] === 'online' ? '#1e824c' : '#5A6B82' }};">{{ __($h['status']) }}</span>
                        </td>
                        <td style="padding:.5rem;text-align:right;">
                            @if ($confirmingRemovalId === $h['id'])
                                <span style="font-size:.85rem;color:#5A6B82;margin-right:.4rem;">{{ __('Remove from monitoring? If this computer is still on, uninstall the agent from it directly or it may reappear.') }}</span>
                                <button wire:click="removeDevice({{ $h['id'] }})"
                                    style="padding:.35rem .7rem;border:1px solid #c0392b;border-radius:6px;background:#c0392b;color:#fff;cursor:pointer;font-size:.85rem;">
                                    {{ __('Yes, remove') }}
                                </button>
                                <button wire:click="cancelRemoval"
                                    style="padding:.35rem .7rem;border:1px solid #ddd;border-radius:6px;background:#fff;color:#5A6B82;cursor:pointer;font-size:.85rem;margin-left:.3rem;">
                                    {{ __('Cancel') }}
                                </button>
                            @else
                                @if ($confirmingRoleHostId == $h['id'])
                                    <span style="font-size:.8rem;color:#8a6500;margin-right:.4rem;">
                                        {{ __('Add :role? :detail', ['role' => $tier2Roles[$confirmingRoleGroup] ?? $confirmingRoleGroup, 'detail' => $roleConfirmText[$confirmingRoleGroup] ?? '']) }}
                                    </span>
                                    <button wire:click="addRole({{ $h['id'] }}, '{{ addslashes($h['hostname']) }}', '{{ $confirmingRoleGroup }}', '{{ $confirmingRolePlatform }}')"
                                        style="padding:.3rem .6rem;border:1px solid #1e824c;background:#1e824c;color:#fff;border-radius:6px;cursor:pointer;font-size:.8rem;margin-right:.3rem;">
                                        {{ __('Yes') }}
                                    </button>
                                    <button wire:click="cancelAddRole"
                                        style="padding:.3rem .6rem;border:1px solid #ddd;background:#fff;color:#5A6B82;border-radius:6px;cursor:pointer;font-size:.8rem;margin-right:.4rem;">
                                        {{ __('Cancel') }}
                                    </button>
                                @else
                                    @foreach ($tier2Roles as $roleKey => $roleLabel)
                                        @php $run = $roleRuns->get($h['hostname'] . '|' . $roleKey); @endphp
                                        @if ($run && $run->status === 'running')
                                            <span style="font-size:.75rem;color:#b8860b;margin-right:.4rem;" title="{{ $roleLabel }}">
                                                ⏳ {{ __('Setting up :role…', ['role' => $roleLabel]) }}
                                            </span>
                                        @elseif ($run && $run->status === 'failed')
                                            <span style="font-size:.75rem;color:#c0392b;margin-right:.4rem;cursor:help;" title="{{ $run->output }}">
                                                ✗ {{ __(':role setup failed', ['role' => $roleLabel]) }}
                                            </span>
                                        @elseif ($run && $run->status === 'success' && $run->finished_at && \Carbon\Carbon::parse($run->finished_at)->diffInMinutes(now()) < 5)
                                            <span style="font-size:.75rem;color:#1e824c;margin-right:.4rem;">
                                                ✓ {{ __(':role setup complete', ['role' => $roleLabel]) }}
                                            </span>
                                        @endif
                                    @endforeach
                                    <select onchange="if(this.value){ @this.call('confirmAddRole', {{ $h['id'] }}, '{{ addslashes($h['hostname']) }}', this.value, '{{ $h['platform'] }}'); this.value=''; }"
                                        style="padding:.35rem .5rem;border:1px solid #ddd;border-radius:6px;background:#fff;color:#0B2545;cursor:pointer;font-size:.8rem;margin-right:.4rem;">
                                        <option value="">{{ __('+ Add role') }}</option>
                                        @foreach ($tier2Roles as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <button wire:click="confirmRemoval({{ $h['id'] }}, '{{ addslashes($h['hostname']) }}')"
                                    style="padding:.35rem .7rem;border:1px solid #ddd;border-radius:6px;background:#fff;color:#c0392b;cursor:pointer;font-size:.85rem;">
                                    {{ __('Remove') }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="padding:1rem;color:#9aa;">
                            @if ($filtered)
                                {{ __('No devices match your filters.') }}
                            @elseif ($fleetError)
                                {{ __('The device list could not be loaded.') }}
                            @else
                                {{ __('No devices are enrolled yet. Install the monitoring agent on your computers to see them here.') }}
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{-- Pagination --}}
        <div style="margin-top:1rem;">
            {{ $hosts->links() }}
        </div>
    </div>
</div>