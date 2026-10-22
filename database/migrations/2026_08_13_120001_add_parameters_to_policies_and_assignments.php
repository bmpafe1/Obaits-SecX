<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // REAL FEATURE 2026-08-13: the first policy needing genuine
        // tenant-supplied input (IP Block, Website Block -- every policy
        // before this, Firewall Enable and OS Update, was parameter-free).
        //
        // requires_parameters signals the UI to show a list-entry field
        // at assignment time; deliberately a simple boolean rather than a
        // richer parameters_schema (deferred back on 07 August as
        // premature before a single real parameterized policy existed --
        // still true, now that one actually does, keep it simple until a
        // second, differently-shaped parameter need arises).
        Schema::table('policies', function (Blueprint $table) {
            $table->boolean('requires_parameters')->default(false)->after('non_compliant_prefix');
        });

        // Real, deliberate scope choice (confirmed with Brian): ONE
        // shared list per ASSIGNMENT, not per device -- every device the
        // assignment covers gets the identical block list. Stored as
        // real JSON text, never interpolated into a shell/PowerShell
        // trigger string (that already exists and only ever carries OUR
        // OWN controlled values like 'firewall_disabled' -- literal,
        // tenant-typed text is a genuinely different, real injection
        // risk). Fetched by the device itself via a new, separate
        // authenticated route instead -- see /enroll/policy-params.
        Schema::table('policy_assignments', function (Blueprint $table) {
            $table->text('parameters')->nullable()->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->dropColumn('requires_parameters');
        });
        Schema::table('policy_assignments', function (Blueprint $table) {
            $table->dropColumn('parameters');
        });
    }
};
