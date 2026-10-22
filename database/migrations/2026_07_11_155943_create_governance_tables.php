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
        // Named individuals in the organisation. NOT platform users — most never log in.
        Schema::create('tenant_people', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('title');                       // e.g. Managing Director, HR Manager
            $table->string('email')->nullable();           // optional — for notifications later
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
 
        // The approval matrix: for each document type, who prepares / reviews / approves.
        Schema::create('document_approvals', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('document_type');               // e.g. information_security_policy
            $table->uuid('person_id');                     // -> tenant_people
            $table->string('role');                        // prepared_by | reviewed_by | approved_by
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'document_type']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('person_id')->references('id')->on('tenant_people')->onDelete('cascade');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('governance_tables');
    }
};
