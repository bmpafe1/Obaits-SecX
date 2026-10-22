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
        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('tenant_id');
            $table->uuid('uploaded_by')->nullable();
            $table->string('original_name');
            $table->string('path');                       // local disk path
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->boolean('is_evidence')->default(false);  // true once the ticket is an incident
            $table->timestamps();
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->index(['tenant_id', 'ticket_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
    }
};
