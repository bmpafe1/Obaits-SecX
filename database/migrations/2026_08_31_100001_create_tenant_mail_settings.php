<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant's own SMTP server, for the mail that is genuinely theirs.
 *
 * WHICH MAIL THIS SENDS, and the distinction matters:
 *
 *   THEIRS -- security alerts, scan reports, notifications, team invites.
 *   These are about their estate, sent to their people, and arriving from
 *   their own domain is better for deliverability and for trust.
 *
 *   OURS -- registration verification codes (the tenant does not exist yet),
 *   marketplace referrals (the referral is made by Obaits, which is copied in
 *   and may intervene), and anything billing (an invoice from us should come
 *   from us). These stay on the platform sender regardless of this setting.
 *
 * FALLS BACK RATHER THAN FAILING. If a tenant's server rejects or times out,
 * the message is sent from the platform instead and the failure is recorded and
 * surfaced to them. An undelivered security alert is worse than one arriving
 * from an unexpected sender -- but a silent fallback would let a tenant believe
 * their SMTP works for months when it does not.
 *
 * The password is encrypted at rest, following BackupConnections, and is never
 * rendered back into the form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_mail_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete()->unique();

            $table->boolean('enabled')->default(false);

            $table->string('host');
            $table->integer('port')->default(587);
            $table->string('encryption')->default('tls');   // tls | ssl | none
            $table->string('username')->nullable();
            $table->text('password_encrypted')->nullable();

            $table->string('from_address');
            $table->string('from_name')->nullable();

            // Set by the test button. A configuration that has never been
            // proven to work should not be trusted with a security alert.
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->default(false);
            $table->string('last_test_error')->nullable();

            // Recorded when a real send falls back to the platform mailer, so a
            // tenant can be told their server stopped working rather than
            // discovering it themselves.
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_failure_error')->nullable();
            $table->integer('failure_count')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_mail_settings');
    }
};
