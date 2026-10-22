<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Live query results land here, not in the page's request. Fleet's live
        // query is asynchronous — a campaign is created and hosts answer later —
        // so a queued job collects results into this table and the page polls it.
        Schema::create('live_query_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');                      // ISOLATION
            $table->string('actor')->nullable();            // who asked
            $table->text('sql');
            $table->string('label')->nullable();            // the plain-language question
            $table->integer('targeted_hosts')->default(0);
            $table->integer('responded_hosts')->default(0);
            $table->string('status')->default('running');   // running|finished|failed
            $table->text('error')->nullable();
            $table->json('results')->nullable();            // [ {host, rows:[...]}, ... ]
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_query_runs');
    }
};