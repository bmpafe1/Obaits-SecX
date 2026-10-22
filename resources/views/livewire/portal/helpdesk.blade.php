<div>
    <h1 style="color:#0B2545;">{{ __('Helpdesk') }}</h1>
    <p style="color:#5A6B82;">{{ __('Report a problem, ask for something, or record a security issue.') }}</p>

    @if (session('helpdesk_ok'))
        <div style="background:#e6ffe6;padding:.8rem;border-radius:6px;margin-bottom:1rem;">{{ session('helpdesk_ok') }}</div>
    @endif

    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin:1rem 0;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Raise a ticket') }}</h3>

        <div style="display:flex;flex-direction:column;gap:.6rem;max-width:640px;">
            <input wire:model="subject" placeholder="{{ __('Subject') }}"
                   style="padding:.6rem;border:1px solid #ddd;border-radius:6px;">
            @error('subject') <small style="color:#c0392b;">{{ $message }}</small> @enderror

            <textarea wire:model="body" placeholder="{{ __('Describe the problem') }}" rows="4"
                      style="padding:.6rem;border:1px solid #ddd;border-radius:6px;"></textarea>
            @error('body') <small style="color:#c0392b;">{{ $message }}</small> @enderror

            <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                {{-- CATEGORY: routes the ticket, and makes it reportable later --}}
                <label style="display:flex;flex-direction:column;gap:.2rem;">
                    <span style="color:#5A6B82;font-size:.85rem;">{{ __('What kind of issue?') }}</span>
                    <select wire:model="category" style="padding:.5rem;border:1px solid #ddd;border-radius:6px;min-width:200px;">
                        <option value="hardware">{{ __('Hardware — a device or equipment') }}</option>
                        <option value="software">{{ __('Software — an application or system') }}</option>
                        <option value="access">{{ __('Access — passwords, permissions, accounts') }}</option>
                        <option value="network">{{ __('Network — internet or connectivity') }}</option>
                        <option value="security">{{ __('Security — something suspicious happened') }}</option>
                        <option value="other">{{ __('Something else') }}</option>
                    </select>
                </label>

                <label style="display:flex;flex-direction:column;gap:.2rem;">
                    <span style="color:#5A6B82;font-size:.85rem;">{{ __('How urgent?') }}</span>
                    <select wire:model="priority" style="padding:.5rem;border:1px solid #ddd;border-radius:6px;min-width:160px;">
                        <option value="low">{{ __('Low') }}</option>
                        <option value="normal">{{ __('Normal') }}</option>
                        <option value="high">{{ __('High') }}</option>
                        <option value="urgent">{{ __('Urgent') }}</option>
                    </select>
                </label>
            </div>

            {{-- A gentle steer, not a rule. The person reporting decides. --}}
            @if ($category === 'security')
                <div style="background:#fff8e1;border-left:3px solid #b8860b;padding:.7rem;border-radius:6px;font-size:.88rem;color:#5A6B82;">
                    {{ __('If something may have been broken into, accessed without permission, or lost — say so here. Someone will look at whether it needs to be recorded as a security incident.') }}
                </div>
            @endif

            <div>
                <button wire:click="raise"
                        style="background:#0B2545;color:#fff;padding:.55rem 1.2rem;border:none;border-radius:8px;cursor:pointer;">
                    {{ __('Raise ticket') }}
                </button>
            </div>
        </div>
    </div>

    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Tickets') }}</h3>

        {{-- ============ FILTERS ============ --}}
        <div style="display:flex;gap:.8rem;margin:1rem 0 .5rem;flex-wrap:wrap;align-items:center;">
            <input type="text" wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search by subject or ticket number...') }}"
                style="flex:1;min-width:220px;padding:.5rem;border:1px solid #ddd;border-radius:6px;">

            <select wire:model.live="statusFilter" style="padding:.5rem;border:1px solid #ddd;border-radius:6px;">
                <option value="">{{ __('All statuses') }}</option>
                <option value="open">{{ __('Open') }}</option>
                <option value="in_progress">{{ __('In progress') }}</option>
                <option value="resolved">{{ __('Resolved') }}</option>
                <option value="closed">{{ __('Closed') }}</option>
            </select>

            <label style="display:flex;align-items:center;gap:.4rem;color:#5A6B82;font-size:.9rem;
                          padding:.45rem .8rem;border:1px solid {{ $incidentsOnly ? '#c0392b' : '#ddd' }};border-radius:6px;
                          background:{{ $incidentsOnly ? '#fdecea' : '#fff' }};cursor:pointer;">
                <input type="checkbox" wire:model.live="incidentsOnly">
                {{ __('Security incidents only') }}
            </label>

            <input type="date" wire:model.live="dateFrom" style="padding:.5rem;border:1px solid #ddd;border-radius:6px;">
            <span style="align-self:center;color:#5A6B82;">{{ __('to') }}</span>
            <input type="date" wire:model.live="dateTo" style="padding:.5rem;border:1px solid #ddd;border-radius:6px;">
        </div>

        {{-- QUICK RANGES --}}
        <div style="display:flex;gap:.4rem;margin-bottom:1rem;flex-wrap:wrap;">
            @foreach (['today'=>__('Today'),'7'=>__('Last 7 days'),'30'=>__('Last 30 days'),'all'=>__('All')] as $r => $label)
                <button wire:click="quickRange('{{ $r }}')"
                    style="padding:.35rem .8rem;border:1px solid #ddd;border-radius:6px;background:#fff;color:#0B2545;cursor:pointer;font-size:.85rem;">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if ($incidentsOnly)
            <div style="background:#fff8e1;border-left:3px solid #b8860b;padding:.7rem .9rem;border-radius:6px;font-size:.88rem;color:#5A6B82;margin-bottom:1rem;">
                {{ __('Showing security incidents only. Dates are when each incident was detected — this is your incident register.') }}
            </div>
        @endif

        @forelse ($tickets as $t)
            <div style="border-top:1px solid #f2f2f2;padding:.9rem 0;display:flex;justify-content:space-between;align-items:center;gap:1rem;">
                <div>
                    <a href="/portal/helpdesk/{{ $t->id }}" style="color:#0B2545;">
                        <strong>#{{ $t->number }} — {{ $t->subject }}</strong>
                    </a>
                    <div style="color:#5A6B82;font-size:.85rem;margin-top:.2rem;">
                        {{-- An incident shows its DETECTED date (the audit-relevant one); a ticket shows created. --}}
                        @if ($t->is_incident && $t->detected_at)
                            {{ substr((string) $t->detected_at, 0, 16) }}
                            <span style="color:#9aa;">({{ __('detected') }})</span>
                        @else
                            {{ substr((string) $t->created_at, 0, 16) }}
                        @endif
                        @if ($t->category) · {{ __(ucfirst($t->category)) }} @endif
                        · {{ __('Priority') }}: {{ __(ucfirst($t->priority)) }}
                    </div>
                </div>

                <div style="text-align:right;white-space:nowrap;">
                    {{-- An incident is a FLAG, not a status. It can be open, resolved or closed AND be an incident. --}}
                    @if ($t->is_incident)
                        <span style="background:#c0392b;color:#fff;padding:.25rem .5rem;border-radius:6px;font-size:.8rem;">{{ __('Incident') }}</span>
                    @endif

                    @if ($t->status === 'open')
                        <span style="background:#17A2B8;color:#fff;padding:.25rem .5rem;border-radius:6px;font-size:.8rem;">{{ __('Open') }}</span>
                    @elseif ($t->status === 'in_progress')
                        <span style="background:#b8860b;color:#fff;padding:.25rem .5rem;border-radius:6px;font-size:.8rem;">{{ __('In progress') }}</span>
                    @elseif ($t->status === 'resolved')
                        <span style="background:#1e824c;color:#fff;padding:.25rem .5rem;border-radius:6px;font-size:.8rem;">{{ __('Resolved') }}</span>
                    @else
                        <span style="background:#ddd;color:#333;padding:.25rem .5rem;border-radius:6px;font-size:.8rem;">{{ __('Closed') }}</span>
                    @endif
                </div>
            </div>
        @empty
            <p style="color:#9aa;padding:1rem 0;">{{ __('No tickets match your filters.') }}</p>
        @endforelse

        {{-- Pagination --}}
        <div style="margin-top:1rem;">
            {{ $tickets->links() }}
        </div>
    </div>
</div>