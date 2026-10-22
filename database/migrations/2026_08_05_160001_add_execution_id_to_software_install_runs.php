<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // REAL GAP FOUND before this ever caused real confusion: matches
        // role_setup_runs' own real, established pattern (fleet_execution_id)
        // -- without it, there was genuinely no way to poll Fleet for the
        // real, actual result of a launched install.
        Schema::table('software_install_runs', function (Blueprint $table) {
            $table->string('fleet_execution_id')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('software_install_runs', function (Blueprint $table) {
            $table->dropColumn('fleet_execution_id');
        });
    }
};
