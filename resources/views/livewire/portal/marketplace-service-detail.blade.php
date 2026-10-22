<div>
    <a href="/portal/marketplace/services" style="color:#17A2B8;text-decoration:none;font-size:.9rem;">&larr; {{ __('Back to services') }}</a>

    @if (!$service)
        <div style="background:#fff;border-radius:12px;padding:2rem;margin-top:1rem;box-shadow:0 1px 3px rgba(0,0,0,.08);">
            <p style="color:#5A6B82;margin:0;">{{ __('That service is no longer available.') }}</p>
        </div>
    @elseif ($submitted)
        {{-- Confirmation. Deliberately explicit that the platform is not party
             to what happens next -- the provider contracts directly. --}}
        <div style="background:#fff;border-radius:12px;padding:2rem;margin-top:1rem;box-shadow:0 1px 3px rgba(0,0,0,.08);max-width:640px;">
            <h1 style="font-size:1.4rem;color:#0B6E4F;margin:0 0 .6rem;">{{ __('Request #:number sent', ['number' => $submittedNumber]) }}</h1>
            <p style="color:#5A6B82;line-height:1.6;">
                {{ __('We have sent your request to :provider. They will contact you directly at :email.', ['provider' => $service->provider_name, 'email' => session('auth_email')]) }}
            </p>
            <p style="color:#5A6B82;line-height:1.6;font-size:.9rem;">
                {{ __('Any quotation, contract, payment and delivery is arranged directly between you and the provider. Obaits is not a party to that agreement.') }}
            </p>
            <a href="/portal/marketplace/requests" style="display:inline-block;background:#17A2B8;color:#fff;padding:.55rem 1.2rem;border-radius:8px;text-decoration:none;margin-top:.5rem;">{{ __('View my requests') }}</a>
        </div>
    @else
        @php
            $badge = match ($service->provider_status) {
                'official_upstream'  => ['#0B6E4F', '#E3F3EC', __('Official upstream')],
                'authorized_partner' => ['#1F3864', '#E8EDF8', __('Authorized partner')],
                'verified'           => ['#7A5C00', '#FFF6DC', __('Verified')],
                default              => ['#5A6B82', '#F0F2F5', __('Community')],
            };
            $delivery = json_decode($service->delivery_models ?? '[]', true) ?: [];
            $regions  = json_decode($service->regions ?? '[]', true) ?: [];
        @endphp

        <div style="display:flex;gap:1.2rem;flex-wrap:wrap;margin-top:1rem;align-items:flex-start;">

            {{-- The service --}}
            <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.08);flex:2;min-width:340px;">
                <h1 style="font-size:1.5rem;color:#1F3864;margin:0 0 .4rem;">{{ $service->title }}</h1>

                <div style="margin-bottom:1rem;">
                    <span style="color:#5A6B82;">{{ $service->provider_name }}</span>
                    <span style="display:inline-block;margin-left:.4rem;padding:.12rem .5rem;border-radius:999px;font-size:.72rem;color:{{ $badge[0] }};background:{{ $badge[1] }};">{{ $badge[2] }}</span>
                </div>

                <p style="color:#3d4d61;line-height:1.7;white-space:pre-line;">{{ $service->description }}</p>

                <dl style="display:grid;grid-template-columns:auto 1fr;gap:.5rem 1rem;margin-top:1.2rem;font-size:.9rem;">
                    <dt style="color:#8fa3bf;">{{ __('Category') }}</dt>
                    <dd style="margin:0;color:#3d4d61;">{{ __($categories[$service->category] ?? $service->category) }}</dd>

                    @if ($service->technology)
                        <dt style="color:#8fa3bf;">{{ __('Technology') }}</dt>
                        <dd style="margin:0;color:#3d4d61;">{{ $technologies[$service->technology] ?? $service->technology }}</dd>
                    @endif

                    @if ($delivery)
                        <dt style="color:#8fa3bf;">{{ __('Delivered') }}</dt>
                        <dd style="margin:0;color:#3d4d61;">{{ implode(', ', array_map(fn($d) => __(ucfirst($d)), $delivery)) }}</dd>
                    @endif

                    @if ($regions)
                        <dt style="color:#8fa3bf;">{{ __('Regions') }}</dt>
                        <dd style="margin:0;color:#3d4d61;">{{ implode(', ', $regions) }}</dd>
                    @endif

                    <dt style="color:#8fa3bf;">{{ __('Pricing') }}</dt>
                    <dd style="margin:0;color:#3d4d61;">{{ $service->pricing ?: __('Request a quote') }}</dd>

                    @if ($service->response_time)
                        <dt style="color:#8fa3bf;">{{ __('Responds') }}</dt>
                        <dd style="margin:0;color:#3d4d61;">{{ $service->response_time }}</dd>
                    @endif

                    @if ($service->prerequisites)
                        <dt style="color:#8fa3bf;">{{ __('Prerequisites') }}</dt>
                        <dd style="margin:0;color:#3d4d61;">{{ $service->prerequisites }}</dd>
                    @endif
                </dl>

                @if ($service->provider_description)
                    <div style="margin-top:1.2rem;padding-top:1rem;border-top:1px solid #eef2f6;">
                        <h3 style="font-size:.95rem;color:#1F3864;margin:0 0 .4rem;">{{ __('About :provider', ['provider' => $service->provider_name]) }}</h3>
                        <p style="color:#5A6B82;font-size:.9rem;line-height:1.6;margin:0;">{{ $service->provider_description }}</p>
                        @if ($service->provider_website)
                            <a href="{{ $service->provider_website }}" target="_blank" rel="noopener noreferrer" style="color:#17A2B8;font-size:.9rem;">{{ $service->provider_website }}</a>
                        @endif
                    </div>
                @endif
            </div>

            {{-- The request --}}
            <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.08);flex:1;min-width:320px;">
                <h2 style="font-size:1.1rem;color:#1F3864;margin:0 0 .8rem;">{{ __('Request this service') }}</h2>

                @if (!$canRequest)
                    {{-- Not a form that fails on click: an explanation. --}}
                    <p style="color:#5A6B82;font-size:.9rem;line-height:1.6;background:#fff3cd;padding:.8rem;border-radius:8px;">
                        {{ __('Only an administrator in your organisation can send a request, because it starts a commercial conversation on your organisation\'s behalf. Ask an administrator to raise it for you.') }}
                    </p>
                @else
                    <label style="display:block;color:#5A6B82;font-size:.85rem;margin-bottom:.2rem;">{{ __('What do you need?') }}</label>
                    <input type="text" wire:model="summary" placeholder="{{ __('A short summary') }}"
                           style="width:100%;padding:.55rem;border:1px solid #dde3ea;border-radius:8px;margin-bottom:.2rem;">
                    @error('summary') <small style="color:#c0392b;">{{ $message }}</small> @enderror

                    <label style="display:block;color:#5A6B82;font-size:.85rem;margin:.7rem 0 .2rem;">{{ __('Tell them more') }}</label>
                    <textarea wire:model="details" rows="5" placeholder="{{ __('What you are trying to achieve, and anything they should know.') }}"
                              style="width:100%;padding:.55rem;border:1px solid #dde3ea;border-radius:8px;"></textarea>
                    @error('details') <small style="color:#c0392b;">{{ $message }}</small> @enderror

                    <label style="display:block;color:#5A6B82;font-size:.85rem;margin:.7rem 0 .2rem;">{{ __('Priority') }}</label>
                    <select wire:model="priority" style="width:100%;padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">
                        <option value="low">{{ __('Low') }}</option>
                        <option value="normal">{{ __('Normal') }}</option>
                        <option value="high">{{ __('High') }}</option>
                    </select>

                    {{-- Self-declared context. Everything here is stated by the
                         customer; nothing is read from platform data. --}}
                    <div style="margin-top:1.2rem;padding-top:1rem;border-top:1px solid #eef2f6;">
                        <h3 style="font-size:.9rem;color:#1F3864;margin:0 0 .3rem;">{{ __('About your environment') }} <span style="color:#8fa3bf;font-weight:normal;">({{ __('optional') }})</span></h3>
                        <p style="color:#8fa3bf;font-size:.8rem;line-height:1.5;margin:0 0 .7rem;">
                            {{ __('Helps the provider give you a useful answer. Only what you enter here is shared.') }}
                        </p>

                        <label style="display:block;color:#5A6B82;font-size:.82rem;margin-bottom:.2rem;">{{ __('Technologies you run') }}</label>
                        <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.7rem;">
                            @foreach ($technologies as $key => $label)
                                <label style="font-size:.82rem;color:#3d4d61;background:#f7f9fc;padding:.25rem .5rem;border-radius:6px;">
                                    <input type="checkbox" wire:model="ctxTechnologies" value="{{ $key }}"> {{ $label }}
                                </label>
                            @endforeach
                        </div>

                        <label style="display:block;color:#5A6B82;font-size:.82rem;margin-bottom:.2rem;">{{ __('How many devices?') }}</label>
                        <select wire:model="ctxEstateSize" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;margin-bottom:.6rem;">
                            <option value="">{{ __('Prefer not to say') }}</option>
                            @foreach ($estateSizes as $k => $v) <option value="{{ $k }}">{{ __($v) }}</option> @endforeach
                        </select>

                        <label style="display:block;color:#5A6B82;font-size:.82rem;margin-bottom:.2rem;">{{ __('Your environment') }}</label>
                        <select wire:model="ctxEnvironment" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;margin-bottom:.6rem;">
                            <option value="">{{ __('Prefer not to say') }}</option>
                            @foreach ($environments as $k => $v) <option value="{{ $k }}">{{ __($v) }}</option> @endforeach
                        </select>

                        <label style="display:block;color:#5A6B82;font-size:.82rem;margin-bottom:.2rem;">{{ __('How soon?') }}</label>
                        <select wire:model="ctxUrgency" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;margin-bottom:.6rem;">
                            <option value="">{{ __('Prefer not to say') }}</option>
                            @foreach ($urgencies as $k => $v) <option value="{{ $k }}">{{ __($v) }}</option> @endforeach
                        </select>

                        <div style="display:flex;gap:.5rem;">
                            <div style="flex:1;">
                                <label style="display:block;color:#5A6B82;font-size:.82rem;margin-bottom:.2rem;">{{ __('Country') }}</label>
                                <input type="text" wire:model="ctxRegion" placeholder="{{ __('e.g. CM') }}" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                            </div>
                            <div style="flex:1;">
                                <label style="display:block;color:#5A6B82;font-size:.82rem;margin-bottom:.2rem;">{{ __('Language') }}</label>
                                <input type="text" wire:model="ctxLanguage" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                            </div>
                        </div>
                    </div>

                    {{-- The disclosure boundary, stated where the decision is
                         made rather than buried in a terms page. --}}
                    <p style="color:#5A6B82;font-size:.8rem;line-height:1.5;background:#eef4fb;padding:.7rem;border-radius:8px;margin:1rem 0 .8rem;">
                        {{ __('The provider will see: your summary, your details, anything you entered above, and your email address so they can reply.') }}<br><br>
                        <strong>{{ __('They will not see any of your security data.') }}</strong>
                        {{ __('Your alerts, scan findings, device inventory, incident records and camera footage are never shared with a provider by Obaits.') }}
                    </p>

                    <button wire:click="submit" wire:loading.attr="disabled"
                            style="width:100%;background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.65rem 1.2rem;font-size:.95rem;cursor:pointer;">
                        <span wire:loading.remove wire:target="submit">{{ __('Send request') }}</span>
                        <span wire:loading wire:target="submit">{{ __('Sending...') }}</span>
                    </button>
                @endif
            </div>
        </div>
    @endif
</div>
