<div>
    <h1 style="font-size:1.6rem;color:#1F3864;margin-bottom:.3rem;">{{ __('Providers') }}</h1>
    <p style="color:#5A6B82;margin-bottom:1.2rem;">
        {{ __('Organisations offering help with the technologies you run. Work is contracted directly with them.') }}
    </p>

    <div style="background:#fff;border-radius:12px;padding:1rem;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:1.2rem;">
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="{{ __('Search providers...') }}"
                   style="flex:1;min-width:220px;padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">

            <select wire:model.live="technology" style="padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">
                <option value="">{{ __('All technologies') }}</option>
                @foreach ($technologies as $k => $v) <option value="{{ $k }}">{{ $v }}</option> @endforeach
            </select>

            <select wire:model.live="statusFilter" style="padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">
                <option value="">{{ __('All providers') }}</option>
                <option value="official_upstream">{{ __('Official upstream') }}</option>
                <option value="authorized_partner">{{ __('Authorized partner') }}</option>
                <option value="verified">{{ __('Verified') }}</option>
                <option value="community">{{ __('Community') }}</option>
            </select>
        </div>

        @if ($countryName)
            <label style="display:flex;align-items:center;gap:.4rem;margin-top:.7rem;color:#5A6B82;font-size:.88rem;">
                <input type="checkbox" wire:model.live="myCountryOnly">
                {{ __('Only providers who work in :country', ['country' => $countryName]) }}
            </label>
        @endif
    </div>

    @if ($providers->isEmpty())
        <div style="background:#fff;border-radius:12px;padding:2rem;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.08);">
            <p style="color:#5A6B82;margin:0;">
                {{ $myCountryOnly && $countryName
                    ? __('No providers working in :country match your filters.', ['country' => $countryName])
                    : __('No providers match your filters yet.') }}
            </p>
        </div>
    @else
        <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(340px, 1fr));gap:1rem;">
            @foreach ($providers as $p)
                @php
                    $badge = match ($p->status) {
                        'official_upstream'  => ['#0B6E4F', '#E3F3EC', __('Official upstream')],
                        'authorized_partner' => ['#1F3864', '#E8EDF8', __('Authorized partner')],
                        'verified'           => ['#7A5C00', '#FFF6DC', __('Verified')],
                        default              => ['#5A6B82', '#F0F2F5', __('Community')],
                    };
                    $techs = json_decode($p->technologies ?? '[]', true) ?: [];
                @endphp
                <div style="background:#fff;border-radius:12px;padding:1.2rem;box-shadow:0 1px 3px rgba(0,0,0,.08);display:flex;flex-direction:column;">
                    <div style="margin-bottom:.5rem;">
                        <h3 style="font-size:1.05rem;color:#1F3864;margin:0 0 .25rem;">{{ $p->display_name }}</h3>
                        <span style="display:inline-block;padding:.12rem .5rem;border-radius:999px;font-size:.72rem;color:{{ $badge[0] }};background:{{ $badge[1] }};">
                            {{ $badge[2] }}
                        </span>
                    </div>

                    <p style="color:#5A6B82;font-size:.9rem;line-height:1.5;flex:1;margin:0 0 .8rem;">
                        {{ \Illuminate\Support\Str::limit($p->description, 160) }}
                    </p>

                    @if ($techs)
                        <div style="display:flex;gap:.3rem;flex-wrap:wrap;margin-bottom:.7rem;">
                            @foreach (array_slice($techs, 0, 5) as $t)
                                <span style="font-size:.72rem;padding:.1rem .45rem;border-radius:999px;background:#f2f4f7;color:#5A6B82;">
                                    {{ $technologies[$t] ?? $t }}
                                </span>
                            @endforeach
                            @if (count($techs) > 5)
                                <span style="font-size:.72rem;color:#8fa3bf;">+{{ count($techs) - 5 }}</span>
                            @endif
                        </div>
                    @endif

                    <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;">
                        <span style="color:#8fa3bf;font-size:.85rem;">
                            {{ trans_choice('{1} :n service|[2,*] :n services', $p->service_count, ['n' => $p->service_count]) }}
                            @if ($p->response_time) · {{ $p->response_time }} @endif
                        </span>
                        <a href="/portal/marketplace/services?provider={{ $p->slug }}"
                           style="background:#17A2B8;color:#fff;padding:.45rem 1rem;border-radius:8px;text-decoration:none;font-size:.9rem;">
                            {{ __('View services') }}
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <p style="color:#8fa3bf;font-size:.8rem;margin-top:1.5rem;line-height:1.5;">
        {{ __('Provider status describes what Obaits has verified. It is not an endorsement, and Obaits is not party to any contract between you and a provider.') }}
    </p>
</div>
