<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scans', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->string('scan_type')->default('network')->after('mode');
        });
        Schema::table('findings', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->string('scan_type')->default('network')->after('tenant_id');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scans', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->dropColumn('scan_type');
        });
        Schema::table('findings', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->dropColumn('scan_type');
        });

    }
};
