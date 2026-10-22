<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // REAL FEATURE 2026-08-20: External API, deliberately last per
        // Brian's own explicit sequencing (CIS Baseline -> backups ->
        // API). Real, explicit scoping decisions confirmed before
        // building: read/write (external tools can assign policies and
        // trigger scans, not just read data), API-key auth (per-tenant,
        // simple for integrators -- not OAuth2/Keycloak).
        //
        // Only the KEY'S HASH is ever stored -- same security discipline
        // as every credential in this project. The real, full key is
        // shown to the user exactly once, at creation time, and is
        // never recoverable afterward (matching Stripe/GitHub/every
        // real API-key convention) -- if lost, the only real recovery
        // path is revoking and creating a new one.
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name'); // human-readable label the tenant gives it
            $table->string('key_prefix', 12); // first few real chars, shown in the UI so a tenant can identify which key is which without ever re-displaying the secret
            $table->string('key_hash'); // sha256 of the full real key -- never the plaintext
            $table->string('created_by')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('key_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
