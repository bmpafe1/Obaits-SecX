<div>
    <h1 style="color:#0B2545;">{{ __('Compliance') }}</h1>
    <p style="color:#5A6B82;">{{ __('The documents and evidence your auditor will ask for.') }}</p>

    @if (session('c_ok'))
        <div style="background:#e6ffe6;padding:.8rem;border-radius:6px;margin-bottom:1rem;">{{ session('c_ok') }}</div>
    @endif

    @if (session('c_err'))
        <div style="background:#fdecea;border-left:4px solid #c0392b;padding:1rem;border-radius:6px;margin-bottom:1rem;color:#c0392b;">
            {{ session('c_err') }}
        </div>
    @endif

    {{-- Unverified control references — the platform refuses to present unchecked claims as fact --}}
    @if ($unverified > 0)
        <div style="background:#fdecea;border-left:4px solid #c0392b;padding:1rem;border-radius:6px;margin-bottom:1.5rem;">
            <strong style="color:#c0392b;">{{ __('Some control references have not been verified.') }}</strong>
            <p style="margin:.3rem 0 0;color:#5A6B82;font-size:.9rem;">
                {{ __('We will not claim a document satisfies a control we have not checked against the standard itself. :n reference(s) are pending verification.', ['n' => $unverified]) }}
            </p>
        </div>
    @endif

    {{-- Profile gate --}}
    @if (!$profileReady)
    <div style="background:#fff3cd;border-left:4px solid #b8860b;padding:1rem;border-radius:6px;margin-bottom:1.5rem;">
        <strong>{{ __('Complete your organisation profile first.') }}</strong>
        <p style="margin:.3rem 0;color:#5A6B82;font-size:.9rem;">
            {{ __('Documents carry your legal name, address and named security officer. Without them, an auditor will reject what we produce.') }}
        </p>
        <ul style="color:#c0392b;font-size:.9rem;margin:.3rem 0;padding-left:1.1rem;">
            @foreach ($missing as $label)
                <li>{{ __($label) }}</li>
            @endforeach
        </ul>
        <a href="/portal/organisation">{{ __('Go to Your organisation') }}</a>
    </div>
    @endif

    {{-- The multi-framework payoff --}}
    @if (!empty($justAdded))
    <div style="background:#e6ffe6;border-left:4px solid #1e824c;padding:1rem;border-radius:6px;margin-bottom:1.5rem;">
        <strong style="color:#1e824c;">{{ __('Good news — you have already done some of this.') }}</strong>
        <p style="margin:.3rem 0;color:#5A6B82;font-size:.9rem;">
            {{ __(':n document(s) you already completed also satisfy this new standard:', ['n' => count($justAdded)]) }}
        </p>
        <ul style="color:#0B2545;font-size:.9rem;margin:.3rem 0;padding-left:1.1rem;">
            @foreach ($justAdded as $t)
                <li>{{ $t }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- Standards --}}
    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Standards you are working toward') }}</h3>
        <p style="color:#5A6B82;font-size:.9rem;">
            {{ __('You can pursue more than one. Many documents count toward several standards at once.') }}
        </p>

        @if ($chosen->isEmpty())
            <p style="color:#b8860b;">{{ __('None selected yet. Not sure which? Ask Brian.') }}</p>
        @else
            @foreach ($chosen as $c)
                <div style="display:flex;justify-content:space-between;align-items:center;border-top:1px solid #f2f2f2;padding:.6rem 0;">
                    <strong style="color:#0B2545;">{{ $c->framework_name }}</strong>
                    @if (\App\Support\Perm::can('manage_team'))
                        <button wire:click="removeFramework('{{ $c->framework_id }}')"
                            wire:confirm="{{ __('Remove this standard?') }}"
                            style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:.85rem;">
                            {{ __('Remove') }}
                        </button>
                    @endif
                </div>
            @endforeach
        @endif

        @if (\App\Support\Perm::can('manage_team'))
            <details style="margin-top:1rem;">
                <summary style="cursor:pointer;color:#17A2B8;">{{ __('Add a standard') }}</summary>
                <div style="margin-top:.6rem;">
                    @forelse ($available as $f)
                        @if (!$chosen->contains('framework_id', $f['id']))
                            <button wire:click="addFramework('{{ $f['id'] }}', @js($f['name']))"
                                style="display:block;width:100%;text-align:left;background:#f8f9fb;border:1px solid #eee;border-radius:8px;padding:.6rem;margin-bottom:.3rem;cursor:pointer;">
                                {{ $f['name'] }}
                            </button>
                        @endif
                    @empty
                        <p style="color:#b8860b;">{{ __('No standards are loaded. Import one below.') }}</p>
                    @endforelse
                </div>
            </details>

            {{-- Import a standard not yet in CISO --}}
            <div style="margin-top:1rem;border-top:1px solid #f2f2f2;padding-top:1rem;">
                <button wire:click="toggleImport"
                    style="background:none;border:none;color:#17A2B8;cursor:pointer;padding:0;font-size:.95rem;">
                    {{ $showImport ? '▼' : '▶' }} {{ __('Import a standard that is not in the list') }}
                </button>

                @if ($showImport)
                    <p style="color:#5A6B82;font-size:.88rem;margin:.6rem 0;">
                        {{ __('Bring in one of the hundreds of standards available, then add it above. Search by name, reference, or provider.') }}
                    </p>

                    <input type="text" wire:model.live.debounce.400ms="importSearch"
                        placeholder="{{ __('e.g. PCI DSS, ISO 31000, SOC 2, GDPR...') }}"
                        style="width:100%;padding:.6rem;border:1px solid #ddd;border-radius:6px;margin-bottom:.6rem;">

                    @forelse ($importable as $lib)
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;background:#f8f9fb;border:1px solid #eee;border-radius:8px;padding:.6rem;margin-bottom:.3rem;">
                            <div>
                                <strong style="color:#0B2545;font-size:.9rem;">{{ $lib['name'] }}</strong>
                                <div style="color:#9aa;font-size:.78rem;">
                                    {{ $lib['ref_id'] ?? '' }}
                                    @if (!empty($lib['provider'])) · {{ $lib['provider'] }} @endif
                                    @if (!empty($lib['locale'])) · {{ strtoupper($lib['locale']) }} @endif
                                </div>
                            </div>
                            <button wire:click="importFramework('{{ $lib['id'] }}', @js($lib['name']))"
                                wire:loading.attr="disabled" wire:target="importFramework"
                                style="background:#0B2545;color:#fff;border:none;border-radius:6px;padding:.35rem .9rem;cursor:pointer;font-size:.82rem;white-space:nowrap;">
                                {{ __('Import') }}
                            </button>
                        </div>
                    @empty
                        @if ($importSearch !== '')
                            <p style="color:#9aa;font-size:.88rem;">{{ __('No matching standards found. Try a different term.') }}</p>
                        @else
                            <p style="color:#9aa;font-size:.88rem;">{{ __('Start typing to search the catalogue.') }}</p>
                        @endif
                    @endforelse

                    <div wire:loading wire:target="importFramework" style="color:#5A6B82;font-size:.85rem;margin-top:.4rem;">
                        {{ __('Importing… this can take a moment for a large standard.') }}
                    </div>
                @endif
            </div>
        @endif
    </div>

    {{-- Per-framework counters — each one honest on its own terms --}}
    @foreach ($summaries as $s)
    <div style="background:#fff;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <p style="color:#5A6B82;font-size:.85rem;margin:0 0 .4rem;">{{ $s['name'] }}</p>

        @if (($s['total'] ?? 0) === 0)
            {{-- No document set yet — offer to draft one with AI, grounded in the standard's controls. --}}
            <div style="background:#fff8e1;border-left:3px solid #b8860b;padding:1rem;border-radius:6px;margin-top:.4rem;">
                <strong style="color:#0B2545;">{{ __('No document set for this standard yet.') }}</strong>
                <p style="margin:.4rem 0 .8rem;color:#5A6B82;font-size:.9rem;">
                    {{ __('This standard still requires records. We can draft a starting set for you using AI, based on the standard\'s own controls — then you review and complete each one.') }}
                </p>
                @if (\App\Support\Perm::can('manage_team'))
                    <button wire:click="generateDocs('{{ $s['id'] }}', @js($s['name']))"
                        wire:loading.attr="disabled" wire:target="generateDocs"
                        style="background:#0B2545;color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;cursor:pointer;">
                        {{ __('Draft a document set with AI') }}
                    </button>
                    <span wire:loading wire:target="generateDocs" style="color:#5A6B82;font-size:.85rem;margin-left:.5rem;">
                        {{ __('Starting…') }}
                    </span>
                    <p style="margin:.6rem 0 0;color:#9aa;font-size:.8rem;">
                        {{ __('AI-drafted documents are a starting point, not a verified compliance set. Have a professional review them before an audit.') }}
                    </p>
                @endif
            </div>
        @else
            <h3 style="margin:0;color:#0B2545;">
                {{ __('We can help you produce :n of the :t documents in our set for this standard.', [
                    'n' => $s['helpable'], 't' => $s['total'],
                ]) }}
            </h3>
            <p style="color:#5A6B82;font-size:.85rem;margin:.3rem 0 0;">
                {{ __('This standard does not fix a number of documents. It requires certain records, plus whatever else your security programme needs. This is our recommended set.') }}
            </p>

            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin:1.2rem 0;">
                <div style="text-align:center;">
                    <div style="font-size:1.7rem;font-weight:700;color:#1e824c;">{{ $s['by_mode']['automated'] }}</div>
                    <div style="color:#5A6B82;font-size:.78rem;">{{ __('From your live data') }}</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:1.7rem;font-weight:700;color:#17A2B8;">{{ $s['by_mode']['interview'] }}</div>
                    <div style="color:#5A6B82;font-size:.78rem;">{{ __('I write after a few questions') }}</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:1.7rem;font-weight:700;color:#b8860b;">{{ $s['by_mode']['delegate'] }}</div>
                    <div style="color:#5A6B82;font-size:.78rem;">{{ __('Belong to someone else') }}</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:1.7rem;font-weight:700;color:#5A6B82;">{{ $s['by_mode']['guide'] }}</div>
                    <div style="color:#5A6B82;font-size:.78rem;">{{ __('Only you can do') }}</div>
                </div>
            </div>

            @php $pct = $s['helpable'] ? (int) round($s['completed'] / $s['helpable'] * 100) : 0; @endphp
            <div style="background:#eee;height:8px;border-radius:4px;">
                <div style="background:#1e824c;height:8px;border-radius:4px;width:{{ $pct }}%;"></div>
            </div>
            <p style="color:#5A6B82;font-size:.85rem;margin:.4rem 0 0;">
                {{ __(':done finished, :prog in progress.', ['done' => $s['completed'], 'prog' => $s['in_progress']]) }}
            </p>

            {{-- THE HONEST COUNTER. Never soften or remove this. --}}
            <div style="background:#fff8e1;border-left:3px solid #b8860b;padding:1rem;border-radius:6px;margin-top:1.4rem;">
                <strong style="color:#0B2545;">
                    {{ __('Separately: we can PROVE :n of your controls are actually operating.', ['n' => $s['evidence_proven']]) }}
                </strong>
                <p style="margin:.4rem 0 0;color:#5A6B82;font-size:.9rem;">
                    {{ __('A policy says what you do. Evidence shows that you do it. We measure these continuously from your live systems — the rest of your documents rely on what you have told us. An auditor will want both, and will test the second.') }}
                </p>
            </div>
        @endif
    </div>
    @endforeach

    {{-- The document register --}}
    @if (!empty($register))
    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Your documents') }}</h3>
        {{-- Filter the register by framework --}}
        <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin:.8rem 0 1rem;">
            <button wire:click="$set('docFilter', '')"
                style="padding:.3rem .8rem;border:1px solid {{ $docFilter === '' ? '#0B2545' : '#ddd' }};border-radius:6px;background:{{ $docFilter === '' ? '#0B2545' : '#fff' }};color:{{ $docFilter === '' ? '#fff' : '#0B2545' }};cursor:pointer;font-size:.83rem;">
                {{ __('All') }}
            </button>
            @foreach ($chosen as $c)
                <button wire:click="$set('docFilter', @js($c->framework_name))"
                    style="padding:.3rem .8rem;border:1px solid {{ $docFilter === $c->framework_name ? '#0B2545' : '#ddd' }};border-radius:6px;background:{{ $docFilter === $c->framework_name ? '#0B2545' : '#fff' }};color:{{ $docFilter === $c->framework_name ? '#fff' : '#0B2545' }};cursor:pointer;font-size:.83rem;">
                    {{ $c->framework_name }}
                </button>
            @endforeach
        </div>
        @php
            $modeColour = ['automated'=>'#1e824c','interview'=>'#17A2B8','delegate'=>'#b8860b','guide'=>'#5A6B82'];
            $modeLabel = [
                'automated' => __('From your data'),
                'interview' => __('A few questions'),
                'delegate'  => __('Someone else'),
                'guide'     => __('Guidance only'),
            ];
        @endphp

        @foreach ($register as $key => $d)
            {{-- Filter: skip documents not mapped to the selected framework --}}
            @if ($docFilter !== '' && !collect($d['frameworks'] ?? [])->keys()->contains($docFilter))
                @continue
            @endif
            @php $latest = ($docStatus[$key] ?? collect())->first(); @endphp
            <div style="border-top:1px solid #f2f2f2;padding:.8rem 0;display:flex;justify-content:space-between;align-items:center;gap:1rem;">
                <div>
                    <span style="background:{{ $modeColour[$d['mode']] }};color:#fff;border-radius:4px;padding:.1rem .5rem;font-size:.75rem;white-space:nowrap;">
                        {{ $modeLabel[$d['mode']] }}
                    </span>
                    <strong style="color:#0B2545;margin-left:.4rem;">{{ __($d['title']) }}</strong>

                    @if (($d['source'] ?? '') === 'ai_drafted')
                        <span style="background:#efe7fb;color:#6b46c1;border-radius:4px;padding:.1rem .5rem;font-size:.72rem;margin-left:.3rem;">
                            {{ __('AI draft') }}
                        </span>
                    @endif

                    {{-- Framework tags: which standard(s) this document serves --}}
                    @foreach (($d['frameworks'] ?? []) as $fwName => $controls)
                        <span style="background:#eef3f8;color:#17A2B8;border-radius:4px;padding:.1rem .5rem;font-size:.72rem;margin-left:.3rem;white-space:nowrap;"
                              title="{{ $controls->pluck('control_ref')->take(8)->implode(', ') }}">
                            {{ \Illuminate\Support\Str::limit($fwName, 22) }}
                        </span>
                    @endforeach

                    @if ($latest)
                        <span style="color:{{ $latest->status === 'final' ? '#1e824c' : '#b8860b' }};font-size:.85rem;">
                            · {{ __($latest->status) }} (v{{ $latest->version }})
                        </span>
                    @endif
                </div>
                <a href="/portal/document/{{ $key }}"
                   style="background:#f8f9fb;border:1px solid #ddd;border-radius:8px;padding:.35rem .9rem;text-decoration:none;color:#0B2545;white-space:nowrap;">
                    {{ $latest ? __('Open') : __('Start') }}
                </a>
            </div>
        @endforeach
    </div>
    @endif
</div>