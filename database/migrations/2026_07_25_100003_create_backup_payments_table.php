<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()
                  ->constrained('tenant_backup_subscriptions')->nullOnDelete();

            $table->string('provider');              // paystack|flutterwave|paypal
            $table->string('provider_reference');
            $table->unsignedInteger('amount_usd_cents');
            $table->string('status')->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->text('raw_payload')->nullable();

            $table->timestamps();

            // Webhooks fire more than once. This makes double-provisioning
            // a database error rather than a silent duplicate.
            $table->unique(['provider', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_payments');
    }
};
