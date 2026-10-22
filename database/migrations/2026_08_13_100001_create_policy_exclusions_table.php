<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Scoped per ASSIGNMENT, not per policy globally -- excluding a
        // device from Firewall Enable on the Linux Server group should
        // NOT automatically exclude it from OS Update Enforcement on the
        // same group; these are independent, deliberate decisions an
        // admin makes one at a time. Real motivation: this week's own
        // incident, where a test assignment swept a real production
        // device (mail.aicexperts.com) into a real enforce action with
        // no way to have excluded it in advance.
        //
        // hostname is denormalized (also present on device_group_members
        // and policy_runs) purely so the exclusions list can render
        // without an extra join back to Fleet -- same pattern already
        // used on policy_runs itself.
        Schema::create('policy_exclusions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('policy_assignment_id');
            $table->foreign('policy_assignment_id')->references('id')->on('policy_assignments')->cascadeOnDelete();
            $table->unsignedBigInteger('fleet_host_id');
            $table->string('hostname')->nullable();
            $table->text('reason')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->unique(['policy_assignment_id', 'fleet_host_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_exclusions');
    }
};
