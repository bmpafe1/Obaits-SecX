<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security training catalogue.
 *
 * CURATED BY THE OPERATOR, exactly like the Marketplace and for the same
 * reason: these are links to third-party courses shown under our brand, and one
 * misleading or dead listing damages trust in the rest. Tenants browse; they do
 * not contribute.
 *
 * On-premise appliances SYNC this read-only from the SaaS, so a local user
 * cannot add or edit listings either.
 *
 * LANGUAGE IS THE INTERESTING FIELD. A training in a language the tenant does
 * not read is worse than useless -- it is noise that makes the catalogue look
 * irrelevant. The default filter shows courses in the tenant's own preferred
 * language, plus English, widened by the languages of their country: a
 * Cameroonian sees French and English, a Spaniard sees Spanish and English. A
 * filter lets them see everything regardless.
 *
 * languages is an ARRAY because a course may genuinely be offered in several.
 *
 * COST is numeric with a currency rather than free text, so it can be filtered
 * and sorted. Zero means free, which is a meaningful and common case here --
 * much security awareness material is published at no charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // security_awareness | professional_certification | other
            $table->string('category');

            $table->string('title');
            $table->text('description');

            // Who runs it -- SANS, Coursera, a local training firm. Not a
            // marketplace_provider: these are links out, not referral targets.
            $table->string('institution')->nullable();
            $table->text('url');

            // Language codes. A course may be offered in more than one.
            $table->json('languages');

            // 0 means free. Currency is required whenever cost is set so a
            // number is never ambiguous.
            $table->decimal('cost', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();

            $table->string('duration')->nullable();      // "2 days", "6 weeks", free text
            $table->string('level')->nullable();         // beginner | intermediate | advanced

            // draft | published | suspended -- same vocabulary as marketplace
            // services, so an operator learns one set of rules rather than two.
            $table->string('status')->default('draft');

            $table->timestamps();

            $table->index(['status', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainings');
    }
};
