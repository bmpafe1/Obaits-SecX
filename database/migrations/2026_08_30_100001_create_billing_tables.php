<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing: invoices, subscription payments, and a change log.
 *
 * Deliberately mirrors the backup billing shape (backup_payments,
 * tenant_backup_subscriptions) so there is ONE mental model rather than two.
 * Anyone who understands how backup storage is charged should recognise this
 * immediately.
 *
 * CONTEXT: asset_limit is now enforced at enrolment (2026-08-29), but nothing
 * charges for the platform and trial_ends_at is written and never read -- so a
 * trial today never ends and nobody is ever asked to pay. This is the schema
 * that makes both possible.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- invoices -------------------------------------------------------
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Sequential PER TENANT, as tickets and marketplace requests are.
            // A customer should see #1, #2, #3 -- not a UUID -- and their
            // accountant will expect a sequence.
            $table->integer('number');

            // subscription | capacity | adjustment
            $table->string('kind')->default('subscription');

            // draft | issued | paid | overdue | void
            $table->string('status')->default('draft');

            $table->unsignedInteger('amount_usd_cents');

            // Device count, unit price, period covered, proration basis. Held
            // as JSON because an invoice must show its own arithmetic -- an
            // unexplained number on an invoice is a support ticket.
            $table->json('line_items')->nullable();

            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Voided rather than deleted -- see voided_at below.
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        // --- subscription_payments -----------------------------------------
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->string('provider');                 // flutterwave | ...
            $table->string('provider_reference');
            $table->unsignedInteger('amount_usd_cents');
            $table->string('status')->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->text('raw_payload')->nullable();

            $table->timestamps();

            // Webhooks fire more than once. Copied verbatim in spirit from
            // backup_payments: this makes a double-charge a database error
            // rather than a silent duplicate.
            $table->unique(['provider', 'provider_reference']);
        });

        // --- subscription_changes ------------------------------------------
        Schema::create('subscription_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->integer('old_asset_limit')->nullable();
            $table->integer('new_asset_limit')->nullable();
            $table->string('old_tier')->nullable();
            $table->string('new_tier')->nullable();
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();

            // Why, and who or what did it. Answers "why can this tenant enrol
            // 15 devices" months later, which neither the subscription row nor
            // the invoice alone can.
            $table->string('reason');
            $table->string('actor')->nullable();        // email, 'system', or 'operator'

            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'created_at']);
        });

        // --- subscriptions gains state -------------------------------------
        //
        // ONLY status is added. grace_ends_at, last_reminder_at and
        // last_reminder_stage are ALREADY on this table, and billing_cycle
        // already covers what an earlier draft of this migration called
        // billing_period. Adding them again failed with a duplicate-column
        // error, which is how they were discovered.
        //
        // Those columns are vestigial here: the live grace machinery reads
        // tenant_backup_subscriptions (EnforceBackupSubscriptions) and
        // license_cache (EnforceLicences), NOT this table. They are reused
        // rather than duplicated, so platform billing does not end up with a
        // second grace column beside an unused one.
        Schema::table('subscriptions', function (Blueprint $table) {
            // tier conflates the PLAN with its STATE. A subscription can be on
            // a paid tier and past due; those are different facts.
            //
            // trialing | active | past_due | suspended | cancelled
            $table->string('status')->default('trialing');
        });

        // Existing rows are all trials.
        DB::table('subscriptions')->where('tier', 'trial')->update(['status' => 'trialing']);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['status']);
        });
        Schema::dropIfExists('subscription_changes');
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('invoices');
    }
};
