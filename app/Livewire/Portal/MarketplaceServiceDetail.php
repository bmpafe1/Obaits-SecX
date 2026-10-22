<?php

namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Perm;
use App\Support\Audit;

/**
 * A single Marketplace service, and the form to request it.
 *
 * BROWSING IS OPEN to every tenant user -- discovery should not be gated.
 * SUBMITTING requires raise_marketplace_request (platform-admin only), because
 * it starts a commercial conversation with a third party on the organisation's
 * behalf. A user without the permission sees the service and an explanation,
 * not a form that fails when they click it.
 *
 * THE DISCLOSURE BOUNDARY. shared_context carries SELF-DECLARED facts only --
 * things the customer states about themselves, never anything read from
 * platform data. The platform must never hand a provider Wazuh logs, endpoint
 * inventories, scan findings, GRC evidence or CCTV footage because a provider
 * happens to offer a related service.
 *
 * This is enforced structurally rather than by discipline: the fields below are
 * a fixed list of options the user picks from, so there is no code path that
 * could put platform data into the payload even by mistake.
 *
 * region and language are pre-filled from the tenant's own profile as EDITABLE
 * values the user confirms -- the customer already told us their country, and
 * making them retype it is friction for no protection. technologies is
 * deliberately NOT pre-filled from what the platform has detected: that would be
 * platform-observed data about their estate, which is exactly the line.
 */
#[Layout('components.layouts.portal')]
class MarketplaceServiceDetail extends Component
{
    public string $tenantId;
    public string $serviceId;

    // The request
    public string $summary = '';
    public string $details = '';
    public string $priority = 'normal';

    // Self-declared context
    public array  $ctxTechnologies = [];
    public string $ctxEstateSize = '';
    public string $ctxEnvironment = '';
    public string $ctxUrgency = '';
    public string $ctxRegion = '';
    public string $ctxLanguage = '';

    public bool $submitted = false;
    public ?int $submittedNumber = null;

    public const ESTATE_SIZES = [
        '1-10'    => '1 to 10 devices',
        '11-25'   => '11 to 25 devices',
        '26-100'  => '26 to 100 devices',
        '101-500' => '101 to 500 devices',
        '500+'    => 'More than 500 devices',
    ];

    public const ENVIRONMENTS = [
        'on_premise' => 'Mostly on-premise',
        'cloud'      => 'Mostly cloud',
        'hybrid'     => 'A mix of both',
    ];

    public const URGENCIES = [
        'exploring'      => 'Just exploring options',
        'within_quarter' => 'Within the next few months',
        'within_month'   => 'Within a month',
        'urgent'         => 'Urgent',
    ];

    public function mount(string $id)
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { return redirect('/login'); }

        $this->serviceId = $id;

        // Pre-fill ONLY what the customer already told us about themselves.
        //
        // preferred_language is theirs -- they chose it. Country is NOT on the
        // tenant record at all, so region starts empty and the customer states
        // it. Deriving it from an IP address or a device's location would be
        // platform-observed data, which is exactly the line this design does
        // not cross.
        $tenant = DB::table('tenants')->where('id', $this->tenantId)->first();
        $this->ctxLanguage = $tenant->preferred_language ?? app()->getLocale();
    }

    public function submit()
    {
        Perm::require('raise_marketplace_request');

        $this->validate([
            'summary' => 'required|string|min:5|max:200',
            'details' => 'required|string|min:20|max:5000',
            'priority' => 'required|in:low,normal,high',
        ], [], [
            'summary' => __('summary'),
            'details' => __('details'),
        ]);

        $service = $this->service();
        if (!$service) {
            $this->addError('summary', __('That service is no longer available.'));
            return;
        }

        // Per-tenant sequential number, as the helpdesk does: the customer sees
        // #1, #2, #3 rather than a UUID.
        $number = (int) DB::table('marketplace_requests')
            ->where('tenant_id', $this->tenantId)->max('number') + 1;

        $id = (string) Str::uuid();

        DB::table('marketplace_requests')->insert([
            'id' => $id,
            'number' => $number,
            'tenant_id' => $this->tenantId,
            'provider_id' => $service->provider_id,
            'service_id' => $service->id,
            'requester_email' => session('auth_email'),
            // From the user record, not the session -- session('user_name') is
            // not a key this application sets, and a silently-null requester
            // name would leave the provider without a person to reply to.
            'requester_name' => $this->requesterName(),
            'summary' => $this->summary,
            'details' => $this->details,
            'priority' => $this->priority,
            'shared_context' => json_encode(array_filter([
                'technologies' => $this->ctxTechnologies ?: null,
                'estate_size'  => $this->ctxEstateSize ?: null,
                'environment'  => $this->ctxEnvironment ?: null,
                'urgency'      => $this->ctxUrgency ?: null,
                'region'       => $this->ctxRegion ?: null,
                'language'     => $this->ctxLanguage ?: null,
            ])),
            'status' => 'submitted',
            'submitted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Audit::log($this->tenantId, 'marketplace', 'request.submitted', 'success', null, [
            'request' => $id, 'number' => $number, 'provider' => $service->provider_slug,
        ]);

        // Notify the provider. This email is the WHOLE mechanism -- providers
        // do not log in, so if it does not arrive nothing happens at all.
        //
        // Sent inline rather than queued: a queued send that fails would leave
        // the customer told their request went out when it did not.
        //
        // A failure must NOT lose the request. The record is what matters, and
        // provider_notified_at staying null is exactly how an operator finds
        // requests that need re-sending by hand.
        try {
            \Illuminate\Support\Facades\Mail::to($service->contact_email)
                ->send(new \App\Mail\MarketplaceRequestToProvider($id));

            DB::table('marketplace_requests')->where('id', $id)
                ->update(['provider_notified_at' => now(), 'updated_at' => now()]);

            DB::table('marketplace_request_messages')->insert([
                'id' => (string) Str::uuid(),
                'request_id' => $id,
                'sender_type' => 'system',
                'message' => __('We sent your request to :provider.', ['provider' => $service->provider_name]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Marketplace request ' . $id . ' could not be emailed: ' . $e->getMessage());

            Audit::log($this->tenantId, 'marketplace', 'request.notify_failed', 'failure', null,
                ['request' => $id, 'error' => $e->getMessage()]);

            DB::table('marketplace_request_messages')->insert([
                'id' => (string) Str::uuid(),
                'request_id' => $id,
                'sender_type' => 'system',
                'message' => __('Your request is recorded. We had trouble emailing :provider and will follow up.', ['provider' => $service->provider_name]),
                'created_at' => now(),
            ]);
        }

        $this->submitted = true;
        $this->submittedNumber = $number;
    }

    private function requesterName(): ?string
    {
        $u = DB::table('tenant_users')
            ->where('tenant_id', $this->tenantId)
            ->where('email', session('auth_email'))
            ->first(['first_name', 'last_name']);

        if (!$u) {
            return null;
        }

        $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));

        return $name !== '' ? $name : null;
    }

    private function service(): ?object
    {
        return DB::table('marketplace_services as s')
            ->join('marketplace_providers as p', 'p.id', '=', 's.provider_id')
            ->where('s.id', $this->serviceId)
            ->where('s.status', 'published')
            ->where('p.lifecycle', 'active')
            ->select(
                's.*',
                'p.id as provider_id', 'p.display_name as provider_name', 'p.contact_email',
                'p.slug as provider_slug', 'p.status as provider_status',
                'p.description as provider_description', 'p.website as provider_website',
                'p.response_time as provider_response_time'
            )
            ->first();
    }

    public function render()
    {
        return view('livewire.portal.marketplace-service-detail', [
            'service' => $this->service(),
            'technologies' => MarketplaceServices::TECHNOLOGIES,
            'categories' => MarketplaceServices::CATEGORIES,
            'estateSizes' => self::ESTATE_SIZES,
            'environments' => self::ENVIRONMENTS,
            'urgencies' => self::URGENCIES,
            'canRequest' => Perm::can('raise_marketplace_request'),
        ]);
    }
}
