<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remembers problems the reconciler has already reported, so an issue that
        // persists for a week is one alert plus an age, not 168 identical emails.
        Schema::create('agent_reconcile_state', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable();      // null = platform-wide (e.g. certificate)
            $table->string('problem_key');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_alerted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'problem_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_reconcile_state');
    }
};