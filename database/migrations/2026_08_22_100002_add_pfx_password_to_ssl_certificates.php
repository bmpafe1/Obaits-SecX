<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // REAL FEATURE 2026-08-22, requested directly: additional real
        // certificate formats beyond PEM (Certbot already produces
        // cert.pem/chain.pem alongside fullchain.pem/privkey.pem -- no
        // conversion needed there). DER and PKCS12/PFX need real
        // conversion via openssl. PFX bundles the private key, so it
        // needs a real, randomly-generated password -- matching this
        // project's own established security discipline (never an
        // empty/guessable password for anything bundling a private key).
        Schema::table('ssl_certificates', function (Blueprint $table) {
            $table->string('pfx_password')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('ssl_certificates', function (Blueprint $table) {
            $table->dropColumn('pfx_password');
        });
    }
};
