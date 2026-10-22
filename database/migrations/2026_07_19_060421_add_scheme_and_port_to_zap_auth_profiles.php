<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // tenant_domains stores a hostname only. Scheme and port belong to the
        // scan profile: they do not change WHICH host may be scanned, which is
        // the property domain verification exists to enforce.
        Schema::table('zap_auth_profiles', function (Blueprint $table) {
            $table->string('scheme', 8)->default('https')->after('tenant_id');
            $table->unsignedInteger('port')->nullable()->after('scheme');
        });
    }

    public function down(): void
    {
        Schema::table('zap_auth_profiles', function (Blueprint $table) {
            $table->dropColumn(['scheme', 'port']);
        });
    }
};