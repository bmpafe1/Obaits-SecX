<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Same shape as software_install_runs, deliberately -- attempts,
        // fleet_execution_id, finished_at all mean the same thing. 'result'
        // is new: the check script's real findings, POSTed back by the
        // device rather than trusted from Fleet's own captured stdout,
        // which is documented elsewhere in this project to silently
        // truncate. 'output' is kept only as a diagnostic fallback.
        Schema::create('policy_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('policy_assignment_id');
            $table->foreign('policy_assignment_id')->references('id')->on('policy_assignments')->cascadeOnDelete();
            $table->unsignedBigInteger('fleet_host_id');
            $table->string('hostname')->nullable();
            $table->string('phase')->default('check'); // check only, this slice
            $table->string('status')->default('pending'); // pending|running|success|failed
            $table->unsignedInteger('attempts')->default(0);
            $table->string('fleet_execution_id')->nullable();
            $table->text('result')->nullable();
            $table->text('output')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['policy_assignment_id', 'fleet_host_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_runs');
    }
};
