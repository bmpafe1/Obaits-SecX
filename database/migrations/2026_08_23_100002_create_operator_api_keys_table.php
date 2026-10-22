<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // REAL FEATURE 2026-08-23: platform-operator-level API keys.
        // Deliberately a SEPARATE table from tenant api_keys, with NO
        // tenant_id column at all -- matching OperatorAuth's own real,
        // hard guarantee that an operator session must NEVER carry
        // tenant scope. A key stored here is genuinely, deliberately
        // full-power and cross-tenant, same as any real, human operator
        // already has -- confirmed directly with Brian before building,
        // since operators currently have no role/permission granularity
        // at all (every operator is equally, fully privileged).
        Schema::create('operator_api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('operator_id');
            $table->string('name');
            $table->string('key_prefix', 20);
            $table->string('key_hash');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index('operator_id');
            $table->index('key_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_api_keys');
    }
};
