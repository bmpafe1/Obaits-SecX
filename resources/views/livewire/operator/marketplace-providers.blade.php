<div style="padding:1.5rem;">
    {{-- The operator layout is a bare wrapper with no navigation of its own --
         every operator page carries its own header links, so this one does too.
         Without it the page is reachable only by typing the URL. --}}
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.2rem;">
        <a href="/operator" style="color:#17A2B8;text-decoration:none;font-size:.9rem;">&larr; {{ __('Operator dashboard') }}</a>
        <div>
            <a href="/operator/audit" style="color:#17A2B8;text-decoration:none;font-size:.9rem;margin-right:1rem;">{{ __('Audit log') }}</a>
            <a href="/operator/logout" style="color:#5A6B82;text-decoration:none;font-size:.9rem;">{{ __('Sign out') }}</a>
        </div>
    </div>

    <h1 style="font-size:1.5rem;color:#1F3864;margin-bottom:.3rem;">{{ __('Marketplace providers') }}</h1>
    <p style="color:#5A6B82;margin-bottom:1.2rem;">
        {{ __('Providers never log in. Everything about them is entered and maintained here.') }}
    </p>

    @if ($creating || $editingId)
        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.08);max-width:760px;">
            <h2 style="font-size:1.1rem;color:#1F3864;margin:0 0 1rem;">
                {{ $editingId ? __('Edit provider') : __('New provider') }}
            </h2>

            <div style="display:flex;gap:.8rem;flex-wrap:wrap;">
                <div style="flex:1;min-width:240px;">
                    <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Legal name') }}</label>
                    <input wire:model="legal_name" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                    @error('legal_name') <small style="color:#c0392b;">{{ $message }}</small> @enderror
                </div>
                <div style="flex:1;min-width:240px;">
                    <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Display name') }}</label>
                    <input wire:model="display_name" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                    @error('display_name') <small style="color:#c0392b;">{{ $message }}</small> @enderror
                </div>
            </div>

            <div style="margin-top:.7rem;">
                <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Slug') }}</label>
                <input wire:model="slug" placeholder="acme-security" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                <small style="color:#8fa3bf;">{{ __('Used in URLs and to attribute referrals. Cannot be reused.') }}</small>
                @error('slug') <small style="color:#c0392b;display:block;">{{ $message }}</small> @enderror
            </div>

            {{-- Trust status. The one field here with legal consequences. --}}
            <div style="margin-top:1rem;padding:.9rem;background:#f7f9fc;border-radius:8px;">
                <label style="display:block;color:#1F3864;font-size:.9rem;font-weight:600;margin-bottom:.4rem;">{{ __('Trust status') }}</label>
                @foreach ($statuses as $key => $meta)
                    <label style="display:block;margin-bottom:.5rem;cursor:pointer;">
                        <input type="radio" wire:model.live="status" value="{{ $key }}">
                        <strong style="font-size:.9rem;color:#3d4d61;">{{ __($meta[0]) }}</strong><br>
                        <span style="color:#8fa3bf;font-size:.8rem;margin-left:1.3rem;">{{ __($meta[1]) }}</span>
                    </label>
                @endforeach
                @error('status') <small style="color:#c0392b;display:block;">{{ $message }}</small> @enderror

                @if (\App\Livewire\Operator\MarketplaceProviders::needsAuthorisation($status))
                    <label style="display:block;margin-top:.6rem;padding:.6rem;background:#FFF6DC;border-radius:6px;font-size:.85rem;color:#7A5C00;">
                        <input type="checkbox" wire:model="authorisation_confirmed">
                        {{ __('I confirm written authorisation from the manufacturer exists and is on file. Presenting a provider as authorised without it is a misrepresentation made under our name.') }}
                    </label>
                @endif
            </div>

            <div style="display:flex;gap:.8rem;flex-wrap:wrap;margin-top:.7rem;">
                <div style="flex:1;min-width:200px;">
                    <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Lifecycle') }}</label>
                    <select wire:model="lifecycle" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                        @foreach ($lifecycles as $k => $v) <option value="{{ $k }}">{{ __($v) }}</option> @endforeach
                    </select>
                    <small style="color:#8fa3bf;">{{ __('Only active providers appear to customers.') }}</small>
                </div>
                <div style="flex:1;min-width:200px;">
                    <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Typical response time') }}</label>
                    <input wire:model="response_time" placeholder="{{ __('Within 2 business days') }}" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                </div>
            </div>

            <div style="margin-top:.7rem;">
                <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Description') }}</label>
                <textarea wire:model="description" rows="3" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;"></textarea>
                @error('description') <small style="color:#c0392b;">{{ $message }}</small> @enderror
            </div>

            <div style="display:flex;gap:.8rem;flex-wrap:wrap;margin-top:.7rem;">
                <div style="flex:1;min-width:220px;">
                    <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Contact email') }}</label>
                    <input wire:model="contact_email" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                    <small style="color:#8fa3bf;">{{ __('Where referrals are sent.') }}</small>
                    @error('contact_email') <small style="color:#c0392b;display:block;">{{ $message }}</small> @enderror
                </div>
                <div style="flex:1;min-width:220px;">
                    <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Contact phone') }}</label>
                    <input wire:model="contact_phone" placeholder="+237 6XX XXX XXX" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                    @error('contact_phone') <small style="color:#c0392b;display:block;">{{ $message }}</small> @enderror
                </div>
            </div>

            <div style="margin-top:.7rem;">
                <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Website') }}</label>
                <input wire:model="website" placeholder="https://" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
            </div>

            @php
                $groups = [
                    ['technologies', __('Technologies supported'), $technologies],
                    ['languages', __('Languages'), $languages],
                    ['delivery_models', __('Delivery'), $deliveryModels],
                ];
            @endphp
            @foreach ($groups as [$model, $label, $options])
                <div style="margin-top:.9rem;">
                    <label style="display:block;color:#5A6B82;font-size:.85rem;margin-bottom:.3rem;">{{ $label }}</label>
                    <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                        @foreach ($options as $k => $v)
                            <label style="font-size:.82rem;color:#3d4d61;background:#f7f9fc;padding:.25rem .5rem;border-radius:6px;">
                                <input type="checkbox" wire:model="{{ $model }}" value="{{ $k }}"> {{ __($v) }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <div style="margin-top:.9rem;">
                <label style="display:block;color:#5A6B82;font-size:.85rem;margin-bottom:.3rem;">{{ __('Regions served') }}</label>
                <select wire:model="regions" multiple size="6" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                    @foreach ($countries as $code => $name) <option value="{{ $code }}">{{ $name }}</option> @endforeach
                </select>
                <small style="color:#8fa3bf;">{{ __('Hold Ctrl or Cmd to select several.') }}</small>
            </div>

            <div style="display:flex;gap:.6rem;margin-top:1.2rem;">
                <button wire:click="save" style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.6rem 1.4rem;cursor:pointer;">{{ __('Save') }}</button>
                <button wire:click="cancel" style="background:#fff;color:#5A6B82;border:1px solid #dde3ea;border-radius:8px;padding:.6rem 1.4rem;cursor:pointer;">{{ __('Cancel') }}</button>
            </div>
        </div>
    @else
        <div style="display:flex;gap:.6rem;margin-bottom:1rem;">
            <input wire:model.live.debounce.400ms="search" placeholder="{{ __('Search providers...') }}"
                   style="flex:1;padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">
            <button wire:click="create" style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.55rem 1.2rem;cursor:pointer;white-space:nowrap;">{{ __('Add provider') }}</button>
        </div>

        <div style="background:#fff;border-radius:12px;padding:1.2rem;box-shadow:0 1px 3px rgba(0,0,0,.08);">
            @if ($providers->isEmpty())
                <p style="color:#5A6B82;margin:0;">{{ __('No providers yet.') }}</p>
            @else
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="text-align:left;color:#8fa3bf;font-size:.8rem;">
                            <th style="padding:.5rem .4rem;">{{ __('Provider') }}</th>
                            <th style="padding:.5rem .4rem;">{{ __('Status') }}</th>
                            <th style="padding:.5rem .4rem;">{{ __('Lifecycle') }}</th>
                            <th style="padding:.5rem .4rem;">{{ __('Services') }}</th>
                            <th style="padding:.5rem .4rem;">{{ __('Contact') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($providers as $p)
                            <tr style="border-top:1px solid #eef2f6;">
                                <td style="padding:.7rem .4rem;">
                                    <strong style="color:#3d4d61;">{{ $p->display_name }}</strong><br>
                                    <small style="color:#8fa3bf;">{{ $p->slug }}</small>
                                </td>
                                <td style="padding:.7rem .4rem;color:#5A6B82;font-size:.85rem;">{{ __($statuses[$p->status][0] ?? $p->status) }}</td>
                                <td style="padding:.7rem .4rem;">
                                    <span style="font-size:.78rem;padding:.15rem .55rem;border-radius:999px;
                                        color:{{ $p->lifecycle === 'active' ? '#0B6E4F' : ($p->lifecycle === 'suspended' ? '#8B2F2F' : '#7A5C00') }};
                                        background:{{ $p->lifecycle === 'active' ? '#E3F3EC' : ($p->lifecycle === 'suspended' ? '#F7E4E4' : '#FFF6DC') }};">
                                        {{ __($lifecycles[$p->lifecycle] ?? $p->lifecycle) }}
                                    </span>
                                </td>
                                <td style="padding:.7rem .4rem;color:#5A6B82;">
                                    <a href="/operator/marketplace/providers/{{ $p->id }}/services" style="color:#17A2B8;">{{ $p->service_count }}</a>
                                </td>
                                <td style="padding:.7rem .4rem;color:#5A6B82;font-size:.85rem;">{{ $p->contact_email }}</td>
                                <td style="padding:.7rem .4rem;text-align:right;">
                                    <button wire:click="edit('{{ $p->id }}')" style="background:none;border:none;color:#17A2B8;cursor:pointer;">{{ __('Edit') }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif
</div>
