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
        Schema::table('findings', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->text('url')->nullable();      // the exact URL ZAP attacked
            $table->string('param')->nullable();  // the parameter it injected into
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            //
        });
    }
};
