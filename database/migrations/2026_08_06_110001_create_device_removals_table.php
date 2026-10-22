<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real device-removal queue. Replaces the synchronous, partial
        // cleanup previously done inline inside Assets::removeDevice(),
        // which only ever touched Fleet, Wazuh, and (as of today) Headscale
        // -- leaving Zabbix hosts, backup selections, and stored role
        // credentials behind on every removal.
        //
        // Modelled directly on software_install_runs / policy_runs: durable,
        // per-step, retryable state rather than fire-and-forget. This
        // matters MORE here than for software pushes, because a failed step
        // here can mean a lost or stolen device keeps real access (VPN,
        // backup repository) rather than just a missing install.
        //
        // artifact_status is a single JSON column rather than one boolean
        // per system: this list will grow (policy device-credential
        // revocation is already planned), and a JSON map of
        // step => 'pending'|'done'|'failed'|'skipped' avoids a migration
        // every time a new system needs revoking.
        Schema::create('device_removals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('fleet_host_id');
            $table->string('hostname');

            // decommissioned: routine, clean removal (retired/rebuilt/sold).
            // lost / stolen: urgent revocation; the record and audit trail
            // are deliberately kept rather than deleted, since this is
            // itself a security incident worth being able to review later.
            $table->string('reason')->default('decommissioned');

            $table->string('requested_by')->nullable();
            $table->timestamp('requested_at');

            // Per-step state: backup_selections, headscale, role_credentials,
            // zabbix, wazuh, group_membership, fleet -- each one of
            // pending|done|failed|skipped, plus an optional error string.
            // Fleet is deliberately processed LAST: every earlier step
            // resolves the device through Fleet's own host record (platform,
            // hostname, group membership), so deleting it first would
            // destroy the information needed to retry anything that failed.
            $table->json('artifact_status');

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'fleet_host_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_removals');
    }
};
