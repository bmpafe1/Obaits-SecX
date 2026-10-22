<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('tenant_backup_connections')->cascadeOnDelete();
            $table->string('schedule')->default('daily'); // daily | weekly for v1
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status')->nullable(); // success | failure | running
            $table->text('last_error')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_jobs');
    }
};