<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenant scanner pool state.
 *
 * WHY THIS EXISTS: vulnerability scanning cannot safely share one scanner
 * across tenants. Every tenant has its own Headscale instance allocating from
 * 100.64.0.0/10, so the same address legitimately exists in several tenants at
 * once. A scanner holding routes into more than one tenant network could probe
 * the wrong customer's device -- an active vulnerability scan against a third
 * party who never authorised it.
 *
 * The design (see the Scanner Pool Design document, 2026-08-25) is that a
 * member joins EXACTLY ONE tenant network at a time, for the duration of one
 * scan, and leaves afterwards. Isolation is then a property of the network
 * rather than of configuration: the scanner cannot reach another tenant because
 * it has no route, not because code remembered to check.
 *
 * These tables are what makes that enforceable. Without them the platform has
 * no idea which member is joined to which tenant, and the guarantee is a
 * comment rather than a constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scanner_pool_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();          // 'pool-1'

            // Registration in GVM. Recorded because create_scanner returns them
            // once and nothing else knows them afterwards.
            $table->uuid('gvm_scanner_id');
            $table->uuid('gvm_credential_id');
            $table->string('host');                    // container hostname; MUST match the cert CN
            $table->integer('port')->default(9391);

            // idle | joining | scanning | releasing | failed | disabled
            //
            // failed and disabled are deliberately distinct: failed means
            // something broke and the reconciler should try to recover it,
            // disabled means an operator took it out of service on purpose.
            // Conflating them means automation fights the operator.
            $table->string('state')->default('idle');

            // ONE scan at a time, by design. current_tenant_id being set while
            // state is 'idle' is a bug worth alerting on -- it would mean a
            // member is still joined to a tenant network it should have left.
            $table->uuid('current_tenant_id')->nullable();
            $table->uuid('current_scan_id')->nullable();

            // Staleness. This is the THIRD place on this platform needing it,
            // after policy runs and scans -- both of which sat 'running'
            // forever when their worker died. Built in from the start here
            // rather than retrofitted after an outage.
            $table->timestamp('allocated_at')->nullable();

            // verify_scanner is cheap and proves the TLS path still works. A
            // member failing verification must not be allocated.
            $table->timestamp('last_verified_at')->nullable();
            $table->string('last_verify_status')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->unique('gvm_scanner_id');
            $table->index('state');
        });

        Schema::create('scanner_pool_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // DENORMALISED DELIBERATELY, not a foreign key. Autoscaling will
            // destroy members routinely, and this history must survive that --
            // "which scanner was joined to which tenant, and when" is exactly
            // what an auditor or an incident investigation asks for. A
            // cascading delete would erase precisely the record needed.
            $table->string('member_name');

            $table->uuid('tenant_id');
            $table->uuid('scan_id')->nullable();

            $table->timestamp('joined_at');
            $table->timestamp('released_at')->nullable();

            // completed | failed | timeout | cancelled | forced
            $table->string('release_reason')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index('tenant_id');
            $table->index('joined_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scanner_pool_allocations');
        Schema::dropIfExists('scanner_pool_members');
    }
};
