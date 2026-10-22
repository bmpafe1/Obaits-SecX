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
        // CISA Known Exploited Vulnerabilities.
        Schema::create('kev_entries', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->string('cve')->primary();          // CVE-2021-44228
            $table->string('vendor')->nullable();
            $table->string('product')->nullable();
            $table->text('name')->nullable();
            $table->text('required_action')->nullable();
            $table->date('due_date')->nullable();
            $table->boolean('ransomware')->default(false);  // known ransomware campaign use
            $table->date('date_added')->nullable();
            $table->timestamps();
        });
 
        // EPSS scores (probability of exploitation in the next 30 days).
        Schema::create('epss_scores', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->string('cve')->primary();
            $table->decimal('epss', 8, 7);             // 0.0000000 - 1.0000000
            $table->decimal('percentile', 8, 7)->nullable();
            $table->date('score_date')->nullable();
            $table->timestamps();
        });
 
        // The computed risk for each finding (the engine's output).
        Schema::table('findings', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->unsignedTinyInteger('criticality')->nullable();   // 1..5
            $table->json('risk_factors')->nullable();                 // the traceable reasoning
            $table->timestamp('scored_at')->nullable();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('threat_intel_tables');
    }
};
