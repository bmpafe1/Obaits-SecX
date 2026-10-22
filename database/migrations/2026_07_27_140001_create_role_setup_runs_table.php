<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_setup_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedInteger('fleet_host_id');
            $table->string('hostname');
            $table->string('role_group');
            $table->string('fleet_execution_id')->nullable();
            $table->string('status')->default('running'); // running|success|failed
            $table->text('output')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'fleet_host_id', 'role_group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_setup_runs');
    }
};
