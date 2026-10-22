<div>
    <h1 style="color:#0B2545;">{{ __('People and approvals') }}</h1>
    <p style="color:#5A6B82;">{{ __('Who writes, checks and signs off each of your security documents. Auditors look for this.') }}</p>
 
    @if (session('gov_ok'))
        <div style="background:#e6ffe6;padding:.8rem;border-radius:6px;margin-bottom:1rem;">{{ session('gov_ok') }}</div>
    @endif
 
    @php $canEdit = \App\Support\Perm::can('manage_team'); @endphp
 
    {{-- People register --}}
    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('People in your organisation') }}</h3>
        <p style="color:#5A6B82;font-size:.9rem;">{{ __('These are the people who sign your documents. They do not need a SecX login — most will never use the platform.') }}</p>
 
        @if ($people->isEmpty())
            <p style="color:#5A6B82;">{{ __('No one added yet. Add the people who write, check and approve your security documents.') }}</p>
        @else
            <table style="width:100%;border-collapse:collapse;margin-bottom:1rem;">
                <thead><tr style="text-align:left;color:#5A6B82;border-bottom:1px solid #eee;">
                    <th style="padding:.5rem;">{{ __('Name') }}</th>
                    <th style="padding:.5rem;">{{ __('Job title') }}</th>
                    <th style="padding:.5rem;">{{ __('Email') }}</th>
                    <th style="padding:.5rem;"></th>
                </tr></thead>
                <tbody>
                    @foreach ($people as $p)
                        <tr style="border-bottom:1px solid #f2f2f2;">
                            <td style="padding:.5rem;">{{ $p->name }}</td>
                            <td style="padding:.5rem;">{{ $p->title }}</td>
                            <td style="padding:.5rem;">{{ $p->email ?? '—' }}</td>
                            <td style="padding:.5rem;">
                                @if ($canEdit)
                                    <button wire:click="removePerson('{{ $p->id }}')"
                                            wire:confirm="{{ __('Remove this person?') }}"
                                            style="background:none;border:none;color:#c0392b;cursor:pointer;">{{ __('Remove') }}</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
 
        @if ($canEdit)
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:.6rem;align-items:end;">
                <label>{{ __('Name') }}<input wire:model="pname" style="width:100%;padding:.4rem;"></label>
                <label>{{ __('Job title') }}<input wire:model="ptitle" placeholder="{{ __('e.g. Managing Director') }}" style="width:100%;padding:.4rem;"></label>
                <label>{{ __('Email (optional)') }}<input wire:model="pemail" style="width:100%;padding:.4rem;"></label>
                <button wire:click="addPerson" style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.45rem 1rem;">{{ __('Add') }}</button>
            </div>
            @error('pname') <small style="color:#c0392b;">{{ $message }}</small> @enderror
            @error('ptitle') <small style="color:#c0392b;">{{ $message }}</small> @enderror
        @endif
    </div>
 
    {{-- Approval matrix --}}
    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Who signs each document') }}</h3>
        <p style="color:#5A6B82;font-size:.9rem;">
            {{ __('Auditors look for three things: who wrote it, who checked it, and who had the authority to approve it. In a small organisation the same person may do more than one — that is fine.') }}
        </p>
 
        @foreach ($types as $key => $t)
            <div style="border-top:1px solid #f2f2f2;padding:1rem 0;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
                    <div>
                        <strong style="color:#0B2545;">{{ __($t['label']) }}</strong>
                        <p style="color:#5A6B82;font-size:.85rem;margin:.2rem 0;">{{ __($t['purpose']) }}</p>
                    </div>
                    @if ($canEdit)
                        <button wire:click="editApprovals('{{ $key }}')"
                            style="background:none;border:1px solid #17A2B8;color:#17A2B8;border-radius:8px;padding:.3rem .8rem;white-space:nowrap;cursor:pointer;">
                            {{ __('Set signatories') }}
                        </button>
                    @endif
                </div>
 
                @php $current = $approvals[$key] ?? collect(); @endphp
                @if ($current->isEmpty())
                    <p style="color:#b8860b;font-size:.85rem;margin:.3rem 0 0;">{{ __('No signatories set yet.') }}</p>
                @else
                    <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-top:.4rem;">
                        @foreach ($roles as $role => $roleLabel)
                            @php $forRole = $current->where('role', $role); @endphp
                            <div style="font-size:.85rem;">
                                <span style="color:#5A6B82;">{{ __($roleLabel) }}:</span>
                                @if ($forRole->isEmpty())
                                    <span style="color:#b8860b;">{{ __('not set') }}</span>
                                @else
                                    @foreach ($forRole as $a)
                                        <span style="color:#0B2545;">{{ $a->name }} ({{ $a->title }}){{ !$loop->last ? ',' : '' }}</span>
                                    @endforeach
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
 
                {{-- Editor --}}
                @if ($editingType === $key)
                    <div style="background:#f8f9fb;border-radius:10px;padding:1rem;margin-top:.8rem;">
                        @if ($people->isEmpty())
                            <p style="color:#c0392b;">{{ __('Add people above first, then come back to set signatories.') }}</p>
                        @else
                            <p style="color:#5A6B82;font-size:.85rem;margin-top:0;">
                                <strong>{{ __('Commonly:') }}</strong>
                                {{ __($t['common']['prepared_by']) }} {{ __('prepares it') }};
                                {{ __($t['common']['reviewed_by']) }} {{ __('reviews it') }};
                                {{ __($t['common']['approved_by']) }} {{ __('approves it') }}.
                                <em>{{ __($t['why']) }}</em>
                                <br><span style="color:#9aa;">{{ __('This is common practice, not a rule. Your organisation decides.') }}</span>
                            </p>
 
                            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;">
                                @foreach ($roles as $role => $roleLabel)
                                    <div>
                                        <strong style="font-size:.9rem;color:#0B2545;">{{ __($roleLabel) }}</strong>
                                        @foreach ($people as $p)
                                            <label style="display:block;font-size:.85rem;color:#5A6B82;margin-top:.2rem;">
                                                <input type="checkbox" wire:model="selected.{{ $role }}" value="{{ $p->id }}">
                                                {{ $p->name }} <span style="color:#9aa;">({{ $p->title }})</span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
 
                            <button wire:click="saveApprovals" style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.45rem 1.1rem;margin-top:.8rem;">{{ __('Save signatories') }}</button>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
