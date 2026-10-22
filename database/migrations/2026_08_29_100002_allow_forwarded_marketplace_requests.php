<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let marketplace_requests hold referrals forwarded from on-premise
 * installations, not only hosted tenants.
 *
 * THE TENANT PROBLEM: a request from an on-premise installation has no tenant
 * here. Their tenants exist on their hardware, not ours. Creating shadow tenant
 * rows for organisations we have no relationship with would corrupt every
 * tenant-scoped query on the platform and inflate every count on the analytics
 * page with customers who are not ours.
 *
 * So tenant_id becomes NULLABLE and installation_id is recorded instead. A
 * forwarded request is a referral we brokered, not a customer we serve, and the
 * schema should say so rather than pretend otherwise.
 *
 * Every existing tenant-scoped query already filters on tenant_id, so a null
 * simply never matches -- forwarded requests cannot leak into a tenant's view.
 *
 * local_reference is the installation's OWN id for the request, and makes
 * forwarding idempotent. An installation that queued a request while offline
 * will retry, and a retry must not send the provider a second copy of the same
 * referral.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_requests', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->change();
            $table->uuid('installation_id')->nullable();
            $table->string('local_reference')->nullable();

            $table->index('installation_id');
            $table->unique(['installation_id', 'local_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_requests', function (Blueprint $table) {
            $table->dropUnique(['installation_id', 'local_reference']);
            $table->dropIndex(['installation_id']);
            $table->dropColumn(['installation_id', 'local_reference']);
        });
    }
};
