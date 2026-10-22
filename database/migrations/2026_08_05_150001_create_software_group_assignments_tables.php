<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real, tenant-defined "this software should be on every device
        // in this group" rule -- Phase B of software push automation,
        // working around Fleet Free's lack of native software-push.
        Schema::create('software_group_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('device_group_id');
            $table->foreign('device_group_id')->references('id')->on('device_groups')->cascadeOnDelete();
            $table->uuid('software_file_id');
            $table->foreign('software_file_id')->references('id')->on('software_files')->cascadeOnDelete();
            // Real, admin-provided silent-install command -- required
            // for anything that is not a real, auto-detected MSI
            // (confirmed directly with Brian: silent-install flags are
            // not universal across installer vendors, so this cannot
            // be safely guessed for non-MSI files).
            $table->text('install_command')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
            $table->unique(['device_group_id', 'software_file_id']);
        });

        // Real, per-device install attempt tracking -- matches
        // role_setup_runs' own established pattern exactly, so the
        // periodic reconciliation job (Phase B's own safety net) knows
        // which real devices are still missing an assigned install.
        Schema::create('software_install_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('software_group_assignment_id');
            $table->foreign('software_group_assignment_id')->references('id')->on('software_group_assignments')->cascadeOnDelete();
            $table->unsignedBigInteger('fleet_host_id');
            $table->string('hostname')->nullable();
            $table->string('status')->default('pending');   // pending | running | success | failed
            $table->text('output')->nullable();
            $table->timestamps();
            $table->unique(['software_group_assignment_id', 'fleet_host_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('software_install_runs');
        Schema::dropIfExists('software_group_assignments');
    }
};
