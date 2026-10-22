<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STUN port for each tenant's embedded DERP relay.
 *
 * WHY: confirmed live 2026-08-25 that EVERY peer on every tenant tailnet is
 * relayed -- not one direct connection across seven devices in four DERP
 * regions. The cause is that Headscale ships the embedded DERP server disabled
 * and serves Tailscale's PUBLIC derp map instead, so the scanner relays via
 * Dallas while Cameroon devices relay via London. A scan of one host took 2.5
 * hours, against 6 minutes for the same scan against localhost.
 *
 * netcheck reports MappingVariesByDestIP: false on both ends -- endpoint-
 * independent NAT, the favourable case -- so direct connections SHOULD be
 * achievable. Running our own STUN is what lets clients discover their external
 * addresses and attempt NAT traversal.
 *
 * PER-TENANT, not shared. Headscale's embedded DERP verifies clients against
 * ITS OWN instance and denies everyone else, so one shared relay would reject
 * every tenant but the one hosting it. Per-tenant also preserves the isolation
 * model used everywhere else on this platform.
 *
 * Follows the existing +10 allocation scheme: 3478, 3488, 3498, 3508.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('headscale_instances', function (Blueprint $table) {
            $table->integer('port_stun')->nullable();
        });

        // Backfill the four existing instances from their already-allocated
        // port_main offset, so the series stays consistent with every other
        // port block rather than being assigned arbitrarily.
        foreach (DB::table('headscale_instances')->get(['id', 'port_main']) as $i) {
            DB::table('headscale_instances')
                ->where('id', $i->id)
                ->update(['port_stun' => 3478 + ($i->port_main - 8081)]);
        }
    }

    public function down(): void
    {
        Schema::table('headscale_instances', function (Blueprint $table) {
            $table->dropColumn('port_stun');
        });
    }
};
