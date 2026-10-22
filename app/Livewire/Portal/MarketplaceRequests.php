<?php

namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Perm;
use App\Support\Audit;

/**
 * The customer's own Marketplace requests.
 *
 * TENANT-SCOPED, ALWAYS. marketplace_requests carries BOTH tenant_id and
 * provider_id, and this is the CUSTOMER side of that table. Every query here
 * filters on tenant_id from the session and must never filter on provider_id --
 * a path that confuses the two leaks across the wrong boundary. The provider
 * portal is the mirror image and filters the other way.
 *
 * Status is shown in plain language. The schema carries eleven states because
 * the workflow needs them; a customer wants to know whether anyone has picked
 * their request up, not to interpret 'routed' versus 'provider_notified'.
 */
#[Layout('components.layouts.portal')]
class MarketplaceRequests extends Component
{
    use WithPagination;

    public string $tenantId;
    public string $search = '';
    public string $statusFilter = '';

    public ?string $openId = null;

    public function updatingSearch() { $this->resetPage(); }
    public function updatingStatusFilter() { $this->resetPage(); }

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { return redirect('/login'); }
    }

    /**
     * What a customer actually wants to know, rather than the internal state.
     * Several distinct workflow states collapse into one customer-facing
     * meaning on purpose.
     */
    /**
     * SIMPLIFIED 2026-08-27. Providers do not act in-platform -- they reply to
     * the customer directly by email -- so the platform can only honestly
     * report what it observes: that a request was sent, and that it was closed
     * or withdrawn. States like 'accepted' or 'in_progress' would be guesses
     * dressed as facts.
     */
    public static function plainStatus(string $status): array
    {
        return match ($status) {
            'draft'     => [__('Draft'), '#5A6B82', '#F0F2F5'],
            'submitted' => [__('Sent to provider'), '#1F3864', '#E8EDF8'],
            'closed'    => [__('Closed'), '#5A6B82', '#F0F2F5'],
            'cancelled' => [__('You withdrew this'), '#5A6B82', '#F0F2F5'],
            default     => [$status, '#5A6B82', '#F0F2F5'],
        };
    }

    public function open(string $id): void
    {
        // Ownership check on every open -- never trust the id from the client.
        $exists = DB::table('marketplace_requests')
            ->where('id', $id)->where('tenant_id', $this->tenantId)->exists();

        $this->openId = $exists ? $id : null;
    }

    public function close(): void
    {
        $this->openId = null;
    }

    public function cancel(string $id): void
    {
        Perm::require('raise_marketplace_request');

        $req = $this->ownedRequest($id);
        if (!$req) { return; }

        // Only while it is still live. A completed or declined request is
        // history and should not be rewritten.
        if (in_array($req->status, ['closed', 'cancelled'], true)) {
            return;
        }

        DB::table('marketplace_requests')->where('id', $req->id)->update([
            'status' => 'cancelled', 'closed_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('marketplace_request_messages')->insert([
            'id' => (string) Str::uuid(),
            'request_id' => $req->id,
            'sender_type' => 'system',
            'message' => __('The customer cancelled this request.'),
            'created_at' => now(),
        ]);

        Audit::log($this->tenantId, 'marketplace', 'request.cancelled', 'success', null,
            ['request' => $req->id, 'number' => $req->number]);
    }

    private function ownedRequest(?string $id): ?object
    {
        if (!$id) { return null; }

        return DB::table('marketplace_requests')
            ->where('id', $id)
            ->where('tenant_id', $this->tenantId)   // the boundary
            ->first();
    }

    public function render()
    {
        $requests = DB::table('marketplace_requests as r')
            ->leftJoin('marketplace_providers as p', 'p.id', '=', 'r.provider_id')
            ->leftJoin('marketplace_services as s', 's.id', '=', 'r.service_id')
            ->where('r.tenant_id', $this->tenantId)          // the boundary
            ->when($this->search !== '', function ($q) {
                $q->where(function ($w) {
                    $w->where('r.summary', 'ilike', '%' . $this->search . '%')
                      ->orWhere('p.display_name', 'ilike', '%' . $this->search . '%');
                });
            })
            ->when($this->statusFilter !== '', fn ($q) => $q->where('r.status', $this->statusFilter))
            ->orderByDesc('r.created_at')
            ->select('r.*', 'p.display_name as provider_name', 'p.status as provider_status', 's.title as service_title')
            ->paginate(15);

        $open = null;
        $messages = collect();
        if ($this->openId) {
            $open = DB::table('marketplace_requests as r')
                ->leftJoin('marketplace_providers as p', 'p.id', '=', 'r.provider_id')
                ->leftJoin('marketplace_services as s', 's.id', '=', 'r.service_id')
                ->where('r.id', $this->openId)
                ->where('r.tenant_id', $this->tenantId)      // the boundary, again
                ->select('r.*', 'p.display_name as provider_name', 'p.contact_email as provider_email', 's.title as service_title')
                ->first();

            if ($open) {
                $messages = DB::table('marketplace_request_messages')
                    ->where('request_id', $open->id)->orderBy('created_at')->get();
            }
        }

        return view('livewire.portal.marketplace-requests', [
            'requests' => $requests,
            'open' => $open,
            'messages' => $messages,
            'canAct' => Perm::can('raise_marketplace_request'),
        ]);
    }
}
