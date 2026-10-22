<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('target');
            $table->string('mode'); // on_demand | scheduled
            $table->string('status')->default('queued'); // queued|running|done|failed
            $table->string('gvm_target_id')->nullable();
            $table->string('gvm_task_id')->nullable();
            $table->integer('progress')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        
        Schema::create('findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('scan_id')->constrained('scans')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('host')->nullable();
            $table->string('port')->nullable();
            $table->string('cve')->nullable();
            $table->decimal('severity', 4, 1)->nullable();
            $table->string('threat')->nullable(); // High/Medium/Low
            $table->text('description')->nullable();
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scans_and_findings');
    }
};
