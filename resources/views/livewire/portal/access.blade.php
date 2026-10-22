<div>
    <h1 style="color:#0B2545;">{{ __('Access review') }}</h1>
    <p style="color:#5A6B82;">{{ __('Who can access your workspace, and what they can do. Review this regularly — auditors ask for it.') }}</p>

    @if (!$groupId)
        <div style="background:#fff3cd;padding:1rem;border-radius:8px;">{{ __('Your workspace is still being set up. Please check back shortly.') }}</div>
    @endif

    @if (session('acc_ok'))
        <div style="background:#e6ffe6;padding:.8rem;border-radius:6px;margin-bottom:1rem;">{{ session('acc_ok') }}</div>
    @endif

    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('People with access') }} ({{ count($users) }})</h3>
        @if (empty($users))
            <p style="color:#5A6B82;">{{ __('No users found.') }}</p>
        @else
            <table style="width:100%;border-collapse:collapse;">
                <thead><tr style="text-align:left;color:#5A6B82;border-bottom:1px solid #eee;">
                    <th style="padding:.5rem;">{{ __('Name') }}</th>
                    <th style="padding:.5rem;">{{ __('Email') }}</th>
                    <th style="padding:.5rem;">{{ __('Role') }}</th>
                    <th style="padding:.5rem;">{{ __('Status') }}</th>
                    <th style="padding:.5rem;">{{ __('Added') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($users as $u)
                        <tr style="border-bottom:1px solid #f2f2f2;">
                            <td style="padding:.5rem;">{{ $u['name'] }}</td>
                            <td style="padding:.5rem;">{{ $u['email'] }}</td>
                            <td style="padding:.5rem;">
                                @forelse ($u['roles'] as $r)
                                    <span style="background:#e0f7fa;color:#17A2B8;border-radius:4px;padding:.1rem .4rem;font-size:.8rem;margin-right:.2rem;">{{ __($r) }}</span>
                                @empty
                                    <span style="color:#c0392b;" title="{{ __('This account can sign in but has no role assigned.') }}">{{ __('No role') }}</span>
                                @endforelse
                            </td>
                            <td style="padding:.5rem;">
                                <span style="color:{{ $u['enabled'] ? '#1e824c' : '#c0392b' }};">{{ $u['enabled'] ? __('Active') : __('Disabled') }}</span>
                            </td>
                            <td style="padding:.5rem;">{{ $u['created'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <p style="color:#5A6B82;font-size:.9rem;margin-top:1rem;">
                {{ __('Tip: remove access for people who have left, and check that each person has only the permissions they need. Manage users on the Team page.') }}
            </p>
        @endif
    </div>

    {{-- AD/LDAP directory connection (admins only) --}}
    @if (\App\Support\Perm::can('manage_team'))
    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-top:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Connect your company directory') }}</h3>
        <p style="color:#5A6B82;font-size:.9rem;">
            {{ __('Connect your Active Directory so your staff can sign in with their existing work accounts. We only read from your directory — we never change it.') }}
        </p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem;max-width:820px;">
            <label>{{ __('Directory address') }}
                <input wire:model="connection_url" placeholder="ldaps://dc.company.com:636" style="width:100%;padding:.4rem;">
            </label>
            <label>{{ __('Service account (Bind DN)') }}
                <input wire:model="bind_dn" placeholder="CN=svc,OU=Users,DC=company,DC=com" style="width:100%;padding:.4rem;">
            </label>
            <label>{{ __('Service account password') }}
                <input type="password" wire:model="bind_credential" style="width:100%;padding:.4rem;">
            </label>
            <label>{{ __('Where your users are (Users DN)') }}
                <input wire:model="users_dn" placeholder="OU=Users,DC=company,DC=com" style="width:100%;padding:.4rem;">
            </label>
            <label>{{ __('Directory type') }}
                <select wire:model="vendor" style="width:100%;padding:.4rem;">
                    <option value="ad">{{ __('Active Directory') }}</option>
                    <option value="other">{{ __('Other LDAP') }}</option>
                </select>
            </label>
            <label>{{ __('Username attribute') }}
                <input wire:model="username_attr" placeholder="sAMAccountName" style="width:100%;padding:.4rem;">
            </label>
        </div>

        <button wire:click="connectDirectory" style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;margin-top:.8rem;">
            {{ __('Connect directory') }}
        </button>

        @error('connection_url') <small style="color:#c0392b;display:block;">{{ $message }}</small> @enderror
        @error('bind_dn') <small style="color:#c0392b;display:block;">{{ $message }}</small> @enderror
        @error('bind_credential') <small style="color:#c0392b;display:block;">{{ $message }}</small> @enderror
        @error('users_dn') <small style="color:#c0392b;display:block;">{{ $message }}</small> @enderror
    </div>
    @endif
</div>