<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 15-minute TTL matches role_script_tokens exactly -- this first
        // slice is immediate-trigger only (the device is assumed online
        // right now), same scoping decision already made for software
        // push before its own reconciler existed. A device_credentials-
        // backed long-offline path is a deliberate later addition, not
        // bundled into this slice.
        Schema::create('policy_script_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('token', 64)->unique();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('policy_run_id');
            $table->foreign('policy_run_id')->references('id')->on('policy_runs')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_script_tokens');
    }
};
