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
        Schema::create('operator_audit', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('operator_id')->nullable();
        $table->string('actor');                    // operator email, captured at the time
        $table->string('action');                   // operator.login, operator.viewed_tenant_data, ...
        $table->uuid('target_tenant_id')->nullable();
        $table->string('reason')->nullable();       // for data access (Layer 2)
        $table->json('detail')->nullable();
        $table->string('ip')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->index(['operator_id', 'created_at']);
        $table->index('target_tenant_id');
    });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operator_audit');
    }
};
