<?php

namespace App\Support\Marketplace;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Support\Audit;

/**
 * A marketplace request raised on an ON-PREMISE installation, forwarded here.
 *
 * WHY IT COMES TO US AT ALL: referrals are the product. A customer running from
 * the ISO still browses a catalogue we curate, and when they request a service
 * the provider must be notified and we must see the referral -- otherwise
 * on-premise deployments would be commercially invisible.
 *
 * THE TENANT PROBLEM. A request from an on-premise installation has no tenant
 * here: their tenants exist on their hardware, not ours, and creating shadow
 * tenant rows for organisations we have no relationship with would corrupt every
 * tenant-scoped query and every count on the analytics page.
 *
 * So these are stored with a NULL tenant_id and the installation recorded
 * instead. They are referrals we brokered, not customers we serve, and the
 * schema should say so rather than pretending otherwise.
 *
 * PRIVACY: we receive only what the customer chose to send -- the same
 * self-declared environment facts a hosted tenant supplies, plus a contact
 * address so the provider can reply. No platform data, no findings, no
 * inventory. The on-premise promise is that their data stays theirs, and this
 * path must not quietly undo it.
 */
class ForwardedRequest
{
    public function accept(object $install, Request $r)
    {
        $data = $r->validate([
            'provider_slug'     => 'required|string|max:120',
            'service_title'     => 'nullable|string|max:200',
            'summary'           => 'required|string|max:200',
            'details'           => 'required|string|max:5000',
            'priority'          => 'nullable|in:low,normal,high',
            'requester_email'   => 'required|email|max:255',
            'requester_name'    => 'nullable|string|max:150',
            'requester_phone'   => 'nullable|string|max:40',
            'organisation_name' => 'nullable|string|max:200',
            'shared_context'    => 'nullable|array',
            // The installation's own reference, so a retry after a failed
            // send does not create a duplicate referral.
            'local_reference'   => 'required|string|max:80',
        ]);

        $provider = DB::table('marketplace_providers')
            ->where('slug', $data['provider_slug'])
            ->where('lifecycle', 'active')
            ->first();

        if (!$provider) {
            return response()->json(['error' => 'unknown or inactive provider'], 422);
        }

        // Idempotent. An installation that queued a request offline will retry,
        // and a retry must not send the provider a second copy.
        $existing = DB::table('marketplace_requests')
            ->where('installation_id', $install->id)
            ->where('local_reference', $data['local_reference'])
            ->first();

        if ($existing) {
            return response()->json([
                'status' => 'already_received',
                'number' => $existing->number,
            ], 200);
        }

        $number = (int) DB::table('marketplace_requests')
            ->where('installation_id', $install->id)->max('number') + 1;

        $id = (string) Str::uuid();

        DB::table('marketplace_requests')->insert([
            'id' => $id,
            'number' => $number,
            // NULL: their tenants live on their hardware, not ours.
            'tenant_id' => null,
            'installation_id' => $install->id,
            'local_reference' => $data['local_reference'],
            'provider_id' => $provider->id,
            'service_id' => null,
            'requester_email' => $data['requester_email'],
            'requester_name' => $data['requester_name'] ?? null,
            'summary' => $data['summary'],
            'details' => $data['details'],
            'priority' => $data['priority'] ?? 'normal',
            'shared_context' => json_encode(($data['shared_context'] ?? []) + array_filter([
                'organisation' => $data['organisation_name'] ?? null,
                'phone' => $data['requester_phone'] ?? null,
            ])),
            'status' => 'submitted',
            'submitted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('installations')->where('id', $install->id)
            ->update(['requests_forwarded' => DB::raw('requests_forwarded + 1'), 'updated_at' => now()]);

        Audit::log(null, 'installation', 'marketplace.request_forwarded', 'success', null, [
            'installation' => $install->id, 'provider' => $provider->slug, 'request' => $id,
        ]);

        // Notify the provider, exactly as a hosted request does. A send failure
        // must not lose the referral -- the record is what matters, and
        // provider_notified_at staying null is how an operator finds it.
        try {
            Mail::to($provider->contact_email)
                ->send(new \App\Mail\MarketplaceRequestToProvider($id));

            DB::table('marketplace_requests')->where('id', $id)
                ->update(['provider_notified_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::error("Forwarded marketplace request {$id} could not be emailed: " . $e->getMessage());
        }

        return response()->json(['status' => 'accepted', 'number' => $number], 201);
    }
}
