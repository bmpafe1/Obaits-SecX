<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // REAL FEATURE 2026-08-16, requested directly: real, current
        // counts for each baseline threat-intel source (Spamhaus DROP,
        // URLhaus, hagezi TIF), reported centrally by the actual device
        // that just refreshed each one -- same "trust what the device
        // reports" discipline used everywhere else in this project,
        // rather than a central guess or a stale hardcoded number.
        // Stores only the MOST RECENT report per source (upserted), not
        // full history -- this is a live status display, not an audit
        // trail. Since every device fetches the identical real external
        // feed on roughly the same schedule, the most recent single
        // report is a reasonable, honest stand-in for "the platform's
        // current real source data" without needing cross-device
        // aggregation.
        Schema::create('threat_intel_source_reports', function (Blueprint $table) {
            $table->string('source')->primary();
            $table->unsignedInteger('count');
            $table->string('reported_by_hostname')->nullable();
            $table->timestamp('reported_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threat_intel_source_reports');
    }
};
