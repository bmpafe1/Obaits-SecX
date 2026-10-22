<div>
    <h1 style="font-size:1.6rem;color:#1F3864;margin-bottom:.3rem;">{{ __($categories[$category]) }}</h1>
    <p style="color:#5A6B82;margin-bottom:1.2rem;">
        {{ __('Training we recommend. Courses are run by the institutions named, not by Obaits.') }}
    </p>

    <div style="background:#fff;border-radius:12px;padding:1rem;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:1.2rem;">
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;">
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="{{ __('Search training...') }}"
                   style="flex:1;min-width:220px;padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">

            <select wire:model.live="level" style="padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">
                <option value="">{{ __('Any level') }}</option>
                @foreach ($levels as $k => $v) <option value="{{ $k }}">{{ __($v) }}</option> @endforeach
            </select>

            <select wire:model.live="costFilter" style="padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">
                <option value="">{{ __('Any cost') }}</option>
                <option value="free">{{ __('Free only') }}</option>
                <option value="paid">{{ __('Paid only') }}</option>
            </select>
        </div>

        {{-- The language filter is on by default, so say so. A filter people do
             not know is applied is a filter that makes the catalogue look empty. --}}
        <label style="display:flex;align-items:center;gap:.4rem;margin-top:.7rem;color:#5A6B82;font-size:.88rem;">
            <input type="checkbox" wire:model.live="allLanguages">
            {{ __('Show training in all languages') }}
            @if (!$allLanguages && $hiddenByLanguage > 0)
                <span style="color:#8fa3bf;">
                    ({{ __(':n more available in other languages', ['n' => $hiddenByLanguage]) }})
                </span>
            @endif
        </label>
    </div>

    @if ($trainings->isEmpty())
        <div style="background:#fff;border-radius:12px;padding:2rem;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.08);">
            <p style="color:#5A6B82;margin:0;">
                {{ $allLanguages ? __('No training matches your filters yet.') : __('No training in your languages matches your filters yet.') }}
            </p>
        </div>
    @else
        <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));gap:1rem;">
            @foreach ($trainings as $t)
                @php $langs = json_decode($t->languages ?? '[]', true) ?: []; @endphp
                <div style="background:#fff;border-radius:12px;padding:1.1rem;box-shadow:0 1px 3px rgba(0,0,0,.08);display:flex;flex-direction:column;">
                    <h3 style="font-size:1.05rem;color:#1F3864;margin:0 0 .3rem;">{{ $t->title }}</h3>

                    @if ($t->institution)
                        <div style="color:#5A6B82;font-size:.88rem;margin-bottom:.5rem;">{{ $t->institution }}</div>
                    @endif

                    <p style="color:#5A6B82;font-size:.9rem;line-height:1.5;flex:1;margin:0 0 .8rem;">
                        {{ \Illuminate\Support\Str::limit($t->description, 150) }}
                    </p>

                    <div style="display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:.7rem;">
                        @foreach ($langs as $l)
                            <span style="font-size:.72rem;padding:.1rem .45rem;border-radius:999px;background:#E8EDF8;color:#1F3864;">{{ strtoupper($l) }}</span>
                        @endforeach
                        @if ($t->level)
                            <span style="font-size:.72rem;padding:.1rem .45rem;border-radius:999px;background:#F0F2F5;color:#5A6B82;">{{ __($levels[$t->level] ?? $t->level) }}</span>
                        @endif
                        @if ($t->duration)
                            <span style="font-size:.72rem;padding:.1rem .45rem;border-radius:999px;background:#F0F2F5;color:#5A6B82;">{{ $t->duration }}</span>
                        @endif
                    </div>

                    <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;">
                        <span style="font-size:.9rem;{{ (float) $t->cost === 0.0 ? 'color:#0B6E4F;font-weight:600;' : 'color:#5A6B82;' }}">
                            @if ($t->cost === null)
                                {{ __('See website') }}
                            @elseif ((float) $t->cost === 0.0)
                                {{ __('Free') }}
                            @else
                                {{ $t->currency }} {{ number_format((float) $t->cost, 2) }}
                            @endif
                        </span>
                        <a href="{{ $t->url }}" target="_blank" rel="noopener noreferrer"
                           style="background:#17A2B8;color:#fff;padding:.45rem 1rem;border-radius:8px;text-decoration:none;font-size:.9rem;">
                            {{ __('Open') }}
                        </a>
                    </div>
                </div>
            @endforeach
        </div>

        <div style="margin-top:1.2rem;">{{ $trainings->links() }}</div>
    @endif

    <p style="color:#8fa3bf;font-size:.8rem;margin-top:1.5rem;line-height:1.5;">
        {{ __('These courses are run by the institutions named. Obaits is not the provider, does not receive payment for them, and any enrolment or payment is arranged directly with the institution.') }}
    </p>
</div>
