<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // REAL BUG FOUND 2026-08-07: /enroll/policy-script had no way to
        // know which platform's script to serve -- it used a plain
        // check_script_windows ?: check_script_linux fallback, so ANY
        // Linux device ran the Windows PowerShell script the moment both
        // existed for a policy (confirmed live: mail.aicexperts.com's
        // bash trigger fetched and eval'd PowerShell, failing loudly with
        // "eval: syntax error near unexpected token '('"). Storing the
        // real platform on the run itself, set once at launch time by
        // PolicyRunner (which already knows it), removes the guess.
        Schema::table('policy_runs', function (Blueprint $table) {
            $table->string('platform')->nullable()->after('hostname');
        });
    }

    public function down(): void
    {
        Schema::table('policy_runs', function (Blueprint $table) {
            $table->dropColumn('platform');
        });
    }
};
