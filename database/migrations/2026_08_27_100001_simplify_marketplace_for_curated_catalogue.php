<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace becomes a CURATED catalogue with no provider portal.
 *
 * DECISION 2026-08-27: providers do not authenticate and never log in. All
 * provider and service data is entered and edited by the platform operator, and
 * a provider replies to the customer DIRECTLY by email with services@obaits.com
 * in CC -- so the platform can intervene if a provider goes quiet, has shut
 * down, or simply never responds.
 *
 * The reason is reputational: an open provider portal means providers writing
 * their own listings under the platform's brand, and one misleading or false
 * entry damages trust in everything else on the page. Curated is a stronger
 * product than open.
 *
 * It also settles the on-premise story. An .iso appliance cannot let a local
 * user edit provider data, so it SYNCS the catalogue read-only from the SaaS
 * and posts requests back to it. The SaaS stays the system of record wherever a
 * request originates.
 *
 * CONSEQUENCES, unwound here:
 *
 * 1. marketplace_provider_users is dropped. It existed only for a portal login
 *    that will not exist. RequireProvider middleware is removed with it.
 *
 * 2. The status vocabulary collapses. accepted, declined,
 *    clarification_requested, routed, provider_notified, customer_contacted and
 *    in_progress could only ever be set by a provider acting in-platform, and no
 *    provider does. What remains is what the platform can actually observe:
 *    submitted -> closed, plus cancelled. Claiming to know more than that would
 *    be dishonest to the customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('marketplace_provider_users');

        // Collapse any existing rows onto the surviving vocabulary.
        DB::table('marketplace_requests')
            ->whereIn('status', ['routed', 'provider_notified', 'accepted',
                                 'clarification_requested', 'customer_contacted', 'in_progress'])
            ->update(['status' => 'submitted']);

        DB::table('marketplace_requests')
            ->whereIn('status', ['declined', 'completed'])
            ->update(['status' => 'closed']);
    }

    public function down(): void
    {
        Schema::create('marketplace_provider_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('provider_id');
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->string('role')->default('member');
            $table->string('status')->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->index('provider_id');
        });
    }
};
