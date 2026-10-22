<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_payments', function (Blueprint $table) {
            $table->foreignUuid('plan_id')->nullable()->after('subscription_id')
                  ->constrained('backup_plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('backup_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
        });
    }
};
