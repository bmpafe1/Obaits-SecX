<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On-premise installations of this platform.
 *
 * A customer who installs from the ISO runs the platform on their own hardware.
 * They still need the Marketplace and Training catalogues, which are curated
 * centrally, and their marketplace requests still need to reach us -- referrals
 * are the product, and we want to see them regardless of how a customer is
 * deployed.
 *
 * SELF-REGISTRATION on first run, no approval step. The ISO is AGPL and meant
 * to be freely installable, so requiring a token issued in advance would work
 * against that. The catalogue is public information anyway, and the only thing
 * an installation can POST is a marketplace request -- which arrives in our
 * inbox with us in copy, so anything abusive is visible immediately.
 *
 * An installation can be SUSPENDED afterwards, which is the control that
 * matters: it is easier to stop a bad actor than to vet everyone in advance.
 *
 * PRIVACY: this record is deliberately thin. A stable anonymous id, a version,
 * and when it last called home. NO customer identity, no hostnames, no
 * addresses -- an on-premise customer chose that deployment precisely so their
 * data stays theirs, and the registration record should not quietly undo that.
 * The organisation name is optional and self-declared, for our own support
 * purposes only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Presented by the installation on every call. Hashed, like every
            // other credential this platform issues.
            $table->string('token_hash')->unique();

            // Optional and self-declared, for support. Never required.
            $table->string('organisation_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('country', 2)->nullable();

            $table->string('version')->nullable();       // platform version reported at sync
            $table->string('status')->default('active'); // active | suspended

            $table->timestamp('registered_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_catalogue_sync_at')->nullable();

            $table->integer('requests_forwarded')->default(0);

            $table->timestamps();

            $table->index('status');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installations');
    }
};
