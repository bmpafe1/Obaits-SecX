<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace V1 -- a qualified service-request and lead-referral system.
 *
 * NOT a payment marketplace. Providers handle quotation, contracting, payment
 * and delivery outside the platform; the platform records discovery,
 * qualification, routing and status. That is a deliberate scope limit, not an
 * unfinished feature.
 *
 * THE ISOLATION PROBLEM THIS SCHEMA MUST SOLVE: every existing access rule on
 * this platform reads "this user belongs to this tenant, show them their
 * tenant's data". A provider user is the INVERSE -- they see requests routed to
 * their provider, across many different tenants. marketplace_requests therefore
 * carries BOTH tenant_id and provider_id, and every query must be scoped by
 * whichever context it is running in. Customer queries filter on tenant_id;
 * provider queries filter on provider_id. A path that forgets which context it
 * is in leaks in one direction or the other.
 *
 * THE DISCLOSURE BOUNDARY: a provider receives only what is needed to evaluate
 * and respond. The platform must NEVER automatically expose Wazuh logs, endpoint
 * inventories, GRC evidence, incident evidence or CCTV data merely because a
 * provider offers a related service. shared_context exists so that boundary is
 * STRUCTURAL: it holds only what the customer explicitly chose to disclose, and
 * provider-facing queries never join to tenant data. A rule someone has to
 * remember is a rule that will eventually be forgotten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('legal_name');
            $table->string('display_name');
            $table->string('slug')->unique();

            // official_upstream | authorized_partner | verified | community
            //
            // BRANDING RULE, and it is a legal one: never present a provider as
            // official or authorized unless the manufacturer has granted that
            // authorization. Technical integration is not endorsement.
            $table->string('status')->default('community');
            $table->string('lifecycle')->default('pending');   // pending | active | suspended

            $table->text('description')->nullable();
            $table->string('website')->nullable();
            $table->string('contact_email');
            $table->string('contact_phone')->nullable();

            // Arrays: technologies supported, regions served, languages spoken,
            // delivery models offered (remote | onsite | hybrid).
            $table->json('technologies')->nullable();
            $table->json('regions')->nullable();
            $table->json('languages')->nullable();
            $table->json('delivery_models')->nullable();

            $table->string('response_time')->nullable();       // expectation setting, free text
            $table->text('terms_url')->nullable();

            $table->timestamps();
            $table->index('status');
            $table->index('lifecycle');
        });

        Schema::create('marketplace_services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('provider_id');

            // professional_services | technical_support | consulting |
            // implementation | managed_services | training | certification
            $table->string('category');
            $table->string('technology')->nullable();          // wazuh | greenbone | fleet | zabbix | ...

            $table->string('title');
            $table->text('description');
            $table->json('delivery_models')->nullable();
            $table->json('regions')->nullable();

            // Optional in V1 -- "request a quote" is an acceptable answer.
            $table->string('pricing')->nullable();
            $table->text('prerequisites')->nullable();
            $table->string('response_time')->nullable();

            $table->string('status')->default('draft');        // draft | published | suspended
            $table->timestamps();

            $table->index(['status', 'category']);
            $table->index('technology');
            $table->index('provider_id');
        });

        Schema::create('marketplace_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Per-tenant sequential number, as the helpdesk does. A customer
            // should see #1, #2, #3 -- not a UUID.
            $table->integer('number');

            $table->uuid('tenant_id');                          // the CUSTOMER side
            $table->uuid('provider_id');                        // the PROVIDER side
            $table->uuid('service_id')->nullable();             // nullable: a service may later be withdrawn

            $table->string('requester_email');
            $table->string('requester_name')->nullable();

            $table->string('summary');
            $table->text('details');
            $table->string('priority')->default('normal');      // low | normal | high

            // ONLY what the customer explicitly chose to share. Never populated
            // automatically from platform findings -- see the class comment.
            $table->json('shared_context')->nullable();

            // draft | submitted | routed | provider_notified | accepted |
            // clarification_requested | declined | customer_contacted |
            // in_progress | completed | cancelled
            //
            // 'completed' means the REQUEST lifecycle closed here. It is not a
            // statement that the service was delivered satisfactorily -- the
            // platform is not party to the provider's contract in V1.
            $table->string('status')->default('draft');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('provider_notified_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);             // customer view
            $table->index(['provider_id', 'status']);           // provider view
        });

        Schema::create('marketplace_request_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('request_id');
            $table->string('sender_type');                      // customer | provider | system
            $table->string('sender_id')->nullable();
            $table->text('message');
            $table->timestamp('created_at')->nullable();

            $table->index('request_id');
        });

        Schema::create('marketplace_provider_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('provider_id');
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->string('role')->default('member');          // owner | member
            $table->string('status')->default('active');        // active | suspended
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->index('provider_id');
        });

        Schema::create('marketplace_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('provider_id');

            // identity | manufacturer_authorization | capability | insurance
            $table->string('verification_type');
            $table->text('evidence')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('result')->default('pending');       // pending | passed | failed
            $table->timestamps();

            $table->index(['provider_id', 'verification_type']);
        });

        Schema::create('marketplace_referrals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('request_id');

            // Denormalised, like scanner_pool_allocations: attribution must
            // survive a provider being removed, because "who was this referred
            // to, and what came of it" is exactly the commercial record.
            $table->string('provider_slug');
            $table->uuid('tenant_id');

            $table->string('outcome')->nullable();              // won | lost | no_response | unknown
            $table->timestamp('referred_at');
            $table->timestamp('outcome_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('provider_slug');
            $table->index('referred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_referrals');
        Schema::dropIfExists('marketplace_verifications');
        Schema::dropIfExists('marketplace_provider_users');
        Schema::dropIfExists('marketplace_request_messages');
        Schema::dropIfExists('marketplace_requests');
        Schema::dropIfExists('marketplace_services');
        Schema::dropIfExists('marketplace_providers');
    }
};
