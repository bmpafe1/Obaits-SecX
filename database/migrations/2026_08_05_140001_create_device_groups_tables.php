<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real, tenant-defined device groups (e.g. "Windows 10", "Windows
        // Server", "Linux Server") -- Phase A of software push automation.
        // Deliberately kept minimal: no auto-assign rule column yet, since
        // that is real, separate Phase C work not yet built -- avoids a
        // speculative, unused column sitting idle until then.
        Schema::create('device_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        // Real, per-device group membership. fleet_host_id is the real,
        // natural identifier (matches Assets.php's own real host list);
        // hostname is denormalized purely for display resilience if
        // Fleet is briefly unreachable when this page is viewed -- never
        // trusted as the real identity, only ever fleet_host_id is.
        Schema::create('device_group_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('device_group_id');
            $table->foreign('device_group_id')->references('id')->on('device_groups')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('fleet_host_id');
            $table->string('hostname')->nullable();
            $table->timestamps();
            $table->unique(['device_group_id', 'fleet_host_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_group_members');
        Schema::dropIfExists('device_groups');
    }
};
