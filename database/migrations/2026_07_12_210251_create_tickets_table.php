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
        Schema::create('tickets', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->unsignedInteger('number');            // human reference: #1, #2 — per tenant
            $table->string('subject');
            $table->text('body');
            $table->string('status')->default('open');     // open | in_progress | resolved | closed
            $table->string('priority')->default('normal'); // low | normal | high | urgent
            $table->uuid('raised_by')->nullable();         // tenant_users.id
            $table->uuid('assigned_to')->nullable();       // tenant_users.id
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
 
            // --- What makes this an INCIDENT rather than a request ---
            $table->boolean('is_incident')->default(false);
            $table->string('incident_severity')->nullable();   // low | medium | high | critical
            $table->timestamp('detected_at')->nullable();      // when it was NOTICED (not when logged)
            $table->timestamp('contained_at')->nullable();     // when the bleeding stopped
            $table->text('root_cause')->nullable();            // WHY it happened
            $table->text('lessons_learned')->nullable();       // A.5.27 — what changes as a result
            $table->uuid('linked_finding_id')->nullable();     // if it came from a scan finding
            $table->string('linked_alert_id')->nullable();     // if it came from a Wazuh alert
 
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'is_incident']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
