<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_backup_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained('tenant_backup_connections')->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained('backup_plans');

            $table->unsignedInteger('gb_purchased');          // IDrive quota unit
            $table->unsignedInteger('price_paid_usd_cents');

            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->string('status')->default('active');      // active|grace|read_only|expired|cancelled
            $table->boolean('auto_renew')->default(false);

            // Mirrors the platform subscriptions reminder pattern.
            $table->string('admin_email')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('last_reminder_at')->nullable();
            $table->string('last_reminder_stage')->nullable(); // '30' | '20' | '7' | 'expired'

            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_backup_subscriptions');
    }
};
