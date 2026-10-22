<?php

namespace App\Livewire\Operator;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use App\Support\OperatorAudit;
use Carbon\Carbon;

/**
 * Every referral the platform has made, across all tenants.
 *
 * WHY THIS MATTERS COMMERCIALLY: referrals ARE the marketplace product, and
 * until now they were visible only as CC'd emails in an inbox. There was no way
 * to see which providers receive leads, which respond, and which quietly ignore
 * them -- and a provider ignoring leads is the single thing most damaging to a
 * curated catalogue's credibility.
 *
 * The oversight this replaces was the only thing making the no-portal model
 * defensible: we promised customers we would notice and intervene. An inbox is
 * a poor instrument for that promise.
 *
 * SHOWS BOTH SIDES, because this is the operator view and the operator is the
 * party to both relationships. That is not a leak: the tenant-facing and
 * provider-facing screens remain scoped, and this one is behind operator auth
 * with its own audit trail.
 */
#[Layout('components.layouts.operator')]
class MarketplaceRequests extends Component
{
    public string $statusFilter = '';
    public string $providerFilter = '';
    public ?string $openId = null;
    public string $note = '';

    /** Days after which an unanswered referral is treated as stalled. */
    public const STALE_DAYS = 5;

    public function open(string $id): void
    {
        $this->openId = $id;
        $this->note = '';
    }

    public function close(): void
    {
        $this->openId = null;
    }

    /**
     * Mark a referral closed by hand. Providers do not act in-platform, so a
     * referral that resolved over email would otherwise stay open forever.
     */
    public function markClosed(string $id): void
    {
        $r = DB::table('marketplace_requests')->where('id', $id)->first();
        if (!$r || in_array($r->status, ['closed', 'cancelled'], true)) { return; }

        DB::table('marketplace_requests')->where('id', $id)->update([
            'status' => 'closed', 'closed_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('marketplace_request_messages')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'request_id' => $id,
            'sender_type' => 'system',
            'message' => __('Closed by Obaits.') . ($this->note ? ' ' . $this->note : ''),
            'created_at' => now(),
        ]);

        OperatorAudit::log('operator.marketplace_request_closed', [
            'request' => $id, 'number' => $r->number, 'note' => $this->note,
        ]);

        $this->note = '';
        session()->flash('op_ok', __('Request closed.'));
    }

    public function render()
    {
        $requests = DB::table('marketplace_requests as r')
            ->leftJoin('marketplace_providers as p', 'p.id', '=', 'r.provider_id')
            ->leftJoin('tenants as t', 't.id', '=', 'r.tenant_id')
            ->leftJoin('marketplace_services as s', 's.id', '=', 'r.service_id')
            ->when($this->statusFilter !== '', fn ($q) => $q->where('r.status', $this->statusFilter))
            ->when($this->providerFilter !== '', fn ($q) => $q->where('p.slug', $this->providerFilter))
            ->orderByDesc('r.created_at')
            ->limit(100)
            ->get([
                'r.id', 'r.number', 'r.summary', 'r.status', 'r.priority',
                'r.submitted_at', 'r.provider_notified_at', 'r.responded_at', 'r.closed_at',
                'r.requester_email', 'r.installation_id',
                'p.display_name as provider_name', 'p.slug as provider_slug', 'p.contact_email as provider_email',
                't.organization_name as tenant_name',
                's.title as service_title',
            ]);

        // Which providers are letting referrals go cold. This is the number
        // worth acting on -- everything else is context.
        $stale = $requests->filter(function ($r) {
            return in_array($r->status, ['submitted'], true)
                && $r->provider_notified_at
                && Carbon::parse($r->provider_notified_at)->lt(now()->subDays(self::STALE_DAYS));
        });

        $byProvider = DB::table('marketplace_requests as r')
            ->leftJoin('marketplace_providers as p', 'p.id', '=', 'r.provider_id')
            ->selectRaw("p.display_name, count(*) as total,
                sum(case when r.status = 'submitted' then 1 else 0 end) as open_count,
                sum(case when r.provider_notified_at is null then 1 else 0 end) as unnotified")
            ->groupBy('p.display_name')->orderByDesc('total')->get();

        $open = $this->openId
            ? DB::table('marketplace_requests as r')
                ->leftJoin('marketplace_providers as p', 'p.id', '=', 'r.provider_id')
                ->leftJoin('tenants as t', 't.id', '=', 'r.tenant_id')
                ->where('r.id', $this->openId)
                ->first(['r.*', 'p.display_name as provider_name', 'p.contact_email as provider_email',
                         't.organization_name as tenant_name'])
            : null;

        return view('livewire.operator.marketplace-requests', [
            'requests' => $requests,
            'stale' => $stale,
            'byProvider' => $byProvider,
            'open' => $open,
            'providers' => DB::table('marketplace_providers')->orderBy('display_name')->get(['slug', 'display_name']),
            'staleDays' => self::STALE_DAYS,
        ]);
    }
}
