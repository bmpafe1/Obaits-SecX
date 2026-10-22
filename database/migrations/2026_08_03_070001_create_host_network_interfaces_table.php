<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_network_interfaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('fleet_host_id');
            $table->string('hostname');
            $table->string('interface_name');
            $table->string('ip_address');
            $table->timestamp('last_fetched_at');
            $table->timestamps();

            // Real, current state per interface -- one row per real,
            // distinct interface on a device, replaced wholesale on
            // each real sync rather than accumulating history (this
            // table answers "what does this device look like right
            // now", not "what has it ever looked like").
            $table->unique(['fleet_host_id', 'interface_name', 'ip_address'], 'host_iface_unique');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_network_interfaces');
    }
};
