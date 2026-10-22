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
        Schema::create('license_cache', function (Blueprint $table) {
        $table->uuid('tenant_id')->primary();
        $table->string('admin_email');
        $table->date('expires_on')->nullable();
        $table->timestamp('grace_ends_at')->nullable();
        $table->string('last_reminder_stage')->nullable();
        $table->timestamps();
    });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('license_cache');
    }
};
