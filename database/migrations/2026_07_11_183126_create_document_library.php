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
        Schema::create('tenant_documents', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('doc_key');                 // DocumentRegister key
            $table->string('title');
            $table->string('mode');                    // automated|interview|delegate|guide
            $table->string('status')->default('draft');// draft | final | sent_to_owner | received
            $table->string('version')->default('1.0');
            $table->longText('body')->nullable();      // markdown source
            $table->string('file_path')->nullable();   // rendered .docx
            $table->json('answers')->nullable();       // interview answers (the audit trail)
            $table->json('signatories')->nullable();   // SNAPSHOT at generation
            $table->json('header')->nullable();        // SNAPSHOT of org profile
            $table->string('assigned_to')->nullable(); // delegate: person name
            $table->string('assigned_role')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'doc_key']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_library');
    }
};
