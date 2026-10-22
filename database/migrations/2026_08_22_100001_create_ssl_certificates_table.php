<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // REAL FEATURE 2026-08-22: SSL Certificates, real, auto-renewing
        // Let's Encrypt certs for tenant-owned domains, via acme-dns +
        // Certbot's DNS-01 challenge. Real mechanism (acme-dns
        // registration, CNAME delegation, Certbot issuance and
        // auto-renewal) fully proven end to end against a real test
        // domain (ssltest.obaits.com) before this table/UI existed.
        Schema::create('ssl_certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('domain');
            // Real acme-dns account credentials for THIS domain --
            // needed by Certbot's own auth hook on every future
            // renewal, not just initial issuance.
            $table->string('acme_dns_subdomain');
            $table->string('acme_dns_username');
            $table->string('acme_dns_password');
            $table->string('acme_dns_fulldomain');
            // pending_dns: CNAME instructions shown, not yet verified
            // verified: CNAME confirmed resolving correctly
            // issued: real certificate successfully obtained
            // failed: a real attempt failed
            $table->string('status')->default('pending_dns');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->unique(['tenant_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssl_certificates');
    }
};
