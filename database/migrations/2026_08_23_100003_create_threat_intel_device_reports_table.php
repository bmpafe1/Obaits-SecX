<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-device threat-intel refresh health.
 *
 * REAL BACKGROUND 2026-08-23: threat-intel refresh moved from a portal-
 * pushed Fleet script (one launch per host, per hour, via the shared
 * Fleet script channel) to a device-side systemd timer / scheduled task.
 * That change is what makes this feature scale -- Fleet SERIALISES script
 * execution per host, and an hourly push whose script takes ~5 minutes
 * built a real, confirmed 240-item backlog across 3 hosts, the oldest
 * queued for FIVE DAYS, which starved genuine Group Policy checks of any
 * chance to run at all. See the 2026-08-23 investigation for the full
 * story.
 *
 * The cost of moving the schedule onto the device is that the portal can
 * no longer see, via Fleet's own activity log, that a refresh happened.
 * This table restores that visibility.
 *
 * WHY NOT threat_intel_source_reports: that existing table is keyed on
 * SOURCE only (four rows, platform-wide), each overwritten by whichever
 * device reported last. It genuinely cannot answer "is device X still
 * refreshing" -- if 3 of 4 devices died, that table would still look
 * perfectly healthy, updated by the one survivor.
 *
 * HOSTNAME IS COPIED FROM device_credentials, never from client input.
 * Reports authenticate with the per-device credential issued at enrollment,
 * and the endpoint reads both tenant_id and hostname from that credential's
 * own row -- so these values match device_credentials by construction, and a
 * device cannot claim to be another. NOTE the stored name is whatever the
 * enrollment script sent ($(hostname) / $env:COMPUTERNAME), i.e. the SHORT
 * name on Windows, while Fleet stores the FQDN: do NOT join these rows to
 * Fleet host records on hostname alone.
 *
 * NOT keyed on fleet_host_id despite device_credentials carrying one --
 * confirmed live 2026-08-23 that it is populated for only 2 of 11 real
 * credentials, so it is not a dependable key today.
 *
 * last_success_at IS THE AUTHORITATIVE ALERTING SIGNAL.
 * consecutive_failures is ADVISORY ONLY -- kept because it distinguishes
 * "failing repeatedly since Tuesday" from "failed once an hour ago" at a
 * glance without a history table, but the two fields can theoretically
 * disagree if a write is interrupted. Alert on last_success_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threat_intel_device_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('hostname');       // normalised: short name, lowercased
            $table->string('platform')->nullable();  // 'linux' | 'windows'

            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status')->nullable();      // 'success' | 'failure'
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->integer('consecutive_failures')->default(0);

            $table->timestamps();

            // Hostnames are NOT unique across tenants -- two tenants can each
            // have a device called "linuxclient". Tenant always comes from the
            // authenticated enrollment secret, never from client input.
            $table->unique(['tenant_id', 'hostname']);
            $table->index('last_success_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threat_intel_device_reports');
    }
};
