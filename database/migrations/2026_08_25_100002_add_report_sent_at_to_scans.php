<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guards against sending a scan report twice.
 *
 * RunScan used to poll to completion inside the job, so the report was sent
 * exactly once by construction. Collection now happens in a SCHEDULED command
 * (obaits:poll-scans) which runs every two minutes, and a scheduled command
 * that emails customers must never be able to send twice -- a status transition
 * alone is not a safe guard, because a crash between sending and updating would
 * resend on the next tick.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->timestamp('report_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn('report_sent_at');
        });
    }
};
