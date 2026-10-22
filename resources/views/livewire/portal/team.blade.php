<div>
    <h1 style="color:#0B2545;">{{ __('Team') }}</h1>
    @if (session('team_ok')) <div style="background:#e6ffe6;padding:.8rem;border-radius:6px;">{{ session('team_ok') }}</div> @endif
 
    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin:1rem 0;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3>{{ __('Invite a team member') }}</h3>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <input wire:model="inviteFirst" placeholder="{{ __('First name') }}" style="padding:.5rem;">
            <input wire:model="inviteLast" placeholder="{{ __('Last name') }}" style="padding:.5rem;">
            <input wire:model="inviteEmail" placeholder="{{ __('Email') }}" style="padding:.5rem;">
            <select wire:model="inviteRole" style="padding:.5rem;">
                <option value="analyst">{{ __('Analyst') }}</option>
                <option value="viewer">{{ __('Viewer') }}</option>
                <option value="platform-admin">{{ __('Admin') }}</option>
            </select>
            <button wire:click="invite" style="background:#0B2545;color:#fff;padding:.5rem 1rem;border:none;border-radius:8px;">{{ __('Send invite') }}</button>
        </div>
        @error('inviteEmail') <small style="color:#c0392b;">{{ $message }}</small> @enderror
    </div>
 
    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3>{{ __('Members') }}</h3>
        @foreach ($members as $m)
            <div style="display:flex;justify-content:space-between;align-items:center;padding:.5rem 0;border-bottom:1px solid #eee;">
                <div>{{ $m->first_name }} {{ $m->last_name }} — {{ $m->email }} <em style="color:#17A2B8;">({{ $m->role }})</em></div>
                @if ($m->email !== session('auth_email'))
                    <button wire:click="remove('{{ $m->id }}')" style="background:#F97068;color:#fff;border:none;border-radius:6px;padding:.3rem .7rem;">{{ __('Remove') }}</button>
                @endif
            </div>
        @endforeach
    </div>
</div>
