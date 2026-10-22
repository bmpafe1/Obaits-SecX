<div>
    <p><a href="/portal/compliance">&larr; {{ __('Back to compliance') }}</a></p>
    <h1 style="color:#0B2545;">{{ __($doc['title']) }}</h1>

    {{-- Control references, grouped by framework. Unverified ones are flagged, never shown as fact. --}}
    @if (!empty($doc['frameworks']) && count($doc['frameworks']) > 0)
        <div style="margin:.6rem 0 1.2rem;">
            @foreach ($doc['frameworks'] as $fwName => $refs)
                <div style="margin-bottom:.3rem;">
                    <span style="color:#5A6B82;font-size:.85rem;">{{ $fwName }}:</span>
                    @foreach ($refs as $r)
                        @if ($r->verified)
                            <span style="background:#eef7f0;color:#1e824c;border-radius:4px;padding:.1rem .45rem;font-size:.8rem;margin-left:.2rem;">
                                {{ $r->control_ref }}
                            </span>
                        @else
                            <span style="background:#fdecea;color:#c0392b;border-radius:4px;padding:.1rem .45rem;font-size:.8rem;margin-left:.2rem;"
                                  title="{{ __('This reference has not been verified against the standard.') }}">
                                ⚠ {{ $r->control_ref }} — {{ __('unverified') }}
                            </span>
                        @endif
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif

    @if (session('d_ok'))
        <div style="background:#e6ffe6;padding:.8rem;border-radius:6px;margin-bottom:1rem;">{{ session('d_ok') }}</div>
    @endif

    {{-- ============ AUTOMATED: built from live data ============ --}}
    @if ($doc['mode'] === 'automated')
    <div style="background:#fff;border-left:4px solid #1e824c;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;color:#1e824c;">{{ __('I can build this from your data') }}</h3>
        <p style="color:#5A6B82;">{{ __('This document is built from what your systems actually report. There is nothing for you to fill in.') }}</p>
        <button wire:click="generate" wire:loading.attr="disabled"
            style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.6rem 1.4rem;cursor:pointer;">
            {{ $body !== '' ? __('Regenerate from my data') : __('Generate from my data') }}
        </button>
        <span wire:loading wire:target="generate" style="color:#5A6B82;margin-left:.6rem;">{{ __('Generating…') }}</span>
    </div>
    @endif

    {{-- ============ INTERVIEW: Brian asks, then writes ============ --}}
    @if ($doc['mode'] === 'interview')
    <div style="background:#fff;border-left:4px solid #17A2B8;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;color:#17A2B8;">{{ __('A few questions and I will write it for you') }}</h3>
        <p style="color:#5A6B82;font-size:.9rem;">
            {{ __('Answer honestly — including where you do not do something. I will note the gaps rather than pretend they are not there. Gaps are useful: they are exactly what you need to know before an auditor finds them.') }}
        </p>

        @foreach ($doc['questions'] as $i => $q)
            <div style="margin-bottom:1rem;">
                <label style="display:block;color:#0B2545;font-weight:600;margin-bottom:.3rem;">
                    {{ $i + 1 }}. {{ __($q) }}
                </label>
                <textarea wire:model="answers.{{ $i }}" rows="2"
                    style="width:100%;padding:.5rem;border:1px solid #ddd;border-radius:6px;font-family:inherit;"></textarea>
            </div>
        @endforeach

        <button wire:click="generate" wire:loading.attr="disabled"
            style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.6rem 1.4rem;cursor:pointer;">
            {{ $body !== '' ? __('Rewrite my document') : __('Write my document') }}
        </button>
        <span wire:loading wire:target="generate" style="color:#5A6B82;margin-left:.6rem;">{{ __('Brian is writing…') }}</span>
    </div>
    @endif

    {{-- ============ DELEGATE: not the IT person's job ============ --}}
    @if ($doc['mode'] === 'delegate')
    <div style="background:#fff;border-left:4px solid #b8860b;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;color:#b8860b;">{{ __('This one belongs to someone else') }}</h3>

        @if ($delegateNote === '')
            <p style="color:#5A6B82;">
                {{ __('This document is normally owned by') }}: <strong>{{ __($doc['owner']) }}</strong>
            </p>
            <p style="color:#5A6B82;font-size:.9rem;">{{ __($doc['ask']) }}</p>
            <button wire:click="prepareDelegatePack" wire:loading.attr="disabled"
                style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.6rem 1.4rem;cursor:pointer;">
                {{ __('Prepare a template to send them') }}
            </button>
            <span wire:loading wire:target="prepareDelegatePack" style="color:#5A6B82;margin-left:.6rem;">{{ __('Preparing…') }}</span>
        @else
            <p style="color:#0B2545;">{{ strip_tags(str_replace(['**'], '', $delegateNote)) }}</p>

            @if ($owner)
                <div style="background:#f8f9fb;border-radius:8px;padding:1rem;margin-top:.8rem;">
                    <strong style="color:#0B2545;">{{ $owner['name'] }}</strong>
                    <span style="color:#5A6B82;"> — {{ $owner['title'] }}</span>
                    @if (!empty($owner['email']))
                        <br><span style="color:#5A6B82;font-size:.9rem;">{{ $owner['email'] }}</span>
                    @endif
                </div>
            @else
                <p style="margin-top:.6rem;">
                    <a href="/portal/governance">{{ __('Add them on People and approvals') }}</a>
                </p>
            @endif

            @if ($delegateFile)
                <p style="margin-top:1rem;">
                    <a href="{{ \Illuminate\Support\Facades\Storage::url($delegateFile) }}" download
                       style="background:#0B2545;color:#fff;padding:.55rem 1.2rem;border-radius:8px;text-decoration:none;display:inline-block;">
                        {{ __('Download the template') }}
                    </a>
                </p>
                <p style="color:#5A6B82;font-size:.85rem;margin-top:.4rem;">
                    {{ __('The template already carries your organisation name, logo and address. Send it to them, and add the completed document back here when it returns.') }}
                </p>
            @endif
        @endif
    </div>
    @endif

    {{-- ============ GUIDE: we cannot produce this ============ --}}
    @if ($doc['mode'] === 'guide')
    <div style="background:#fff;border-left:4px solid #5A6B82;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;color:#5A6B82;">{{ __('I cannot produce this one — but here is what you need to know') }}</h3>
        <p style="color:#0B2545;">{{ __($doc['advice']) }}</p>
    </div>
    @endif

    {{-- ============ THE DRAFT ============ --}}
    @if ($body !== '' && $doc['mode'] !== 'guide')
    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Your document') }}</h3>
        <p style="color:#5A6B82;font-size:.9rem;">
            {{ __('Read it. Edit anything that is wrong. Anything in [square brackets] needs your attention before this is final.') }}
        </p>
        <textarea wire:model="body" rows="20"
            style="width:100%;padding:.8rem;border:1px solid #ddd;border-radius:6px;font-family:monospace;font-size:.85rem;line-height:1.5;"></textarea>

        <div style="margin-top:.8rem;display:flex;gap:.6rem;flex-wrap:wrap;">
            <button wire:click="saveDraft"
                style="background:#5A6B82;color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;cursor:pointer;">
                {{ __('Save draft') }}
            </button>
            <button wire:click="markFinal"
                style="background:#1e824c;color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;cursor:pointer;">
                {{ __('Mark as final') }}
            </button>
        </div>
    </div>
    @endif

    {{-- ============ VERSIONS ============ --}}
    @if ($versions->isNotEmpty())
    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Versions') }}</h3>
        <p style="color:#5A6B82;font-size:.85rem;">
            {{ __('Every version is kept. An auditor may ask what your policy said last year.') }}
        </p>
        @foreach ($versions as $v)
            <div style="border-top:1px solid #f2f2f2;padding:.7rem 0;display:flex;justify-content:space-between;align-items:center;gap:1rem;">
                <span>
                    <strong>{{ __('Version') }} {{ $v->version }}</strong>
                    @php
                        $statusColour = match ($v->status) {
                            'final' => '#1e824c',
                            'sent_to_owner' => '#b8860b',
                            'received' => '#17A2B8',
                            default => '#5A6B82',
                        };
                    @endphp
                    <span style="color:{{ $statusColour }};font-size:.85rem;">({{ __($v->status) }})</span>
                    @if ($v->assigned_to)
                        <span style="color:#5A6B82;font-size:.85rem;"> · {{ __('with') }} {{ $v->assigned_to }}</span>
                    @endif
                    <span style="color:#9aa;font-size:.85rem;"> · {{ \Illuminate\Support\Str::limit($v->created_at, 16, '') }}</span>
                </span>
                @if ($v->file_path)
                    <a href="{{ \Illuminate\Support\Facades\Storage::url($v->file_path) }}" download
                       style="white-space:nowrap;">{{ __('Download .docx') }}</a>
                @endif
            </div>
        @endforeach
    </div>
    @endif
</div>