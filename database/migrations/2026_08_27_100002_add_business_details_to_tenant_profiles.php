<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A short description of what the tenant's business does, plus a marker for
 * when the first-login business-detail step was completed.
 *
 * WHY A DESCRIPTION: a provider receiving a marketplace referral needs context
 * to qualify it, and sector plus headcount alone do not convey what a business
 * actually does. Paired deliberately: the structured fields are what a provider
 * can FILTER on, the description is what a human reads, and neither substitutes
 * for the other.
 *
 * WHY AT FIRST LOGIN rather than registration: signup already asks for six
 * things, and every additional required field costs conversions on a form
 * someone completes before they have seen the product. Asking once they are
 * committed gets better answers and fewer abandoned signups.
 *
 * business_details_completed_at exists so the step is asked exactly ONCE. A
 * prompt that reappears is a prompt people learn to dismiss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->timestamp('business_details_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table) {
            $table->dropColumn(['description', 'business_details_completed_at']);
        });
    }
};
