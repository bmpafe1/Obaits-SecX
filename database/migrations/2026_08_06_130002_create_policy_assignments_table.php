<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // mode is deliberately constrained to off|audit in this slice --
        // there is NO enforce code path anywhere yet, so a device cannot
        // be changed even by a bug here. Enforce, exclusions, and the
        // kill switch are a later, separate pass once audit is proven
        // against real devices.
        Schema::create('policy_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('policy_id');
            $table->foreign('policy_id')->references('id')->on('policies')->cascadeOnDelete();
            $table->unsignedInteger('policy_version');
            $table->uuid('device_group_id');
            $table->foreign('device_group_id')->references('id')->on('device_groups')->cascadeOnDelete();
            $table->string('mode')->default('off'); // off|audit
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'policy_id', 'device_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_assignments');
    }
};
