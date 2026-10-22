<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // REAL FEATURE 2026-08-13: the kill switch, from the original
        // 07 August design notes (enforcement_paused_at). Deliberately
        // per-TENANT, per Brian's explicit choice -- a tenant admin's
        // own "stop everything my policies are doing right now" button,
        // not a per-assignment toggle (Disable already exists for that)
        // and not a platform-wide operator control (out of scope here).
        //
        // Deliberately does NOT touch policy_assignments.mode or run any
        // revert logic when paused -- this suspends FUTURE action only.
        // An assignment already in 'enforce' stays exactly as it is;
        // resuming picks back up from that same state. Pausing is meant
        // to buy a moment to investigate something that looks wrong,
        // not to trigger a second, separate risky action (mass revert)
        // on top of whatever already prompted the pause.
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('policy_enforcement_paused_at')->nullable()->after('status');
            $table->string('policy_enforcement_paused_by')->nullable()->after('policy_enforcement_paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['policy_enforcement_paused_at', 'policy_enforcement_paused_by']);
        });
    }
};
