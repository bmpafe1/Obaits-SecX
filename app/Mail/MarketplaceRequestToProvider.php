<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\DB;

/**
 * Tells a provider that a customer has requested their service.
 *
 * THIS IS THE WHOLE MECHANISM. Providers do not log in -- there is no provider
 * portal by design -- so this email is the only way a request ever reaches
 * them. If it does not arrive, nothing happens, and the customer has been told
 * their request was sent.
 *
 * ADDRESSING, and each part is deliberate:
 *
 *   To:        the provider's contact_email
 *   Cc:        services@obaits.com -- so the platform sees every referral it
 *              makes, and can chase or reassign if a provider goes quiet, has
 *              shut down, or simply never replies. Without a portal this is the
 *              only oversight that exists.
 *   Reply-To:  the customer AND services@obaits.com -- the provider hits reply
 *              and reaches the customer directly, which is how this model is
 *              meant to work, while the platform sees the response too.
 *
 * Sent from the PLATFORM's own address, not the tenant's. The referral is made
 * by Obaits: it is in copy, it may intervene, and a provider receiving mail
 * from an unfamiliar customer domain is more likely to treat it as spam.
 *
 * NOT QUEUED, deliberately. A queued send that fails leaves the customer told
 * their request went out when it did not. Sending inline means the outcome is
 * known at the moment of submission and provider_notified_at reflects reality.
 */
class MarketplaceRequestToProvider extends Mailable
{
    public function __construct(public string $requestId) {}

    public function envelope(): Envelope
    {
        $r = $this->request();

        $cc = [new Address(config('marketplace.oversight_email', 'services@obaits.com'), 'Obaits')];

        $replyTo = [new Address($r->requester_email, $r->requester_name ?: $r->requester_email)];
        $replyTo[] = $cc[0];

        return new Envelope(
            subject: __('Service request #:number from :org', [
                'number' => $r->number,
                'org' => $r->organization_name
                    ?: (json_decode($r->shared_context ?? '{}', true)['organisation'] ?? __('an Obaits customer')),
            ]),
            cc: $cc,
            replyTo: $replyTo,
        );
    }

    public function content(): Content
    {
        $r = $this->request();
        $ctx = json_decode($r->shared_context ?? '{}', true) ?: [];

        // A forwarded request has no tenant here, so the organisation name
        // comes from what the installation sent instead.
        $org = $r->organization_name ?: ($ctx['organisation'] ?? __('An Obaits customer'));

        $rows = '';
        $map = [
            'technologies' => __('Technologies they run'),
            'estate_size'  => __('Devices'),
            'environment'  => __('Environment'),
            'urgency'      => __('Timeframe'),
            'region'       => __('Country'),
            'language'     => __('Preferred language'),
        ];
        foreach ($map as $key => $label) {
            if (empty($ctx[$key])) continue;
            $val = is_array($ctx[$key]) ? implode(', ', $ctx[$key]) : $ctx[$key];
            $rows .= '<tr><td style="padding:4px 12px 4px 0;color:#667;">' . e($label)
                   . '</td><td style="padding:4px 0;">' . e($val) . '</td></tr>';
        }

        $html =
            '<p>' . __('Hello :provider,', ['provider' => e($r->provider_name)]) . '</p>'
            . '<p>' . __(':org has requested :service through Obaits.', [
                'org' => e($org),
                'service' => e($r->service_title ?: __('one of your services')),
            ]) . '</p>'

            . '<p><strong>' . e($r->summary) . '</strong></p>'
            . '<p style="white-space:pre-line;">' . e($r->details) . '</p>';

        if ($rows) {
            $html .= '<p><strong>' . __('What they told us about their environment') . '</strong></p>'
                   . '<table style="font-size:14px;border-collapse:collapse;">' . $rows . '</table>';
        }

        $html .=
            '<p><strong>' . __('Contact') . '</strong><br>'
            . e($r->requester_name ?: __('(no name given)')) . '<br>'
            . e($r->requester_email)
            . ($r->requester_phone ? '<br>' . e($r->requester_phone) : '')
            . '</p>'

            . '<p>' . __('Reply to this email to reach them directly. Obaits is copied so we can help if anything is unclear.') . '</p>'

            . '<hr style="border:none;border-top:1px solid #e6e9ee;margin:20px 0;">'
            . '<p style="font-size:12px;color:#889;">'
            . __('Obaits introduces customers to providers. Any quotation, contract, payment and delivery is agreed directly between you and the customer, and Obaits is not a party to that agreement.')
            . '</p>'
            . '<p style="font-size:12px;color:#889;">'
            . __('Priority stated by the customer: :priority', ['priority' => e($r->priority)])
            . '</p>';

        return new Content(htmlString: $html);
    }

    private function request(): object
    {
        return DB::table('marketplace_requests as r')
            ->join('marketplace_providers as p', 'p.id', '=', 'r.provider_id')
            // LEFT join, not inner.
            //
            // REAL BUG FOUND 2026-08-29 on the first forwarded request: a
            // request from an on-premise installation has a NULL tenant_id --
            // their tenants live on their hardware, not ours -- so an inner
            // join returned nothing and the whole mailable failed. The referral
            // was stored and the provider was never told, which is worse than
            // not forwarding at all because it looks like it worked.
            ->leftJoin('tenants as t', 't.id', '=', 'r.tenant_id')
            ->leftJoin('marketplace_services as s', 's.id', '=', 'r.service_id')
            // The requester's phone, so a provider can call rather than only
            // email -- a called lead converts far better than a mailed one.
            // Taken from their tenant_users profile rather than asked again on
            // the form: it is already held, and the profile is where a user
            // keeps it current. No phone on the profile means no number in the
            // email, which is the honest outcome.
            ->leftJoin('tenant_users as u', function ($j) {
                $j->on('u.tenant_id', '=', 'r.tenant_id')
                  ->on('u.email', '=', 'r.requester_email');
            })
            ->where('r.id', $this->requestId)
            ->select('r.*', 'p.display_name as provider_name', 'p.contact_email',
                     't.organization_name', 's.title as service_title',
                     'u.phone as requester_phone')
            ->first();
    }
}
