<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real Phase C: auto-assign newly enrolled devices matching a
        // real Wazuh machine-type group (windows-client, windows-dc,
        // linux-servers, linux-desktop, macos-client -- matching
        // Enroll.php's own real TIER1_GROUPS taxonomy exactly, since
        // that's the same real, established distinction already made
        // at enrollment time and is the finest real granularity
        // available (Fleet's own platform field alone cannot
        // distinguish a workstation from a domain controller).
        Schema::table('device_groups', function (Blueprint $table) {
            $table->string('auto_assign_wazuh_group')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('device_groups', function (Blueprint $table) {
            $table->dropColumn('auto_assign_wazuh_group');
        });
    }
};
