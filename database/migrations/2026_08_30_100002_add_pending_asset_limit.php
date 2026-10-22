<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A device reduction that takes effect at the end of the current period.
 *
 * WHY NOT APPLY IT IMMEDIATELY: the customer has paid for their current devices
 * until the period ends. Taking them away the moment they ask to reduce would
 * be taking back something already bought, and could refuse an enrolment they
 * are entitled to make.
 *
 * WHY NOT CHARGE FOR IT: there is nothing to charge. Until 2026-08-30 a
 * reduction was quoted as a full new period at the lower count, so going from
 * 21 devices to 10 on day 25 cost $30 immediately AND forfeited the five
 * remaining days of the 21 devices already paid for. That was simply wrong.
 *
 * Read at renewal by EnforceBilling, which invoices the lower count and clears
 * this back to null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->integer('pending_asset_limit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('pending_asset_limit');
        });
    }
};
