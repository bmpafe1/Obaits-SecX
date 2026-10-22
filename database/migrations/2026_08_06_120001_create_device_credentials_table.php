<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('hostname');
            $table->unsignedBigInteger('fleet_host_id')->nullable();
            $table->string('secret_hash', 64);
            $table->timestamp('issued_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'hostname']);
            $table->index(['tenant_id', 'fleet_host_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_credentials');
    }
};
