<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waf_deployments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('label');                          // "Internet banking"
            $table->string('hostname');                       // banking.example.com
            $table->string('server_type');                    // nginx | apache | iis
            $table->string('deploy_path');                    // modsec3_nginx | modsec2_apache | nginx_proxy | modsec2_iis
            $table->string('mode')->default('pending');       // pending | detection | blocking
            $table->string('crs_version')->nullable();
            $table->boolean('from_fleet')->default(false);
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->index('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waf_deployments');
    }
};