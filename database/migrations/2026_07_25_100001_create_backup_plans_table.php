<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('gb_size');            // IDrive quota unit is GB
            $table->unsignedInteger('price_usd_cents');    // integer cents, never float
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_plans');
    }
};
