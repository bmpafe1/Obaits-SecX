<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A daily snapshot of each tenant, for product analytics.
 *
 * WHY SNAPSHOTS RATHER THAN LIVE QUERIES: four of the thirteen questions
 * answerable today need data that is NOT held locally. Fleet host inventory
 * (operating systems, versions, software) and DFIR-IRIS case counts are fetched
 * over APIs, and there is no local cache of either.
 *
 * Querying those live, per tenant, on every page load would make the analytics
 * page slow and fragile: one unreachable service and the whole page fails.
 * Collecting once a day instead keeps the page instant, keeps it working when a
 * service is down, and costs only that the figures are up to a day old -- which
 * is entirely acceptable for product analytics.
 *
 * The second benefit is the real one. A snapshot per tenant per day is a TIME
 * SERIES, so questions about trend and dormancy become answerable rather than
 * only point-in-time counts. "How many devices does this tenant have" is worth
 * far less than "this tenant had 40 devices in June and has 12 now".
 *
 * ON-PREMISE: this table is exactly the shape that should be transmitted --
 * counts and distributions, no hostnames, no addresses, no personal data. See
 * the telemetry design document, section 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->date('captured_on');

            // Scale
            $table->integer('device_count')->default(0);
            $table->integer('user_count')->default(0);

            // Engagement -- the dormancy signal
            $table->integer('logins_7d')->default(0);
            $table->integer('logins_30d')->default(0);
            $table->integer('active_users_30d')->default(0);
            $table->timestamp('last_activity_at')->nullable();

            // Estate, from Fleet. Distributions only, never hostnames.
            $table->json('os_distribution')->nullable();

            // Security posture
            $table->integer('findings_total')->default(0);
            $table->integer('findings_high')->default(0);
            $table->integer('findings_medium')->default(0);
            $table->integer('findings_low')->default(0);
            $table->integer('scans_total')->default(0);

            // Feature adoption -- which capabilities are used, and which are not.
            // The telemetry design flags this as the most commercially valuable
            // signal: a tenant using two of fourteen capabilities is a tenant
            // about to churn.
            $table->integer('policy_assignments')->default(0);
            $table->integer('policy_runs_30d')->default(0);
            $table->integer('backup_selections')->default(0);
            $table->integer('backup_runs_30d')->default(0);
            $table->integer('open_incidents')->default(0);
            $table->integer('tickets_total')->default(0);
            $table->integer('marketplace_requests')->default(0);

            // Compliance
            $table->string('framework')->nullable();
            $table->boolean('has_security_officer')->default(false);   // BOOLEAN, not the name

            // Profile
            $table->string('country', 2)->nullable();
            $table->string('sector')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'captured_on']);
            $table->index('captured_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_snapshots');
    }
};
