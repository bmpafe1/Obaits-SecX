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
        Schema::create('license_fetch_state', function (Blueprint $table) {
        $table->id();
        $table->timestamp('last_fetch_ok_at')->nullable();
        $table->timestamp('last_attempt_at')->nullable();
        $table->string('last_error')->nullable();
        $table->integer('consecutive_failures')->default(0);
    });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('license_fetch_state');
    }
};
