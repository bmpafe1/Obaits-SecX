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
        Schema::create('software_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('software_id');            // the software_catalog entry
            $table->uuid('uploaded_by')->nullable();
            $table->string('original_name');
            $table->string('path');                 // per-tenant storage path
            $table->string('mime')->nullable();
            $table->bigInteger('size')->nullable();
            $table->string('sha256', 64);           // integrity — shown on download
            $table->string('scan_status')->default('pending');   // clean | infected | error
            $table->string('scan_detail')->nullable();
            $table->string('version')->nullable();  // which version this installer is
            $table->text('notes')->nullable();
            $table->timestamps();
 
            $table->index(['tenant_id', 'software_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('software_files');
    }
};
