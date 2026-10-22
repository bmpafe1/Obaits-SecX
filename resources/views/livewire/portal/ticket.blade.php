<div>
    <p><a href="/portal/helpdesk" style="color:#17A2B8;">&larr; {{ __('Back to helpdesk') }}</a></p>

    <h1 style="color:#0B2545;">
        {{ $ticket->subject }}
        <small style="color:#5A6B82;font-size:.9rem;">#{{ $ticket->number }}</small>
    </h1>

    <div style="background:#fff;border-radius:12px;padding:1.2rem;margin:1rem 0;box-shadow:0 2px 8px rgba(0,0,0,.06);">

        {{-- An incident is a FLAG, not a status. Show it plainly when set. --}}
        @if ($ticket->is_incident)
            <div style="background:#fdecea;border-left:4px solid #c0392b;padding:.8rem;border-radius:6px;margin-bottom:1rem;">
                <strong style="color:#c0392b;">{{ __('Recorded as a security incident') }}</strong>
                <div style="color:#5A6B82;font-size:.87rem;margin-top:.2rem;">
                    {{ __('Severity') }}: {{ __(ucfirst($ticket->incident_severity ?? 'medium')) }}
                    @if ($ticket->detected_at)
                        · {{ __('Detected') }}: {{ substr($ticket->detected_at, 0, 16) }}
                    @endif
                </div>
            </div>
        @endif

        <p style="color:#5A6B82;white-space:pre-wrap;">{{ $ticket->body }}</p>

        <p style="color:#9aa;font-size:.85rem;">
            {{ __('Priority') }}: {{ __(ucfirst($ticket->priority)) }}
            @if ($ticket->category) · {{ __('Category') }}: {{ __(ucfirst($ticket->category)) }} @endif
            · {{ __('Status') }}: {{ __(ucfirst(str_replace('_', ' ', $ticket->status))) }}
        </p>

        {{-- The lesson, once recorded. This is A.5.27 evidence. --}}
        @if ($ticket->is_incident && $ticket->lessons_learned)
            <div style="background:#eef7f0;border-left:3px solid #1e824c;padding:.8rem;border-radius:6px;margin-top:.8rem;">
                @if ($ticket->root_cause)
                    <p style="margin:0 0 .4rem;"><strong>{{ __('Why it happened') }}:</strong> {{ $ticket->root_cause }}</p>
                @endif
                <p style="margin:0;"><strong>{{ __('What we changed') }}:</strong> {{ $ticket->lessons_learned }}</p>
            </div>
        @endif

        {{-- Suggest escalation for a security-category ticket that is not yet an incident.
             A prompt, never automatic — the compliance judgment belongs to whoever manages the team. --}}
        @if (!$ticket->is_incident && $ticket->category === 'security' && \App\Support\Perm::can('manage_team'))
            <div style="background:#fff8e1;border-left:3px solid #b8860b;padding:.8rem;border-radius:6px;margin-top:.8rem;color:#5A6B82;font-size:.9rem;">
                {{ __('This was reported as a security issue. If something was actually broken into, accessed without permission, or lost, record it as an incident so it goes in your incident register.') }}
            </div>
        @endif

        @if (\App\Support\Perm::can('manage_team'))
            <div style="margin-top:1rem;display:flex;gap:.5rem;flex-wrap:wrap;">
                @if (!$ticket->is_incident)
                    <button wire:click="escalate"
                        style="background:#c0392b;color:#fff;border:none;border-radius:6px;padding:.4rem .8rem;cursor:pointer;">
                        {{ __('Record as security incident') }}
                    </button>
                @endif

                @if (!in_array($ticket->status, ['resolved', 'closed']))
                    <button wire:click="startResolve"
                        style="background:#1e824c;color:#fff;border:none;border-radius:6px;padding:.4rem .8rem;cursor:pointer;">
                        {{ __('Resolve') }}
                    </button>
                @endif

                @if ($ticket->status !== 'closed')
                    <button wire:click="close"
                        style="background:#eee;color:#333;border:1px solid #ccc;border-radius:6px;padding:.4rem .8rem;cursor:pointer;">
                        {{ __('Close') }}
                    </button>
                @endif
            </div>
        @endif

        {{-- ATTACHMENTS --}}
        <div style="margin-top:1rem;border-top:1px solid #f2f2f2;padding-top:1rem;">
            <h4 style="margin:0 0 .5rem;color:#0B2545;">{{ __('Attachments') }}</h4>

            @if ($ticket->is_incident)
                <p style="color:#5A6B82;font-size:.83rem;margin:.2rem 0 .6rem;">
                    {{ __('Files attached to an incident are kept as evidence. Your auditor may ask to see them.') }}
                </p>
            @endif

            @forelse ($attachments as $a)
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.35rem 0;gap:1rem;">
                    <button wire:click="download('{{ $a->id }}')"
                        style="background:none;border:none;color:#17A2B8;cursor:pointer;text-align:left;padding:0;font-size:.92rem;">
                        &#128206; {{ $a->original_name }}
                        <span style="color:#9aa;font-size:.8rem;">({{ round(($a->size ?? 0)/1024) }} KB)</span>
                    </button>
                    @if ($a->is_evidence)
                        <span style="background:#eef7f0;color:#1e824c;font-size:.75rem;padding:.1rem .45rem;border-radius:4px;white-space:nowrap;">{{ __('Evidence') }}</span>
                    @endif
                </div>
            @empty
                <p style="color:#9aa;font-size:.9rem;margin:0;">{{ __('No files attached.') }}</p>
            @endforelse

            <div style="margin-top:.7rem;">
                <input type="file" wire:model="attachment" style="font-size:.9rem;">
                @error('attachment') <small style="color:#c0392b;display:block;">{{ $message }}</small> @enderror
                <button wire:click="uploadAttachment"
                    style="background:#0B2545;color:#fff;border:none;border-radius:6px;padding:.35rem .8rem;margin-top:.4rem;cursor:pointer;">
                    {{ __('Attach file') }}
                </button>
            </div>
        </div>
    </div>

    {{-- ESCALATION FORM --}}
    @if ($showEscalationForm)
    <div style="background:#fff;border-radius:12px;padding:1.2rem;margin:1rem 0;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Record as a security incident') }}</h3>
        <p style="color:#5A6B82;font-size:.9rem;">
            {{ __('This puts the event in your incident register, which your auditor will look at.') }}
        </p>

        <div style="margin-top:.6rem;">
            <label style="color:#5A6B82;font-size:.9rem;">{{ __('How serious?') }}</label>
            <select wire:model="incidentSeverity" style="display:block;margin-top:.3rem;padding:.4rem;width:220px;border:1px solid #ddd;border-radius:6px;">
                <option value="low">{{ __('Low') }}</option>
                <option value="medium">{{ __('Medium') }}</option>
                <option value="high">{{ __('High') }}</option>
                <option value="critical">{{ __('Critical') }}</option>
            </select>
            @error('incidentSeverity') <small style="color:#c0392b;">{{ $message }}</small> @enderror
        </div>

        <div style="margin-top:.6rem;">
            <label style="color:#5A6B82;font-size:.9rem;">{{ __('When did you first notice it?') }}</label>
            <input type="datetime-local" wire:model="detectedAt"
                style="display:block;margin-top:.3rem;padding:.4rem;border:1px solid #ddd;border-radius:6px;">
            <small style="color:#9aa;">{{ __('When it was NOTICED — not when you are typing this. The time between noticing and stopping it is what matters.') }}</small>
        </div>

        <div style="margin-top:.6rem;">
            <label style="color:#5A6B82;font-size:.9rem;">{{ __('What happened, in your words?') }}</label>
            <textarea wire:model="incidentDescription" rows="4" style="width:100%;margin-top:.3rem;padding:.5rem;border:1px solid #ddd;border-radius:6px;"></textarea>
            @error('incidentDescription') <small style="color:#c0392b;">{{ $message }}</small> @enderror
        </div>

        <div style="margin-top:.6rem;">
            <label style="color:#5A6B82;font-size:.9rem;">{{ __('What are you doing about it?') }}</label>
            <textarea wire:model="incidentResponse" rows="3" style="width:100%;margin-top:.3rem;padding:.5rem;border:1px solid #ddd;border-radius:6px;"></textarea>
            @error('incidentResponse') <small style="color:#c0392b;">{{ $message }}</small> @enderror
        </div>

        <div style="margin-top:.8rem;">
            <button wire:click="escalateToIncident" style="background:#c0392b;color:#fff;border:none;border-radius:6px;padding:.45rem .9rem;cursor:pointer;">{{ __('Record incident') }}</button>
            <button wire:click="$set('showEscalationForm', false)" style="background:#eee;color:#333;border:1px solid #ccc;border-radius:6px;padding:.45rem .9rem;margin-left:.5rem;cursor:pointer;">{{ __('Cancel') }}</button>
        </div>
    </div>
    @endif

    {{-- RESOLUTION FORM — the A.5.27 gate. An incident cannot close without a lesson. --}}
    @if ($showResolveForm)
    <div style="background:#fff;border-radius:12px;padding:1.2rem;margin:1rem 0;box-shadow:0 2px 8px rgba(0,0,0,.06);border-left:4px solid #1e824c;">
        <h3 style="margin-top:0;">{{ __('Resolve this incident') }}</h3>
        <p style="color:#5A6B82;font-size:.9rem;">
            {{ __('Before an incident is closed, record why it happened and what will change. An incident with no lesson is an incident that will happen again — and this is the thing auditors most often find missing.') }}
        </p>

        <div style="margin-top:.6rem;">
            <label style="color:#5A6B82;font-size:.9rem;">{{ __('Why did it happen?') }}</label>
            <textarea wire:model="rootCause" rows="3" placeholder="{{ __('The underlying cause — not just what went wrong, but why it was possible.') }}"
                style="width:100%;margin-top:.3rem;padding:.5rem;border:1px solid #ddd;border-radius:6px;"></textarea>
            @error('rootCause') <small style="color:#c0392b;">{{ $message }}</small> @enderror
        </div>

        <div style="margin-top:.6rem;">
            <label style="color:#5A6B82;font-size:.9rem;">{{ __('What will you change so it does not happen again?') }}</label>
            <textarea wire:model="lessonsLearned" rows="3" placeholder="{{ __('A concrete change — a new control, a process, training, a configuration.') }}"
                style="width:100%;margin-top:.3rem;padding:.5rem;border:1px solid #ddd;border-radius:6px;"></textarea>
            @error('lessonsLearned') <small style="color:#c0392b;">{{ $message }}</small> @enderror
        </div>

        <div style="margin-top:.8rem;">
            <button wire:click="resolve" style="background:#1e824c;color:#fff;border:none;border-radius:6px;padding:.45rem .9rem;cursor:pointer;">{{ __('Resolve incident') }}</button>
            <button wire:click="$set('showResolveForm', false)" style="background:#eee;color:#333;border:1px solid #ccc;border-radius:6px;padding:.45rem .9rem;margin-left:.5rem;cursor:pointer;">{{ __('Cancel') }}</button>
        </div>
    </div>
    @endif

    {{-- COMMENT THREAD --}}
    <div style="background:#fff;border-radius:12px;padding:1.2rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h4 style="margin-top:0;">{{ __('Activity') }}</h4>

        @forelse ($comments as $c)
            <div style="border-top:1px solid #f2f2f2;padding:.7rem 0;">
                <div style="color:#9aa;font-size:.85rem;">
                    {{ $c->author_name ?? __('System') }} · {{ $c->created_at }}
                </div>
                <div style="margin-top:.3rem;white-space:pre-wrap;">{{ $c->body }}</div>
            </div>
        @empty
            <p style="color:#5A6B82;font-size:.9rem;">{{ __('Nothing yet.') }}</p>
        @endforelse

        <div style="margin-top:1rem;">
            <textarea wire:model="commentBody" rows="3" placeholder="{{ __('Add a note') }}"
                style="width:100%;padding:.6rem;border:1px solid #ddd;border-radius:6px;"></textarea>
            @error('commentBody') <small style="color:#c0392b;">{{ $message }}</small> @enderror
            <div style="margin-top:.5rem;">
                <button wire:click="addComment" style="background:#0B2545;color:#fff;padding:.45rem .9rem;border:none;border-radius:6px;cursor:pointer;">{{ __('Add note') }}</button>
            </div>
        </div>
    </div>
</div>