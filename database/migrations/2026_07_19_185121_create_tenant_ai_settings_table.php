<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A tenant's own AI provider and key.
        //
        // Two reasons this exists: AI cost scales with a tenant's usage and not
        // with their subscription, so unlimited use of the platform's key is an
        // unbounded liability; and a tenant may have a legitimate preference
        // about which company processes their risk register and compliance
        // content.
        //
        // The key is encrypted at rest and never returned to the browser once
        // saved. A tenant re-enters it to change it.
        Schema::create('tenant_ai_settings', function (Blueprint $table) {
            $table->uuid('tenant_id')->primary();
            $table->string('provider', 32)->default('platform');   // platform|deepseek|claude
            $table->text('api_key')->nullable();                   // ENCRYPTED
            $table->string('model')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->string('last_verify_result')->nullable();       // ok|invalid_key|no_credit|unreachable
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_ai_settings');
    }
};