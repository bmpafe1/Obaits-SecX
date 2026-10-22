<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_role_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('role_group'); // e.g. 'mysql-db' — ONE credential set per (tenant, role),
                                           // not per-host: a real v1 simplification (most tenants use
                                           // one admin credential per database engine across their
                                           // infrastructure), stated explicitly rather than assumed silently.
            $table->string('username');
            $table->text('encrypted_password');
            $table->timestamps();

            $table->unique(['tenant_id', 'role_group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_role_credentials');
    }
};
