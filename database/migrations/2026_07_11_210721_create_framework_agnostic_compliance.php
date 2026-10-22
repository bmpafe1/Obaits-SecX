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
        // The document catalogue — framework-INDEPENDENT.
        // A document exists on its own terms; frameworks reference it.
        Schema::create('compliance_documents', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->string('doc_key')->primary();       // e.g. backup_policy
            $table->string('title');
            $table->string('mode');                     // automated|interview|delegate|guide
            $table->string('source')->nullable();       // AUTOMATED: telemetry source
            $table->json('questions')->nullable();      // INTERVIEW: what Brian asks
            $table->string('owner_role')->nullable();   // DELEGATE: whose job it is
            $table->text('ask')->nullable();            // DELEGATE: what they must provide
            $table->text('advice')->nullable();         // GUIDE: what we tell them
            $table->timestamps();
        });
 
        // The MANY-TO-MANY map. One document can satisfy several frameworks.
        // This is the whole point: write the Access Control Policy once, and it
        // counts for ISO A.5.18, PCI 7.x, and SOC 2 CC6.x.
        Schema::create('document_control_map', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('doc_key');
            $table->string('framework_id');             // CISO framework UUID
            $table->string('control_ref');              // A.5.18 / 7.1 / CC6.1 …
            $table->boolean('verified')->default(false);// set TRUE only by compliance:validate
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['doc_key', 'framework_id', 'control_ref'], 'dcm_unique');
            $table->index(['framework_id']);
            $table->foreign('doc_key')->references('doc_key')->on('compliance_documents')->onDelete('cascade');
        });
 
        // A tenant may pursue SEVERAL standards at once.
        Schema::create('tenant_frameworks', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('framework_id');
            $table->string('framework_name');
            $table->timestamps();
            $table->unique(['tenant_id', 'framework_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_profiles', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->dropColumn(['framework_id', 'framework_name']);
        });

    }
};
