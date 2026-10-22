<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real prerequisites for the Phase B safety net.
        // - attempts: without a real attempt counter, an install failing for a
        //   genuine, permanent reason would retry every 15 minutes forever.
        // - finished_at: matches role_setup_runs' own established pattern, and
        //   lets the reconciler age out a run stuck 'running' far longer than
        //   any real install could plausibly take.
        Schema::table('software_install_runs', function (Blueprint $table) {
            $table->unsignedInteger('attempts')->default(0)->after('status');
            $table->timestamp('finished_at')->nullable()->after('output');
        });
    }

    public function down(): void
    {
        Schema::table('software_install_runs', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'finished_at']);
        });
    }
};
