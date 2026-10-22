<div style="padding:1.5rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.2rem;">
        <a href="/operator" style="color:#17A2B8;text-decoration:none;font-size:.9rem;">&larr; {{ __('Operator dashboard') }}</a>
        <div>
            <a href="/operator/marketplace/providers" style="color:#17A2B8;text-decoration:none;font-size:.9rem;margin-right:1rem;">{{ __('Providers') }}</a>
            <a href="/operator/logout" style="color:#5A6B82;text-decoration:none;font-size:.9rem;">{{ __('Sign out') }}</a>
        </div>
    </div>

    <h1 style="font-size:1.5rem;color:#1F3864;margin-bottom:.3rem;">{{ __('Referrals') }}</h1>
    <p style="color:#5A6B82;margin-bottom:1.2rem;font-size:.92rem;">
        {{ __('Every introduction the platform has made. Providers do not log in, so this is how we keep the promise that we will notice when one goes quiet.') }}
    </p>

    @if (session('op_ok'))
        <div style="background:#E3F3EC;border:1px solid #C4DECD;color:#0B6E4F;padding:.8rem 1.1rem;border-radius:8px;margin-bottom:1.2rem;">{{ session('op_ok') }}</div>
    @endif

    @if ($stale->count())
        {{-- The number worth acting on. Everything else on this page is context. --}}
        <div style="background:#F7E4E4;border:1px solid #E6C9C9;border-radius:10px;padding:1rem 1.2rem;margin-bottom:1.4rem;">
            <strong style="color:#8B2F2F;">{{ __(':n referral(s) unanswered for more than :d days', ['n' => $stale->count(), 'd' => $staleDays]) }}</strong>
            <p style="color:#8B2F2F;font-size:.9rem;margin:.35rem 0 0;line-height:1.55;">
                {{ __('A provider sitting on leads is what undermines a curated catalogue. Chase them, or reconsider their listing.') }}
            </p>
            <ul style="margin:.6rem 0 0;padding-left:1.2rem;color:#8B2F2F;font-size:.88rem;">
                @foreach ($stale as $s)
                    <li>#{{ $s->number }} {{ $s->provider_name }} — {{ \Carbon\Carbon::parse($s->provider_notified_at)->diffForHumans() }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Per-provider responsiveness --}}
    @if ($byProvider->count())
        <div style="background:#fff;border-radius:12px;padding:1.2rem;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:1.4rem;">
            <h2 style="font-size:1rem;color:#1F3864;margin:0 0 .8rem;">{{ __('By provider') }}</h2>
            <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
                <thead><tr style="text-align:left;color:#8fa3bf;font-size:.78rem;">
                    <th style="padding:.4rem;">{{ __('Provider') }}</th>
                    <th style="padding:.4rem;">{{ __('Referrals') }}</th>
                    <th style="padding:.4rem;">{{ __('Still open') }}</th>
                    <th style="padding:.4rem;">{{ __('Never notified') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($byProvider as $p)
                        <tr style="border-top:1px solid #eef2f6;">
                            <td style="padding:.6rem .4rem;color:#3d4d61;">{{ $p->display_name ?? '—' }}</td>
                            <td style="padding:.6rem .4rem;color:#5A6B82;">{{ $p->total }}</td>
                            <td style="padding:.6rem .4rem;color:{{ $p->open_count > 0 ? '#7A5C00' : '#5A6B82' }};">{{ $p->open_count }}</td>
                            <td style="padding:.6rem .4rem;color:{{ $p->unnotified > 0 ? '#8B2F2F' : '#5A6B82' }};">{{ $p->unnotified }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p style="color:#8fa3bf;font-size:.8rem;margin:.7rem 0 0;">
                {{ __('“Never notified” means the email failed. Those referrals reached nobody and need re-sending.') }}
            </p>
        </div>
    @endif

    <div style="display:flex;gap:.6rem;margin-bottom:1rem;flex-wrap:wrap;">
        <select wire:model.live="statusFilter" style="padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
            <option value="">{{ __('All statuses') }}</option>
            <option value="submitted">{{ __('Open') }}</option>
            <option value="closed">{{ __('Closed') }}</option>
            <option value="cancelled">{{ __('Withdrawn') }}</option>
        </select>
        <select wire:model.live="providerFilter" style="padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
            <option value="">{{ __('All providers') }}</option>
            @foreach ($providers as $p) <option value="{{ $p->slug }}">{{ $p->display_name }}</option> @endforeach
        </select>
    </div>

    <div style="background:#fff;border-radius:12px;padding:1.2rem;box-shadow:0 1px 3px rgba(0,0,0,.08);">
        @if ($requests->isEmpty())
            <p style="color:#5A6B82;margin:0;">{{ __('No referrals yet.') }}</p>
        @else
            <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
                <thead><tr style="text-align:left;color:#8fa3bf;font-size:.78rem;">
                    <th style="padding:.4rem;">#</th>
                    <th style="padding:.4rem;">{{ __('From') }}</th>
                    <th style="padding:.4rem;">{{ __('To') }}</th>
                    <th style="padding:.4rem;">{{ __('Summary') }}</th>
                    <th style="padding:.4rem;">{{ __('Sent') }}</th>
                    <th style="padding:.4rem;">{{ __('Status') }}</th>
                    <th></th>
                </tr></thead>
                <tbody>
                    @foreach ($requests as $r)
                        <tr style="border-top:1px solid #eef2f6;">
                            <td style="padding:.6rem .4rem;color:#8fa3bf;">{{ $r->number }}</td>
                            <td style="padding:.6rem .4rem;color:#3d4d61;">
                                {{ $r->tenant_name ?? __('On-premise') }}
                                @if ($r->installation_id)
                                    <br><small style="color:#8fa3bf;">{{ __('installation') }}</small>
                                @endif
                            </td>
                            <td style="padding:.6rem .4rem;color:#5A6B82;">{{ $r->provider_name }}</td>
                            <td style="padding:.6rem .4rem;color:#3d4d61;">{{ \Illuminate\Support\Str::limit($r->summary, 45) }}</td>
                            <td style="padding:.6rem .4rem;color:#5A6B82;font-size:.85rem;">
                                @if (!$r->provider_notified_at)
                                    <span style="color:#8B2F2F;">{{ __('not sent') }}</span>
                                @else
                                    {{ \Carbon\Carbon::parse($r->provider_notified_at)->diffForHumans() }}
                                @endif
                            </td>
                            <td style="padding:.6rem .4rem;color:#5A6B82;">{{ $r->status }}</td>
                            <td style="padding:.6rem .4rem;text-align:right;">
                                <button wire:click="open('{{ $r->id }}')" style="background:none;border:none;color:#17A2B8;cursor:pointer;">{{ __('View') }}</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($open)
        <div style="background:#fff;border-radius:12px;padding:1.4rem;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-top:1.4rem;">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <h2 style="font-size:1.05rem;color:#1F3864;margin:0;">#{{ $open->number }} — {{ $open->summary }}</h2>
                <button wire:click="close" style="background:none;border:none;color:#8fa3bf;cursor:pointer;">{{ __('Close') }}</button>
            </div>
            <p style="color:#5A6B82;font-size:.9rem;margin:.5rem 0;">
                {{ $open->tenant_name ?? __('On-premise installation') }} → {{ $open->provider_name }} ({{ $open->provider_email }})
            </p>
            <p style="color:#3d4d61;font-size:.92rem;white-space:pre-line;line-height:1.6;">{{ $open->details }}</p>

            @if (!in_array($open->status, ['closed','cancelled']))
                <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid #eef2f6;display:flex;gap:.6rem;align-items:flex-start;flex-wrap:wrap;">
                    <input wire:model="note" placeholder="{{ __('Optional note, recorded') }}"
                           style="flex:1;min-width:240px;padding:.5rem;border:1px solid #dde3ea;border-radius:8px;">
                    <button wire:click="markClosed('{{ $open->id }}')"
                            style="background:#1F3864;color:#fff;border:none;border-radius:8px;padding:.5rem 1.2rem;cursor:pointer;">
                        {{ __('Mark closed') }}
                    </button>
                </div>
                <p style="color:#8fa3bf;font-size:.8rem;margin:.6rem 0 0;">
                    {{ __('Providers do not act in the platform, so a referral that resolved over email stays open until closed here.') }}
                </p>
            @endif
        </div>
    @endif
</div>
