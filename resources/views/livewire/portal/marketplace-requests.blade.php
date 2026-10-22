<div>
    <h1 style="font-size:1.6rem;color:#1F3864;margin-bottom:.3rem;">{{ __('My Requests') }}</h1>
    <p style="color:#5A6B82;margin-bottom:1.2rem;">
        {{ __('Service requests you have sent to providers. Quotation, contracting and delivery are arranged directly with them.') }}
    </p>

    @if ($open)
        {{-- Detail --}}
        @php [$label, $fg, $bg] = \App\Livewire\Portal\MarketplaceRequests::plainStatus($open->status); @endphp
        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.08);max-width:820px;">
            <button wire:click="close" style="background:none;border:none;color:#17A2B8;cursor:pointer;padding:0;font-size:.9rem;">&larr; {{ __('Back to requests') }}</button>

            <div style="display:flex;justify-content:space-between;align-items:start;gap:1rem;margin-top:.8rem;">
                <div>
                    <h2 style="font-size:1.2rem;color:#1F3864;margin:0 0 .2rem;">#{{ $open->number }} — {{ $open->summary }}</h2>
                    <p style="color:#5A6B82;font-size:.9rem;margin:0;">
                        {{ $open->provider_name }}@if ($open->service_title) · {{ $open->service_title }}@endif
                    </p>
                </div>
                <span style="white-space:nowrap;padding:.2rem .6rem;border-radius:999px;font-size:.78rem;color:{{ $fg }};background:{{ $bg }};">{{ $label }}</span>
            </div>

            <p style="color:#3d4d61;line-height:1.7;white-space:pre-line;margin-top:1rem;">{{ $open->details }}</p>

            @php $ctx = json_decode($open->shared_context ?? '{}', true) ?: []; @endphp
            @if ($ctx)
                <div style="margin-top:1rem;padding:.8rem;background:#f7f9fc;border-radius:8px;">
                    <h3 style="font-size:.85rem;color:#1F3864;margin:0 0 .4rem;">{{ __('What you shared') }}</h3>
                    <ul style="margin:0;padding-left:1.1rem;color:#5A6B82;font-size:.85rem;line-height:1.6;">
                        @if (!empty($ctx['technologies'])) <li>{{ __('Technologies') }}: {{ implode(', ', $ctx['technologies']) }}</li> @endif
                        @if (!empty($ctx['estate_size'])) <li>{{ __('Devices') }}: {{ $ctx['estate_size'] }}</li> @endif
                        @if (!empty($ctx['environment'])) <li>{{ __('Environment') }}: {{ $ctx['environment'] }}</li> @endif
                        @if (!empty($ctx['urgency'])) <li>{{ __('Urgency') }}: {{ $ctx['urgency'] }}</li> @endif
                        @if (!empty($ctx['region'])) <li>{{ __('Country') }}: {{ $ctx['region'] }}</li> @endif
                        @if (!empty($ctx['language'])) <li>{{ __('Language') }}: {{ $ctx['language'] }}</li> @endif
                    </ul>
                </div>
            @endif

            {{-- Conversation --}}
            <div style="margin-top:1.2rem;padding-top:1rem;border-top:1px solid #eef2f6;">
                <h3 style="font-size:.95rem;color:#1F3864;margin:0 0 .6rem;">{{ __('Activity') }}</h3>
                <p style="color:#8fa3bf;font-size:.82rem;line-height:1.5;margin:0 0 .7rem;">
                    {{ __(':provider replies to you directly by email. This is a record of what we did, not a conversation.', ['provider' => $open->provider_name]) }}
                </p>

                @forelse ($messages as $m)
                    @php $mine = $m->sender_type === 'customer'; $sys = $m->sender_type === 'system'; @endphp
                    <div style="margin-bottom:.7rem;padding:.7rem .9rem;border-radius:8px;background:{{ $sys ? '#f7f9fc' : ($mine ? '#eef4fb' : '#E3F3EC') }};">
                        <div style="font-size:.75rem;color:#8fa3bf;margin-bottom:.2rem;">
                            {{ $sys ? __('System') : ($mine ? __('You') : $open->provider_name) }} · {{ $m->created_at }}
                        </div>
                        <div style="color:#3d4d61;font-size:.9rem;line-height:1.6;white-space:pre-line;">{{ $m->message }}</div>
                    </div>
                @empty
                    <p style="color:#8fa3bf;font-size:.88rem;">{{ __('Nothing recorded yet.') }}</p>
                @endforelse

                @if ($canAct && !in_array($open->status, ['closed','cancelled']))
                    <button wire:click="cancel('{{ $open->id }}')"
                            wire:confirm="{{ __('Withdraw this request? We will let the provider know.') }}"
                            style="background:#fff;color:#8B2F2F;border:1px solid #e6c9c9;border-radius:8px;padding:.5rem 1.1rem;cursor:pointer;margin-top:.5rem;">{{ __('Withdraw request') }}</button>
                @endif
            </div>
        </div>
    @else
        {{-- List --}}
        <div style="background:#fff;border-radius:12px;padding:1rem;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:1.2rem;">
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                <input type="text" wire:model.live.debounce.400ms="search" placeholder="{{ __('Search by summary or provider...') }}"
                       style="flex:1;min-width:220px;padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">
                <select wire:model.live="statusFilter" style="padding:.55rem;border:1px solid #dde3ea;border-radius:8px;">
                    <option value="">{{ __('All statuses') }}</option>
                    <option value="submitted">{{ __('Sent to provider') }}</option>
                    <option value="closed">{{ __('Closed') }}</option>
                    <option value="cancelled">{{ __('Withdrawn') }}</option>
                </select>
            </div>
        </div>

        @if ($requests->isEmpty())
            <div style="background:#fff;border-radius:12px;padding:2rem;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                <p style="color:#5A6B82;margin:0 0 .8rem;">{{ __('You have not sent any requests yet.') }}</p>
                <a href="/portal/marketplace/services" style="background:#17A2B8;color:#fff;padding:.5rem 1.2rem;border-radius:8px;text-decoration:none;">{{ __('Browse services') }}</a>
            </div>
        @else
            <div style="background:#fff;border-radius:12px;padding:1.2rem;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="text-align:left;color:#8fa3bf;font-size:.8rem;">
                            <th style="padding:.5rem .4rem;">#</th>
                            <th style="padding:.5rem .4rem;">{{ __('Summary') }}</th>
                            <th style="padding:.5rem .4rem;">{{ __('Provider') }}</th>
                            <th style="padding:.5rem .4rem;">{{ __('Sent') }}</th>
                            <th style="padding:.5rem .4rem;">{{ __('Status') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($requests as $r)
                            @php [$label, $fg, $bg] = \App\Livewire\Portal\MarketplaceRequests::plainStatus($r->status); @endphp
                            <tr style="border-top:1px solid #eef2f6;">
                                <td style="padding:.7rem .4rem;color:#8fa3bf;font-size:.9rem;">{{ $r->number }}</td>
                                <td style="padding:.7rem .4rem;color:#3d4d61;">{{ Str::limit($r->summary, 60) }}</td>
                                <td style="padding:.7rem .4rem;color:#5A6B82;font-size:.9rem;">{{ $r->provider_name }}</td>
                                <td style="padding:.7rem .4rem;color:#5A6B82;font-size:.85rem;">{{ $r->submitted_at ? \Carbon\Carbon::parse($r->submitted_at)->diffForHumans() : '—' }}</td>
                                <td style="padding:.7rem .4rem;">
                                    <span style="padding:.15rem .55rem;border-radius:999px;font-size:.75rem;color:{{ $fg }};background:{{ $bg }};">{{ $label }}</span>
                                </td>
                                <td style="padding:.7rem .4rem;text-align:right;">
                                    <button wire:click="open('{{ $r->id }}')" style="background:none;border:none;color:#17A2B8;cursor:pointer;font-size:.9rem;">{{ __('View') }}</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div style="margin-top:1rem;">{{ $requests->links() }}</div>
            </div>
        @endif
    @endif
</div>
