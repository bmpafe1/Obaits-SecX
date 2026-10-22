<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A verdict a tenant cannot inspect is a claim they have to take on
        // trust. Store WHY we concluded what we did, and which pages the
        // authenticated crawl actually reached, so the result can be checked.
        Schema::table('zap_auth_profiles', function (Blueprint $table) {
            $table->string('last_verify_reason')->nullable()->after('last_new_paths');
            $table->integer('last_anon_paths')->nullable()->after('last_verify_reason');
            $table->json('last_sample')->nullable()->after('last_anon_paths');
        });
    }

    public function down(): void
    {
        Schema::table('zap_auth_profiles', function (Blueprint $table) {
            $table->dropColumn(['last_verify_reason', 'last_anon_paths', 'last_sample']);
        });
    }
};