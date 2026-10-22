<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cve_severity_cache', function (Blueprint $table) {
            $table->string('cve')->primary();
            $table->decimal('score', 3, 1)->nullable();
            $table->string('severity')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cve_severity_cache');
    }
};
