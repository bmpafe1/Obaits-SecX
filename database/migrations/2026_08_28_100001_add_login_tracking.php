<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login tracking for tenant users.
 *
 * NOTHING RECORDS THIS TODAY. tenant_users has no last_login_at and no session
 * history exists anywhere, so "how often does each person actually use the
 * platform" cannot be answered at all -- and unlike most gaps, this one cannot
 * be backfilled. Every day without it is engagement history permanently lost.
 *
 * That is why it comes before the reporting that consumes it: the analytics
 * view can be built at any time, but login history only accrues from the moment
 * this exists.
 *
 * WHY IT MATTERS COMMERCIALLY: a tenant whose users stopped logging in is a
 * tenant about to leave, and knowing that early is worth more than any
 * vulnerability statistic. The telemetry design flags dormancy as the most
 * valuable signal in the whole register.
 *
 * TWO TABLES, deliberately:
 *
 *   tenant_users.last_login_at  -- the cheap answer to "is this person active",
 *                                  readable without scanning history.
 *   user_logins                 -- the event log, for frequency and trend.
 *
 * The event log records NO IP address and NO user agent. Neither is needed to
 * answer any question in the telemetry register, and collecting them would make
 * this a surveillance record of a customer's staff rather than a usage measure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable();
            $table->integer('login_count')->default(0);
            // Distinct from last_login_at, and the truer churn signal. A user
            // who authenticates once and stays signed in for a week looks
            // dormant by last_login_at while actually using the platform daily.
            // Updated at most hourly from RequireTenant.
            $table->timestamp('last_seen_at')->nullable();
        });

        Schema::create('user_logins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            $table->string('email');
            $table->timestamp('logged_in_at');

            $table->index(['tenant_id', 'logged_in_at']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_logins');
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn(['last_login_at', 'login_count', 'last_seen_at']);
        });
    }
};
