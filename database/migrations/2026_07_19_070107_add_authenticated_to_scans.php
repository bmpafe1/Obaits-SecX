<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether this web scan actually got past the site's login.
        //
        // Without this, a scan that returns three findings is ambiguous: the site
        // may be clean, or the login may have quietly stopped working — an expired
        // password, a changed login page — and the scan only tested the public
        // shell. A tenant cannot tell those apart, and the second is dangerous
        // precisely because it looks like good news.
        //
        // none = no login configured, yes = scanned logged in,
        // failed = a login was configured but did not work, so this is a public scan
        Schema::table('scans', function (Blueprint $table) {
            $table->string('authenticated', 16)->default('none')->after('scan_type');
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn('authenticated');
        });
    }
};