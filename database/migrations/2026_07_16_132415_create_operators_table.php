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
        Schema::create('operators', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('email')->unique();
        $table->string('name');
        $table->string('password');                 // bcrypt
        $table->string('mfa_secret')->nullable();   // TOTP; set on first login
        $table->boolean('mfa_confirmed')->default(false);
        $table->timestamp('last_login_at')->nullable();
        $table->boolean('active')->default(true);
        $table->timestamps();
    });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operators');
    }
};
