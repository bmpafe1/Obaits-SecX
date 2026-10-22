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
        Schema::table('subscriptions', function (Blueprint $table) {
        $table->string('license_key')->nullable()->after('tier');
        $table->string('admin_email')->nullable();          // who to remind
        $table->date('purchased_at')->nullable();
        $table->timestamp('grace_ends_at')->nullable();      // set when expiry passes
        $table->timestamp('last_reminder_at')->nullable();   // de-dupe reminders
        $table->string('last_reminder_stage')->nullable();   // '30' | '20' | '7' | 'expired'
    });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            //
        });
    }
};
