<div style="padding:1.5rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.2rem;">
        <a href="/operator" style="color:#17A2B8;text-decoration:none;font-size:.9rem;">&larr; {{ __('Operator dashboard') }}</a>
        <div>
            <a href="/operator/marketplace/providers" style="color:#17A2B8;text-decoration:none;font-size:.9rem;margin-right:1rem;">{{ __('Marketplace') }}</a>
            <a href="/operator/logout" style="color:#5A6B82;text-decoration:none;font-size:.9rem;">{{ __('Sign out') }}</a>
        </div>
    </div>

    <h1 style="font-size:1.5rem;color:#1F3864;margin-bottom:.3rem;">{{ __('Training catalogue') }}</h1>
    <p style="color:#5A6B82;margin-bottom:1.2rem;">{{ __('Third-party courses shown to tenants under our brand. Curated here; tenants only browse.') }}</p>

    @if ($creating || $editingId)
        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.08);max-width:760px;">
            <h2 style="font-size:1.1rem;color:#1F3864;margin:0 0 1rem;">{{ $editingId ? __('Edit training') : __('New training') }}</h2>

            <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Title') }}</label>
            <input wire:model="title" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
            @error('title') <small style="color:#c0392b;">{{ $message }}</small> @enderror

            <div style="display:flex;gap:.8rem;flex-wrap:wrap;margin-top:.7rem;">
                <div style="flex:1;min-width:220px;">
                    <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Category') }}</label>
                    <select wire:model="category" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                        @foreach ($categories as $k => $v) <option value="{{ $k }}">{{ __($v) }}</option> @endforeach
                    </select>
                </div>
                <div style="flex:1;min-width:220px;">
                    <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Institution') }}</label>
                    <input wire:model="institution" placeholder="SANS, Coursera, ..." style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                </div>
            </div>

            <label style="display:block;color:#5A6B82;font-size:.85rem;margin-top:.7rem;">{{ __('Description') }}</label>
            <textarea wire:model="description" rows="3" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;"></textarea>
            @error('description') <small style="color:#c0392b;">{{ $message }}</small> @enderror

            <label style="display:block;color:#5A6B82;font-size:.85rem;margin-top:.7rem;">{{ __('URL') }}</label>
            <input wire:model="url" placeholder="https://" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
            @error('url') <small style="color:#c0392b;">{{ $message }}</small> @enderror

            <div style="margin-top:.9rem;padding:.9rem;background:#f7f9fc;border-radius:8px;">
                <label style="display:block;color:#1F3864;font-size:.9rem;font-weight:600;margin-bottom:.3rem;">{{ __('Languages of instruction') }}</label>
                <p style="color:#8fa3bf;font-size:.8rem;margin:0 0 .5rem;">{{ __('Tenants see training in their own languages by default, so a listing with none is invisible to everyone.') }}</p>
                <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                    @foreach ($languageOptions as $k => $v)
                        <label style="font-size:.82rem;color:#3d4d61;background:#fff;padding:.25rem .5rem;border-radius:6px;border:1px solid #e6e9ee;">
                            <input type="checkbox" wire:model="languages" value="{{ $k }}"> {{ __($v) }}
                        </label>
                    @endforeach
                </div>
                @error('languages') <small style="color:#c0392b;display:block;margin-top:.3rem;">{{ $message }}</small> @enderror
            </div>

            <div style="display:flex;gap:.8rem;flex-wrap:wrap;margin-top:.7rem;">
                <div style="flex:1;min-width:140px;">
                    <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Cost') }}</label>
                    <input wire:model="cost" placeholder="0" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                    <small style="color:#8fa3bf;">{{ __('0 = free. Blank = see website.') }}</small>
                    @error('cost') <small style="color:#c0392b;display:block;">{{ $message }}</small> @enderror
                </div>
                <div style="flex:1;min-width:120px;">
                    <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Currency') }}</label>
                    <select wire:model="currency" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                        @foreach ($currencies as $c) <option value="{{ $c }}">{{ $c }}</option> @endforeach
                    </select>
                </div>
                <div style="flex:1;min-width:140px;">
                    <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Duration') }}</label>
                    <input wire:model="duration" placeholder="2 days" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                </div>
                <div style="flex:1;min-width:140px;">
                    <label style="display:block;color:#5A6B82;font-size:.85rem;">{{ __('Level') }}</label>
                    <select wire:model="level" style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                        <option value="">{{ __('Not specified') }}</option>
                        @foreach ($levels as $k => $v) <option value="{{ $k }}">{{ __($v) }}</option> @endforeach
                    </select>
                </div>
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
        <div style="display:flex;gap:.6rem;margin-bottom:1rem;flex-wrap:wrap;">
            <input wire:model.live.debounce.400ms="search" placeholder="{{ __('Search...') }}" style="flex:1;min-width:200px;padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">
            <select wire:model.live="categoryFilter" style="padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">
                <option value="">{{ __('All categories') }}</option>
                @foreach ($categories as $k => $v) <option value="{{ $k }}">{{ __($v) }}</option> @endforeach
            </select>
            <button wire:click="create" style="background:#17A2B8;color:#fff;border:none;border-radius:8px;padding:.55rem 1.2rem;cursor:pointer;white-space:nowrap;">{{ __('Add training') }}</button>
        </div>

        <div style="background:#fff;border-radius:12px;padding:1.2rem;box-shadow:0 1px 3px rgba(0,0,0,.08);">
            @if ($trainings->isEmpty())
                <p style="color:#5A6B82;margin:0;">{{ __('No training listed yet.') }}</p>
            @else
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="text-align:left;color:#8fa3bf;font-size:.8rem;">
                            <th style="padding:.5rem .4rem;">{{ __('Title') }}</th>
                            <th style="padding:.5rem .4rem;">{{ __('Category') }}</th>
                            <th style="padding:.5rem .4rem;">{{ __('Languages') }}</th>
                            <th style="padding:.5rem .4rem;">{{ __('Cost') }}</th>
                            <th style="padding:.5rem .4rem;">{{ __('Visibility') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($trainings as $t)
                            @php $langs = json_decode($t->languages ?? '[]', true) ?: []; @endphp
                            <tr style="border-top:1px solid #eef2f6;">
                                <td style="padding:.7rem .4rem;color:#3d4d61;">
                                    {{ $t->title }}
                                    @if ($t->institution) <br><small style="color:#8fa3bf;">{{ $t->institution }}</small> @endif
                                </td>
                                <td style="padding:.7rem .4rem;color:#5A6B82;font-size:.85rem;">{{ __($categories[$t->category] ?? $t->category) }}</td>
                                <td style="padding:.7rem .4rem;color:#5A6B82;font-size:.82rem;">{{ strtoupper(implode(', ', $langs)) }}</td>
                                <td style="padding:.7rem .4rem;color:#5A6B82;font-size:.85rem;">
                                    @if ($t->cost === null) {{ __('See website') }}
                                    @elseif ((float) $t->cost === 0.0) {{ __('Free') }}
                                    @else {{ $t->currency }} {{ number_format((float) $t->cost, 2) }} @endif
                                </td>
                                <td style="padding:.7rem .4rem;">
                                    <span style="font-size:.78rem;padding:.15rem .55rem;border-radius:999px;
                                        color:{{ $t->status === 'published' ? '#0B6E4F' : ($t->status === 'suspended' ? '#8B2F2F' : '#5A6B82') }};
                                        background:{{ $t->status === 'published' ? '#E3F3EC' : ($t->status === 'suspended' ? '#F7E4E4' : '#F0F2F5') }};">
                                        {{ __($statuses[$t->status][0] ?? $t->status) }}
                                    </span>
                                </td>
                                <td style="padding:.7rem .4rem;text-align:right;">
                                    <button wire:click="edit('{{ $t->id }}')" style="background:none;border:none;color:#17A2B8;cursor:pointer;">{{ __('Edit') }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif
</div>
