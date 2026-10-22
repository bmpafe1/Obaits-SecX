<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable by design: a policy with no remediate/revert script for
        // a platform simply cannot enforce/revert on that platform. This
        // matches the policy's own declared 'reversibility' field rather
        // than assuming every policy is symmetric (some genuinely aren't
        // -- System Update was the example raised during design).
        Schema::table('policies', function (Blueprint $table) {
            $table->text('remediate_script_windows')->nullable()->after('check_script_linux');
            $table->text('remediate_script_linux')->nullable()->after('remediate_script_windows');
            $table->text('revert_script_windows')->nullable()->after('remediate_script_linux');
            $table->text('revert_script_linux')->nullable()->after('revert_script_windows');
        });

        // Captured from the check's own real 'result' the FIRST time
        // remediation runs for a (assignment, host) pair -- reused as-is
        // rather than a separate capture mechanism, since the check
        // result already IS the real prior state for this class of
        // policy. Never overwritten again after that first capture, so
        // a later re-check/re-remediate cycle doesn't lose the original
        // "what was this before we touched it" answer.
        Schema::table('policy_runs', function (Blueprint $table) {
            $table->text('prior_state')->nullable()->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->dropColumn(['remediate_script_windows', 'remediate_script_linux', 'revert_script_windows', 'revert_script_linux']);
        });
        Schema::table('policy_runs', function (Blueprint $table) {
            $table->dropColumn('prior_state');
        });
    }
};
