<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // REAL, SERIOUS GAP FOUND (Brian's own direct observation): a
        // stored credential was keyed only by (tenant_id, role_group) --
        // this was a real, DELIBERATE v1 simplification (per the
        // original migration's own comment), not an oversight, but
        // confirmed directly this genuinely breaks in practice as soon
        // as a tenant has TWO devices running the same role with
        // DIFFERENT accounts (real MSSQL testing hit this exactly).
        // Adding fleet_host_id, NULLABLE for backward compatibility --
        // an existing row with a null fleet_host_id becomes a real,
        // honest "shared/default" fallback, used only when no
        // device-specific credential exists.
        Schema::table('tenant_role_credentials', function (Blueprint $table) {
            $table->unsignedBigInteger('fleet_host_id')->nullable()->after('role_group');
        });
        Schema::table('role_credential_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('fleet_host_id')->nullable()->after('role_group');
        });

        // REAL BUG CAUGHT before this migration ran: the ORIGINAL table
        // already has a real unique constraint on (tenant_id,
        // role_group) ALONE -- confirmed directly in its own creation
        // migration. Left in place, that constraint would still block
        // inserting a second, device-specific row for the same
        // (tenant, role) even after adding a new, wider constraint
        // below. Must drop the old one first.
        Schema::table('tenant_role_credentials', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'role_group']);
        });
        Schema::table('tenant_role_credentials', function (Blueprint $table) {
            $table->unique(['tenant_id', 'role_group', 'fleet_host_id'], 'tenant_role_cred_host_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_role_credentials', function (Blueprint $table) {
            $table->dropUnique('tenant_role_cred_host_unique');
        });
        Schema::table('tenant_role_credentials', function (Blueprint $table) {
            $table->unique(['tenant_id', 'role_group']);
            $table->dropColumn('fleet_host_id');
        });
        Schema::table('role_credential_tokens', function (Blueprint $table) {
            $table->dropColumn('fleet_host_id');
        });
    }
};
