<div style="max-width:900px;margin:2rem auto;padding:0 1rem;">
    <a href="/operator" style="color:#17A2B8;text-decoration:none;font-size:.9rem;">&larr; {{ __('All tenants') }}</a>

    @if (session('op_ok'))
        <div style="background:#e6ffe6;padding:.8rem;border-radius:6px;margin:1rem 0;">{{ session('op_ok') }}</div>
    @endif
    @if (session('op_error'))
        <div style="background:#fdecea;color:#c0392b;padding:.8rem;border-radius:6px;margin:1rem 0;">{{ session('op_error') }}</div>
    @endif




    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.6rem;">
        <h1 style="color:#0B2545;margin:0;">{{ $tenant->organization_name }}</h1>
        <span style="background:{{ $tenant->status === 'suspended' ? '#fdecea' : '#eef7f0' }};color:{{ $tenant->status === 'suspended' ? '#c0392b' : '#1e824c' }};border-radius:6px;padding:.25rem .7rem;font-size:.85rem;">
            {{ $tenant->status }}
        </span>
    </div>
    <p style="color:#5A6B82;">{{ $tenant->primary_domain }} · {{ $tenant->slug }}</p>

    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin:1rem 0;">
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:1rem;text-align:center;">
            @foreach (['users'=>__('Users'),'findings'=>__('Findings'),'documents'=>__('Documents'),'scans'=>__('Scans'),'frameworks'=>__('Frameworks')] as $k => $label)
                <div>
                    <div style="font-size:1.6rem;font-weight:700;color:#0B2545;">{{ $stats[$k] }}</div>
                    <div style="color:#5A6B82;font-size:.78rem;">{{ $label }}</div>
                </div>
            @endforeach
        </div>
    {{-- Device limit. A customer blocked at their limit can normally pay and
         unblock themselves; not always -- a failed card, a mid-rollout at
         midnight, an agreed trial extension. This is the operator remedy, and
         it is RECORDED rather than being a database query nobody sees.

         COUNT only, never hostnames: this page shows metadata about a tenant,
         not their data. --}}
    @if ($subscription)
        <div style="background:#fff;border-radius:10px;padding:1.2rem;margin:1.2rem 0;box-shadow:0 1px 3px rgba(0,0,0,.08);">
            <h2 style="font-size:1.05rem;color:#1F3864;margin:0 0 .7rem;">{{ __('Plan and device limit') }}</h2>

            <div style="display:flex;gap:2rem;flex-wrap:wrap;margin-bottom:1rem;font-size:.92rem;">
                <div><span style="color:#8fa3bf;">{{ __('Tier') }}</span><br><strong>{{ $subscription->tier }}</strong></div>
                <div><span style="color:#8fa3bf;">{{ __('Status') }}</span><br><strong>{{ $subscription->status ?? '—' }}</strong></div>
                <div><span style="color:#8fa3bf;">{{ __('Devices') }}</span><br>
                    <strong style="color:{{ $devicesUsed >= $subscription->asset_limit ? '#c0392b' : '#1F3864' }};">
                        {{ $devicesUsed }} / {{ $subscription->asset_limit }}
                    </strong>
                </div>
                <div><span style="color:#8fa3bf;">{{ __('Period ends') }}</span><br><strong>{{ $subscription->current_period_ends_at ?? '—' }}</strong></div>
            </div>

            <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-start;border-top:1px solid #eef2f6;padding-top:1rem;">
                <div>
                    <label style="display:block;font-size:.82rem;color:#5A6B82;">{{ __('New limit') }}</label>
                    <input type="number" wire:model="newLimit" min="1" style="width:110px;padding:.5rem;border:1px solid #dde3ea;border-radius:6px;">
                    @error('newLimit') <small style="color:#c0392b;display:block;">{{ $message }}</small> @enderror
                </div>
                <div style="flex:1;min-width:240px;">
                    <label style="display:block;font-size:.82rem;color:#5A6B82;">{{ __('Reason (recorded)') }}</label>
                    <input type="text" wire:model="limitReason" placeholder="{{ __('e.g. agreed extension while their card is replaced') }}"
                           style="width:100%;padding:.5rem;border:1px solid #dde3ea;border-radius:6px;">
                    @error('limitReason') <small style="color:#c0392b;display:block;">{{ $message }}</small> @enderror
                </div>
                <div style="padding-top:1.3rem;">
                    <button wire:click="changeLimit" style="background:#1F3864;color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;cursor:pointer;">{{ __('Set limit') }}</button>
                </div>
            </div>
            <p style="color:#8fa3bf;font-size:.8rem;margin:.7rem 0 0;">
                {{ __('Does not change their tier or billing period — this is a support action, not a sale. The tenant is notified.') }}
            </p>

            @if (!empty($subPayments) && count($subPayments))
                <div style="margin-top:1.4rem;padding-top:1.1rem;border-top:1px solid #eef2f6;">
                    <h3 style="font-size:.95rem;color:#1F3864;margin:0 0 .7rem;">{{ __('Subscription payments') }}</h3>
                    <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
                        <tbody>
                            @foreach ($subPayments as $sp)
                                <tr style="border-top:1px solid #eef2f6;">
                                    <td style="padding:.55rem .4rem;color:#5A6B82;">{{ \Carbon\Carbon::parse($sp->created_at)->toFormattedDateString() }}</td>
                                    <td style="padding:.55rem .4rem;color:#8fa3bf;">@if ($sp->invoice_number) #{{ $sp->invoice_number }} @endif</td>
                                    <td style="padding:.55rem .4rem;color:#3d4d61;">${{ number_format($sp->amount_usd_cents / 100, 2) }}</td>
                                    <td style="padding:.55rem .4rem;">
                                        @php $c = match($sp->status) {
                                            'success'  => ['#0B6E4F','#E3F3EC'],
                                            'refunded' => ['#7A5C00','#FFF6DC'],
                                            'failure'  => ['#8B2F2F','#F7E4E4'],
                                            default    => ['#5A6B82','#F0F2F5'],
                                        }; @endphp
                                        <span style="font-size:.76rem;padding:.12rem .5rem;border-radius:999px;color:{{ $c[0] }};background:{{ $c[1] }};">{{ ucfirst($sp->status) }}</span>
                                    </td>
                                    <td style="padding:.55rem .4rem;text-align:right;">
                                        @if ($sp->status === 'success')
                                            <button wire:click="refundSubscriptionPayment('{{ $sp->id }}')"
                                                    wire:confirm="{{ __('Refund this payment at Flutterwave? This cannot be undone.') }}"
                                                    style="background:none;border:none;color:#8B2F2F;cursor:pointer;font-size:.85rem;">
                                                {{ __('Refund') }}
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p style="color:#8fa3bf;font-size:.78rem;margin:.7rem 0 0;line-height:1.5;">
                        {{ __('Refunding rolls the device limit back where it can be done without leaving them over it. If they have enrolled more devices since, the limit is left alone and you are told.') }}
                    </p>
                </div>
            @endif
        </div>
    @endif

    </div>

    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin:1rem 0;">
        <h3 style="margin-top:0;">{{ __('Actions') }}</h3>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
            @if ($tenant->status === 'suspended')
                <button wire:click="reactivate" style="background:#1e824c;color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;cursor:pointer;">{{ __('Reactivate') }}</button>
            @else
                <button wire:click="suspend" wire:confirm="{{ __('Suspend this tenant? They will lose portal access.') }}" style="background:#c0392b;color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;cursor:pointer;">{{ __('Suspend') }}</button>
            @endif
            <a href="/operator/tenant/{{ $tenant->id }}/data" style="background:#0B2545;color:#fff;border-radius:8px;padding:.5rem 1.2rem;text-decoration:none;">{{ __('View data (recorded)') }}</a>
        </div>
    </div>

    @if ($payments->isNotEmpty())
    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);margin:1rem 0;">
        <h3 style="margin-top:0;">{{ __('Backup storage payments') }}</h3>
        <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
            <thead>
                <tr style="text-align:left;color:#8a97a8;font-size:.78rem;">
                    <th style="padding:.3rem 0;">{{ __('Date') }}</th>
                    <th>{{ __('Amount') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Reference') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($payments as $p)
                    <tr style="border-top:1px solid #f2f2f2;">
                        <td style="padding:.5rem 0;">{{ $p->created_at }}</td>
                        <td>${{ number_format($p->amount_usd_cents / 100, 2) }}</td>
                        <td>
                            @if ($p->status === 'success')
                                <span style="color:#1e824c;">{{ __('Success') }}</span>
                            @elseif ($p->status === 'refunded')
                                <span style="color:#8a97a8;">{{ __('Refunded') }}</span>
                            @elseif ($p->status === 'pending')
                                <span style="color:#b8860b;">{{ __('Pending') }}</span>
                            @else
                                <span style="color:#c0392b;">{{ $p->status }}</span>
                            @endif
                        </td>
                        <td style="font-family:monospace;font-size:.8rem;color:#5A6B82;">{{ $p->provider_reference }}</td>
                        <td>
                            @if ($p->status === 'success')
                                <button wire:click="refundPayment('{{ $p->id }}')"
                                    wire:confirm="{{ __('Refund this payment and revert the tenant\'s storage to the free tier? This cannot be undone.') }}"
                                    wire:loading.attr="disabled"
                                    style="background:none;border:1px solid #c0392b;color:#c0392b;border-radius:6px;padding:.3rem .8rem;cursor:pointer;font-size:.8rem;">
                                    {{ __('Refund') }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p style="color:#8a97a8;font-size:.78rem;margin-top:.8rem;">
            {{ __('Refund requests come in via email to :email. Process them here.', ['email' => config('platform.admin_email')]) }}
        </p>
    </div>
    @endif

    @if ($access->isNotEmpty())
    <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h3 style="margin-top:0;">{{ __('Recent operator access to this tenant') }}</h3>
        @foreach ($access as $a)
            <div style="border-top:1px solid #f2f2f2;padding:.5rem 0;font-size:.85rem;">
                <span style="color:#5A6B82;">{{ $a->created_at }}</span> ·
                {{ $a->actor }} · <strong>{{ $a->action }}</strong>
                @if ($a->reason)<span style="color:#5A6B82;"> — {{ $a->reason }}</span>@endif
            </div>
        @endforeach
    </div>
    @endif
</div>