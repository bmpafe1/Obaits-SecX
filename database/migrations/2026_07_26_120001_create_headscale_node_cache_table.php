<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('headscale_node_cache', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('hostname');
            $table->string('ip_v4');
            $table->boolean('online')->default(false);
            $table->timestamp('synced_at');
            $table->timestamps();

            // One row per (tenant, ip) — a re-sync updates in place rather
            // than accumulating stale duplicates.
            $table->unique(['tenant_id', 'ip_v4']);
            $table->index(['tenant_id', 'ip_v4', 'online']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('headscale_node_cache');
    }
};
