<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which parts of the platform each tenant actually uses.
 *
 * WHY audit_log IS NOT A SUBSTITUTE: it records security-relevant ACTIONS --
 * who suspended a tenant, who revoked a credential -- deliberately and by
 * design. It says nothing about a tenant who opens the vulnerability page every
 * day and has never opened compliance once. That is a different question and it
 * needs a different record.
 *
 * WHY IT MATTERS COMMERCIALLY: the telemetry design flags dormancy as the most
 * valuable signal in the whole register. A tenant using two of fourteen
 * capabilities is a tenant about to churn, and the capabilities NOBODY uses are
 * the ones to reconsider building on.
 *
 * DAILY AGGREGATES, NOT ONE ROW PER REQUEST. This is derived in RequireTenant,
 * which runs on every portal request and sits on the path taken from 791ms to
 * 52ms -- so it has to stay cheap. A row per tenant per area per day answers
 * "which areas do they use" perfectly well and grows at a bounded rate, where a
 * row per request would grow without limit for finer data nobody needs.
 *
 * NO URL, NO IP, NO USER AGENT. The AREA only, and a count. This measures
 * product usage, not people -- and the same restraint applies as in the login
 * tracking: collecting more would turn a usage measure into surveillance of a
 * customer's staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_usage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // The navigation area, not the route: 'vulnerability', 'compliance',
            // 'marketplace'. Routes churn; areas are the product's own shape.
            $table->string('area');

            $table->date('used_on');
            $table->integer('views')->default(0);

            // Distinct users touching this area today, so "one person clicking
            // a lot" is distinguishable from "the team uses this".
            $table->integer('distinct_users')->default(0);

            $table->timestamps();

            $table->unique(['tenant_id', 'area', 'used_on']);
            $table->index(['area', 'used_on']);
        });

        // Which users touched which area today. Cleared as it ages -- it exists
        // only to make distinct_users countable, not as a per-person history.
        Schema::create('feature_usage_seen', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('area');
            $table->date('used_on');

            $table->unique(['tenant_id', 'user_id', 'area', 'used_on']);
            $table->index('used_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_usage_seen');
        Schema::dropIfExists('feature_usage');
    }
};
