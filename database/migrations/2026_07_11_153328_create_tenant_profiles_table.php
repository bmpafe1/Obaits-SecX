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
        Schema::create('tenant_profiles', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
 
            // Identity
            $table->string('legal_name')->nullable();
            $table->string('trading_name')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('logo_path')->nullable();
 
            // Address
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
 
            // Contact
            $table->string('phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('website')->nullable();
 
            // Governance
            $table->string('security_officer_name')->nullable();
            $table->string('security_officer_title')->nullable();
            $table->string('approver_name')->nullable();
            $table->string('approver_title')->nullable();
            $table->string('sector')->nullable();
            $table->unsignedInteger('headcount')->nullable();
 
            // Document control
            $table->string('doc_version')->default('1.0');
            $table->date('effective_date')->nullable();
            $table->string('review_cycle')->default('annual');   // annual | biannual | quarterly
 
            // ISMS scope
            $table->text('isms_scope')->nullable();
 
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_profiles');
    }
};
