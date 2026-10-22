<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // REAL FEATURE 2026-08-21: CCTV Monitoring (lightNVR), one
        // dedicated instance per tenant -- confirmed as the correct,
        // vendor-recommended pattern for this exact MSP use case (their
        // own "LightNVR Cloud" offering does the same: one isolated
        // instance per client, since lightNVR itself has no internal
        // multi-tenant separation the way Fleet/Wazuh/IRIS do).
        Schema::create('cctv_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique(); // one instance per tenant, enforced at the schema level
            $table->string('subdomain'); // e.g. "aicexperts-cameras.obaits.com"
            $table->unsignedInteger('web_port'); // the real, allocated host port for the web UI (8092, 8093, ...)
            $table->string('container_name');
            $table->string('status')->default('provisioning'); // provisioning | running | stopped | failed
            $table->timestamps();

            $table->index('web_port');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cctv_instances');
    }
};
