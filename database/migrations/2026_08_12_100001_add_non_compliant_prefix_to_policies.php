<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // REAL GENERALIZATION FIX 2026-08-12: 'firewall_disabled' was
        // hardcoded as a literal PHP string in five separate places
        // (GroupPolicies.php x4, ReconcilePolicyEnforcement.php x1) --
        // fine while Firewall Enable was the only real policy, but a
        // real, immediate blocker for a second policy with a genuinely
        // different result vocabulary (OS Update reports
        // 'updates_pending:N' vs 'up_to_date', not an enabled/disabled
        // binary). Each policy now DECLARES what its own non-compliant
        // result looks like; every read site does a prefix match against
        // this column instead of an exact hardcoded string.
        Schema::table('policies', function (Blueprint $table) {
            $table->string('non_compliant_prefix')->nullable()->after('reversibility');
        });

        // Backfill the one existing real policy so it keeps working
        // identically -- this migration must never change Firewall
        // Enable's own live behaviour, only add the mechanism a second
        // policy needs.
        DB::table('policies')->where('resource_key', 'firewall.enabled')
            ->update(['non_compliant_prefix' => 'firewall_disabled']);
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->dropColumn('non_compliant_prefix');
        });
    }
};
