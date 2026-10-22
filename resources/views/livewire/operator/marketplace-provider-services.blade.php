<div style="padding:1.5rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.2rem;">
        <a href="/operator/marketplace/providers" style="color:#17A2B8;text-decoration:none;font-size:.9rem;">&larr; {{ __('All providers') }}</a>
        <div>
            <a href="/operator/audit" style="color:#17A2B8;text-decoration:none;font-size:.9rem;margin-right:1rem;">{{ __('Audit log') }}</a>
            <a href="/operator/logout" style="color:#5A6B82;text-decoration:none;font-size:.9rem;">{{ __('Sign out') }}</a>
        </div>
    </div>

    @if (!$provider)
        <p style="color:#5A6B82;">{{ __('That provider no longer exists.') }}</p>
    @else
        <h1 style="font-size:1.5rem;color:#1F3864;margin-bottom:.2rem;">{{ $provider->display_name }}</h1>
        <p style="color:#5A6B82;margin-bottom:1.2rem;">{{ __('Service listings') }}</p>

        @if ($provider->lifecycle !== 'active')
            {{-- Say it plainly rather than leaving the operator to wonder why a
                 published service is invisible. --}}
            <p style="background:#FFF6DC;color:#7A5C00;padding:.7rem .9rem;border-radius:8px;font-size:.9rem;margin-bottom:1rem;">
                {{ __('This provider is :state, so none of these services reach customers regardless of their own status.', ['state' => __($provider->lifecycle)]) }}
            </p>
        @endif

        @if ($creating || $editingId)
            <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.08);max-width:760px;">
                <h2 style="font-size:1.1rem;color:#1F3864;margin:0 0 1rem;">{{ $editingId ? __('Edit service') : __('New service') }}</h2>

                <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Title') }}</label>
                <input wire:model="title" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                @error('title') <small style="color:#c0392b;">{{ $message }}</small> @enderror

                <div style="display:flex;gap:.8rem;flex-wrap:wrap;margin-top:.7rem;">
                    <div style="flex:1;min-width:220px;">
                        <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Category') }}</label>
                        <select wire:model="category" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                            <option value="">{{ __('Choose one') }}</option>
                            @foreach ($categories as $k => $v) <option value="{{ $k }}">{{ __($v) }}</option> @endforeach
                        </select>
                        @error('category') <small style="color:#c0392b;">{{ $message }}</small> @enderror
                    </div>
                    <div style="flex:1;min-width:220px;">
                        <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Technology') }}</label>
                        <select wire:model="technology" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                            <option value="">{{ __('Not technology-specific') }}</option>
                            @foreach ($technologies as $k => $v) <option value="{{ $k }}">{{ $v }}</option> @endforeach
                        </select>
                    </div>
                </div>

                <label style="display:block;color:#5A6B82;font-size:.85rem;margin-top:.7rem;">{{ __('Description') }}</label>
                <textarea wire:model="description" rows="4" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;"></textarea>
                @error('description') <small style="color:#c0392b;">{{ $message }}</small> @enderror

                <div style="display:flex;gap:.8rem;flex-wrap:wrap;margin-top:.7rem;">
                    <div style="flex:1;min-width:220px;">
                        <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Pricing') }}</label>
                        <input wire:model="pricing" placeholder="{{ __('Leave blank for “Request a quote”') }}" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                    </div>
                    <div style="flex:1;min-width:220px;">
                        <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Response time') }}</label>
                        <input wire:model="response_time" placeholder="{{ __('Within 2 business days') }}" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                    </div>
                </div>

                <label style="display:block;color:#5A6B82;font-size:.85rem;margin-top:.7rem;">{{ __('Prerequisites') }}</label>
                <textarea wire:model="prerequisites" rows="2" placeholder="{{ __('Anything the customer needs in place first') }}" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;"></textarea>

                <div style="margin-top:.9rem;">
                    <label style="display:block;color:#5A6B82;font-size:.85rem;margin-bottom:.3rem;">{{ __('Delivery') }}</label>
                    <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                        @foreach ($deliveryModels as $k => $v)
                            <label style="font-size:.82rem;color:#3d4d61;background:#f7f9fc;padding:.25rem .5rem;border-radius:6px;">
                                <input type="checkbox" wire:model="delivery_models" value="{{ $k }}"> {{ __($v) }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <div style="margin-top:.9rem;">
                    <label style="display:block;color:#5A6B82;font-size:.85rem;margin-bottom:.3rem;">{{ __('Regions') }}</label>
                    <select wire:model="regions" multiple size="5" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                        @foreach ($countries as $code => $name) <option value="{{ $code }}">{{ $name }}</option> @endforeach
                    </select>
                </div>

                <div style="margin-top:1rem;padding:.9rem;background:#f7f9fc;border-radius:8px;">
                    <label style="display:block;color:#1F3864;font-size:.9rem;font-weight:600;margin-bottom:.4rem;">{{ __('Visibility') }}</label>
                    @foreach ($statuses as $key => $meta)
                        <label style="display:block;margin-bottom:.4rem;cursor:pointer;">
                            <input type="radio" wire:model="status" value="{{ $key }}">
                            <strong style="font-size:.9rem;color:#3d4d61;">{{ __($meta[0]) }}</strong>
                            <span style="color:#8fa3bf;font-size:.8rem;">— {{ __($meta[1]) }}</span>
                        </label>
                    @endforeach
                </div>

                <div style="display:flex;gap:.6rem;margin-top:1.2rem;">
                    <button wire:click="save" style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.6rem 1.4rem;cursor:pointer;">{{ __('Save') }}</button>
                    <button wire:click="cancel" style="background:#fff;color:#5A6B82;border:1px solid #dde3ea;border-radius:8px;padding:.6rem 1.4rem;cursor:pointer;">{{ __('Cancel') }}</button>
                </div>
            </div>
        @else
            <button wire:click="create" style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.55rem 1.2rem;cursor:pointer;margin-bottom:1rem;">{{ __('Add service') }}</button>

            <div style="background:#fff;border-radius:12px;padding:1.2rem;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                @if ($services->isEmpty())
                    <p style="color:#5A6B82;margin:0;">{{ __('No services listed for this provider yet.') }}</p>
                @else
                    <table style="width:100%;border-collapse:collapse;">
                        <thead>
                            <tr style="text-align:left;color:#8fa3bf;font-size:.8rem;">
                                <th style="padding:.5rem .4rem;">{{ __('Service') }}</th>
                                <th style="padding:.5rem .4rem;">{{ __('Category') }}</th>
                                <th style="padding:.5rem .4rem;">{{ __('Technology') }}</th>
                                <th style="padding:.5rem .4rem;">{{ __('Visibility') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($services as $s)
                                <tr style="border-top:1px solid #eef2f6;">
                                    <td style="padding:.7rem .4rem;color:#3d4d61;">{{ $s->title }}</td>
                                    <td style="padding:.7rem .4rem;color:#5A6B82;font-size:.85rem;">{{ __($categories[$s->category] ?? $s->category) }}</td>
                                    <td style="padding:.7rem .4rem;color:#5A6B82;font-size:.85rem;">{{ $technologies[$s->technology] ?? '—' }}</td>
                                    <td style="padding:.7rem .4rem;">
                                        <span style="font-size:.78rem;padding:.15rem .55rem;border-radius:999px;
                                            color:{{ $s->status === 'published' ? '#0B6E4F' : ($s->status === 'suspended' ? '#8B2F2F' : '#5A6B82') }};
                                            background:{{ $s->status === 'published' ? '#E3F3EC' : ($s->status === 'suspended' ? '#F7E4E4' : '#F0F2F5') }};">
                                            {{ __($statuses[$s->status][0] ?? $s->status) }}
                                        </span>
                                    </td>
                                    <td style="padding:.7rem .4rem;text-align:right;">
                                        <button wire:click="edit('{{ $s->id }}')" style="background:none;border:none;color:#17A2B8;cursor:pointer;">{{ __('Edit') }}</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif
    @endif
</div>
