<div>
    <h1 style="font-size:1.6rem;color:#1F3864;margin-bottom:.3rem;">{{ __('Billing') }}</h1>
    <p style="color:#5A6B82;margin-bottom:1.2rem;">
        {{ __('Your plan, what you are using, and your invoice history.') }}
    </p>

    @if (session('billing_ok'))
        <div style="background:#E3F3EC;border:1px solid #C4DECD;color:#0B6E4F;padding:.9rem 1.2rem;border-radius:10px;margin-bottom:1.2rem;">
            {{ session('billing_ok') }}
        </div>
    @endif

    @if ($errors->any())
        <div style="background:#F7E4E4;border:1px solid #E6C9C9;color:#8B2F2F;padding:.9rem 1.2rem;border-radius:10px;margin-bottom:1.2rem;">
            @foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach
        </div>
    @endif

    @if ($notice)
        @php
            [$level, $headline, $detail] = $notice;
            $c = match ($level) {
                'critical' => ['#8B2F2F', '#F7E4E4', '#E6C9C9'],
                'warning'  => ['#7A5C00', '#FFF6DC', '#EBDCAE'],
                default    => ['#1F3864', '#E8EDF8', '#CBD8EC'],
            };
        @endphp
        <div style="background:{{ $c[1] }};border:1px solid {{ $c[2] }};border-radius:10px;padding:1rem 1.2rem;margin-bottom:1.4rem;">
            <strong style="color:{{ $c[0] }};font-size:1rem;">{{ $headline }}</strong>
            <p style="color:{{ $c[0] }};margin:.35rem 0 0;font-size:.92rem;line-height:1.55;">{{ $detail }}</p>
        </div>
    @endif

    @if (!$sub)
        <div style="background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.08);">
            <p style="color:#5A6B82;margin:0;">{{ __('No subscription record was found for your organisation. Please contact support.') }}</p>
        </div>
    @else
        {{-- Plan and usage --}}
        <div style="display:flex;gap:1.2rem;flex-wrap:wrap;margin-bottom:1.4rem;">

            <div style="flex:1;min-width:280px;background:#fff;border-radius:12px;padding:1.4rem;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                <p style="font-size:.78rem;letter-spacing:.12em;text-transform:uppercase;color:#8fa3bf;margin:0 0 .5rem;">{{ __('Your plan') }}</p>
                <p style="font-size:1.4rem;color:#1F3864;font-weight:600;margin:0 0 .2rem;">
                    {{ $sub->tier === 'trial' ? __('Free trial') : ucfirst($sub->tier) }}
                </p>
                @php
                    $statusLabel = match ($sub->status ?? 'trialing') {
                        'active'    => [__('Active'), '#0B6E4F', '#E3F3EC'],
                        'past_due'  => [__('Payment overdue'), '#8B2F2F', '#F7E4E4'],
                        'suspended' => [__('Suspended'), '#8B2F2F', '#F7E4E4'],
                        'cancelled' => [__('Cancelled'), '#5A6B82', '#F0F2F5'],
                        default     => [__('On trial'), '#1F3864', '#E8EDF8'],
                    };
                @endphp
                <span style="display:inline-block;font-size:.78rem;padding:.15rem .6rem;border-radius:999px;color:{{ $statusLabel[1] }};background:{{ $statusLabel[2] }};">
                    {{ $statusLabel[0] }}
                </span>

                @if ($sub->tier === 'trial' && $tenant->trial_ends_at)
                    <p style="color:#5A6B82;font-size:.88rem;margin:.9rem 0 0;">
                        {{ __('Trial ends :date', ['date' => \Carbon\Carbon::parse($tenant->trial_ends_at)->toFormattedDateString()]) }}
                    </p>
                @elseif ($sub->current_period_ends_at)
                    <p style="color:#5A6B82;font-size:.88rem;margin:.9rem 0 0;">
                        {{ __('Current period ends :date', ['date' => \Carbon\Carbon::parse($sub->current_period_ends_at)->toFormattedDateString()]) }}
                    </p>
                @endif
            </div>

            {{-- Devices used against the limit. This is the number enrolment
                 actually checks against, so it must match what refuses you. --}}
            <div style="flex:1;min-width:280px;background:#fff;border-radius:12px;padding:1.4rem;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                <p style="font-size:.78rem;letter-spacing:.12em;text-transform:uppercase;color:#8fa3bf;margin:0 0 .5rem;">{{ __('Devices') }}</p>
                @php
                    $pct = $limit ? min(100, round($devicesUsed / max(1, $limit) * 100)) : 0;
                    $bar = $pct >= 100 ? '#8B2F2F' : ($pct >= 80 ? '#C8871B' : '#17A2B8');
                @endphp
                <p style="font-size:1.4rem;color:#1F3864;font-weight:600;margin:0 0 .1rem;">
                    {{ $devicesUsed }} <span style="color:#8fa3bf;font-weight:400;font-size:1rem;">{{ __('of') }} {{ $limit ?? '—' }}</span>
                </p>
                @if ($limit)
                    <div style="height:7px;background:#eef2f6;border-radius:4px;margin-top:.7rem;overflow:hidden;">
                        <div style="height:7px;background:{{ $bar }};width:{{ $pct }}%;"></div>
                    </div>
                    @if ($devicesUsed >= $limit)
                        <p style="color:#8B2F2F;font-size:.86rem;margin:.7rem 0 0;line-height:1.5;">
                            {{ __('You have used every device slot. New devices cannot be enrolled until you add capacity.') }}
                        </p>
                    @elseif ($pct >= 80)
                        <p style="color:#7A5C00;font-size:.86rem;margin:.7rem 0 0;line-height:1.5;">
                            {{ __('You are close to your limit. :n slot(s) remaining.', ['n' => $limit - $devicesUsed]) }}
                        </p>
                    @else
                        <p style="color:#5A6B82;font-size:.86rem;margin:.7rem 0 0;">
                            {{ __(':n slot(s) remaining.', ['n' => $limit - $devicesUsed]) }}
                        </p>
                    @endif
                @endif
            </div>
        </div>

        @if (!empty($sub->pending_asset_limit))
            {{-- A scheduled reduction must be visible, or a customer would be
                 surprised by a smaller limit at renewal and have no record of
                 having asked for it. --}}
            <div style="background:#E8EDF8;border:1px solid #CBD8EC;border-radius:10px;padding:.9rem 1.2rem;margin-bottom:1.4rem;">
                <strong style="color:#1F3864;font-size:.95rem;">{{ __('A change is scheduled') }}</strong>
                <p style="color:#1F3864;margin:.3rem 0 0;font-size:.9rem;line-height:1.55;">
                    {{ __('Your plan reduces to :n device(s) when this period ends. You keep your current :current until then, and nothing is charged in the meantime.', [
                        'n' => $sub->pending_asset_limit,
                        'current' => $sub->asset_limit,
                    ]) }}
                </p>
            </div>
        @endif

        {{-- Self-service. A tenant blocked mid-rollout at 11pm needs the
             shortest possible path to unblocking themselves, not an email
             that waits for a timezone. --}}
        @php
            $suggest = max(($limit ?? 10), $devicesUsed) + 5;
            $monthlyCents = $suggest * \App\Http\Controllers\SubscriptionCheckoutController::PER_DEVICE_MONTHLY_CENTS;
        @endphp
        <div style="background:#fff;border-radius:12px;padding:1.4rem;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:1.4rem;">
            <h2 style="font-size:1.05rem;color:#1F3864;margin:0 0 .4rem;">
                {{ ($sub->tier ?? '') === 'trial' ? __('Choose a plan') : __('Change your plan or add devices') }}
            </h2>
            <p style="color:#5A6B82;font-size:.92rem;line-height:1.6;margin:0 0 1rem;">
                {{ __('Pay for the devices you need. One payment per period — nothing is charged automatically afterwards, and you choose again when the period ends.') }}
            </p>

            <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                <a href="/portal/checkout?devices={{ $suggest }}&period=monthly"
                   style="background:#17A2B8;color:#fff;padding:.55rem 1.3rem;border-radius:8px;text-decoration:none;font-size:.93rem;">
                    {{ __('Pay monthly') }}
                </a>
                <a href="/portal/checkout?devices={{ $suggest }}&period=yearly"
                   style="background:#fff;color:#1F3864;border:1px solid #1F3864;padding:.55rem 1.3rem;border-radius:8px;text-decoration:none;font-size:.93rem;">
                    {{ __('Pay yearly — two months free') }}
                </a>
            </div>

            <p style="color:#8fa3bf;font-size:.83rem;margin:.9rem 0 0;line-height:1.5;">
                {{ __('You can change the number of devices on the next screen before paying.') }}
                
            </p>
        </div>

        {{-- Invoices --}}
        <div style="background:#fff;border-radius:12px;padding:1.4rem;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:1.4rem;">
            <h2 style="font-size:1.05rem;color:#1F3864;margin:0 0 .8rem;">{{ __('Invoices') }}</h2>
            @if ($invoices->isEmpty())
                <p style="color:#5A6B82;margin:0;font-size:.92rem;">
                    {{ __('No invoices yet. While you are on trial there is nothing to pay.') }}
                </p>
            @else
                <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
                    <thead>
                        <tr style="text-align:left;color:#8fa3bf;font-size:.78rem;">
                            <th style="padding:.5rem .4rem;">#</th>
                            <th style="padding:.5rem .4rem;">{{ __('Issued') }}</th>
                            <th style="padding:.5rem .4rem;">{{ __('Period') }}</th>
                            <th style="padding:.5rem .4rem;">{{ __('Amount') }}</th>
                            <th style="padding:.5rem .4rem;">{{ __('Status') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoices as $inv)
                            <tr style="border-top:1px solid #eef2f6;">
                                <td style="padding:.65rem .4rem;color:#8fa3bf;">{{ $inv->number }}</td>
                                <td style="padding:.65rem .4rem;color:#5A6B82;">{{ $inv->issued_at ? \Carbon\Carbon::parse($inv->issued_at)->toFormattedDateString() : '—' }}</td>
                                <td style="padding:.65rem .4rem;color:#5A6B82;">
                                    {{ $inv->period_start ? \Carbon\Carbon::parse($inv->period_start)->format('d M') : '' }}
                                    @if ($inv->period_end) – {{ \Carbon\Carbon::parse($inv->period_end)->format('d M Y') }} @endif
                                </td>
                                <td style="padding:.65rem .4rem;color:#3d4d61;">${{ number_format($inv->amount_usd_cents / 100, 2) }}</td>
                                <td style="padding:.65rem .4rem;">
                                    @php
                                        $s = match ($inv->status) {
                                            'paid'    => [__('Paid'), '#0B6E4F', '#E3F3EC'],
                                            'overdue' => [__('Overdue'), '#8B2F2F', '#F7E4E4'],
                                            'issued'  => [__('Due'), '#7A5C00', '#FFF6DC'],
                                            default   => [ucfirst($inv->status), '#5A6B82', '#F0F2F5'],
                                        };
                                    @endphp
                                    <span style="font-size:.76rem;padding:.12rem .5rem;border-radius:999px;color:{{ $s[1] }};background:{{ $s[2] }};">{{ $s[0] }}</span>
                                </td>
                                <td style="padding:.65rem .4rem;text-align:right;">
                                    <a href="/portal/invoice/{{ $inv->id }}" target="_blank" rel="noopener"
                                       style="color:#17A2B8;text-decoration:none;font-size:.85rem;">{{ __('View') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Payments --}}
        @if ($payments->isNotEmpty())
            <div style="background:#fff;border-radius:12px;padding:1.4rem;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                <h2 style="font-size:1.05rem;color:#1F3864;margin:0 0 .8rem;">{{ __('Payments') }}</h2>
                <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
                    <tbody>
                        @foreach ($payments as $pay)
                            <tr style="border-top:1px solid #eef2f6;">
                                <td style="padding:.65rem .4rem;color:#5A6B82;">{{ \Carbon\Carbon::parse($pay->created_at)->toFormattedDateString() }}</td>
                                <td style="padding:.65rem .4rem;color:#3d4d61;">${{ number_format($pay->amount_usd_cents / 100, 2) }}</td>
                                <td style="padding:.65rem .4rem;color:#5A6B82;">{{ ucfirst($pay->status) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</div>
