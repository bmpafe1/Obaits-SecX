<div>
    <h1 style="font-size:1.6rem; color:#1F3864; margin-bottom:.3rem;">{{ __('Services') }}</h1>
    <p style="color:#5A6B82; margin-bottom:1.2rem;">
        {{ __('Expert help with the technologies you already run. Providers handle quotation, contracting and delivery directly.') }}
    </p>

    {{-- Filters --}}
    <div style="background:#fff; border-radius:12px; padding:1rem; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:1.2rem;">
        <div style="display:flex; gap:.6rem; flex-wrap:wrap; align-items:center;">
            <input type="text" wire:model.live.debounce.400ms="search"
                   placeholder="{{ __('Search services or providers...') }}"
                   style="flex:1; min-width:220px; padding:.55rem; border:1px solid #dde3ea; border-radius:8px;">

            <select wire:model.live="category" style="padding:.55rem; border:1px solid #dde3ea; border-radius:8px;">
                <option value="">{{ __('All categories') }}</option>
                @foreach ($categories as $key => $label)
                    <option value="{{ $key }}">{{ __($label) }}</option>
                @endforeach
            </select>

            <select wire:model.live="technology" style="padding:.55rem; border:1px solid #dde3ea; border-radius:8px;">
                <option value="">{{ __('All technologies') }}</option>
                @foreach ($technologies as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>

            <select wire:model.live="providerStatus" style="padding:.55rem; border:1px solid #dde3ea; border-radius:8px;">
                <option value="">{{ __('All providers') }}</option>
                <option value="official_upstream">{{ __('Official upstream') }}</option>
                <option value="authorized_partner">{{ __('Authorized partner') }}</option>
                <option value="verified">{{ __('Verified') }}</option>
                <option value="community">{{ __('Community') }}</option>
            </select>

            <button wire:click="clearFilters"
                    style="padding:.55rem .9rem; border:1px solid #dde3ea; background:#f7f9fc; border-radius:8px; color:#5A6B82;">
                {{ __('Clear') }}
            </button>
        </div>
    </div>

    @if ($services->isEmpty())
        <div style="background:#fff; border-radius:12px; padding:2rem; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,.08);">
            <p style="color:#5A6B82; margin:0;">{{ __('No services match your filters yet.') }}</p>
        </div>
    @else
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:1rem;">
            @foreach ($services as $s)
                <div style="background:#fff; border-radius:12px; padding:1.1rem; box-shadow:0 1px 3px rgba(0,0,0,.08); display:flex; flex-direction:column;">
                    <div style="display:flex; justify-content:space-between; align-items:start; gap:.5rem;">
                        <h3 style="font-size:1.05rem; color:#1F3864; margin:0 0 .3rem;">{{ $s->title }}</h3>
                    </div>

                    <div style="margin-bottom:.5rem;">
                        <span style="color:#5A6B82; font-size:.9rem;">{{ $s->provider_name }}</span>
                        @php
                            $badge = match ($s->provider_status) {
                                'official_upstream' => ['#0B6E4F', '#E3F3EC', __('Official upstream')],
                                'authorized_partner' => ['#1F3864', '#E8EDF8', __('Authorized partner')],
                                'verified' => ['#7A5C00', '#FFF6DC', __('Verified')],
                                default => ['#5A6B82', '#F0F2F5', __('Community')],
                            };
                        @endphp
                        <span style="display:inline-block; margin-left:.4rem; padding:.12rem .5rem; border-radius:999px; font-size:.72rem; color:{{ $badge[0] }}; background:{{ $badge[1] }};">
                            {{ $badge[2] }}
                        </span>
                    </div>

                    <p style="color:#5A6B82; font-size:.9rem; line-height:1.5; flex:1; margin:0 0 .8rem;">
                        {{ \Illuminate\Support\Str::limit($s->description, 160) }}
                    </p>

                    <div style="display:flex; justify-content:space-between; align-items:center; gap:.5rem;">
                        <span style="color:#5A6B82; font-size:.85rem;">
                            {{ $s->pricing ?: __('Request a quote') }}
                        </span>
                        <a href="/portal/marketplace/services/{{ $s->id }}"
                           style="background:#17A2B8; color:#fff; padding:.45rem 1rem; border-radius:8px; text-decoration:none; font-size:.9rem;">
                            {{ __('View') }}
                        </a>
                    </div>
                </div>
            @endforeach
        </div>

        <div style="margin-top:1.2rem;">{{ $services->links() }}</div>
    @endif

    <p style="color:#8fa3bf; font-size:.8rem; margin-top:1.5rem; line-height:1.5;">
        {{ __('Provider status describes what Obaits has verified. It is not an endorsement of any provider, and Obaits is not party to the contract between you and a provider.') }}
    </p>
</div>
