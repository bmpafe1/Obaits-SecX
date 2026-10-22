<?php

namespace App\Console\Commands;

use App\Clients\FleetClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncHostNetworkInterfaces extends Command
{
    protected $signature = 'obaits:sync-network-interfaces';
    protected $description = 'Sync real, per-interface network addresses for every tenant device via Fleet\'s scheduled-query report (NOT the cached, sometimes-fabricated public_ip/primary_ip fields)';

    public function handle(FleetClient $fleet): int
    {
        $queryId = $fleet->ensureNetworkInterfaceQuery();
        $this->info("Using Fleet query id: {$queryId}");

        $report = $fleet->fetchNetworkInterfaceReport($queryId);
        $this->info('Fetched ' . count($report) . ' real interface rows from Fleet.');

        // Real report rows are global (every host Fleet knows about), not
        // tenant-scoped. Build a real host_id -> tenant_id map first, using
        // the SAME per-tenant Fleet label mechanism already proven correct
        // for hostsForTenant() elsewhere in this project, rather than trust
        // anything in the report rows themselves for tenant attribution.
        $tenants = DB::table('tenants')->pluck('id');
        $hostToTenant = [];

        foreach ($tenants as $tenantId) {
            $secret = DB::table('tenant_artifacts')
                ->where('tenant_id', $tenantId)
                ->where('tool', 'fleet')
                ->where('artifact_type', 'enroll_secret')
                ->value('external_id');

            if (!$secret) continue;

            $hosts = $fleet->hostsForTenant($secret);
            foreach ($hosts as $h) {
                if (isset($h['id'])) {
                    $hostToTenant[(int) $h['id']] = $tenantId;
                }
            }
        }

        $now = now();
        $seenKeys = [];
        $upserted = 0;

        foreach ($report as $row) {
            $hostId = (int) ($row['host_id'] ?? 0);
            $tenantId = $hostToTenant[$hostId] ?? null;
            if (!$tenantId) continue; // real host Fleet knows about, but not attributed to any tenant we manage

            $iface = $row['columns']['interface'] ?? null;
            $addr  = $row['columns']['address'] ?? null;
            if (!$iface || !$addr) continue;

            $hostname = $row['host_name'] ?? '';
            $lastFetched = $row['last_fetched'] ?? $now;

            DB::table('host_network_interfaces')->updateOrInsert(
                [
                    'fleet_host_id'  => $hostId,
                    'interface_name' => $iface,
                    'ip_address'     => $addr,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'hostname' => $hostname,
                    'last_fetched_at' => $lastFetched,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $seenKeys[] = [$hostId, $iface, $addr];
            $upserted++;
        }

        // Real, current state per device: remove any interface row for a
        // host we DID hear from in this sync that is no longer present
        // (e.g. a Docker bridge network that was removed) -- but never
        // touch rows for a host we simply didn't hear from this cycle
        // (that host may just be offline, not genuinely interface-less).
        $hostsHeardFrom = array_unique(array_column($report, 'host_id'));
        foreach ($hostsHeardFrom as $hostId) {
            $keepAddrs = array_values(array_filter($seenKeys, fn ($k) => $k[0] === (int) $hostId));
            $keepPairs = array_map(fn ($k) => $k[1] . '|' . $k[2], $keepAddrs);

            $existing = DB::table('host_network_interfaces')
                ->where('fleet_host_id', (int) $hostId)
                ->get(['id', 'interface_name', 'ip_address']);

            foreach ($existing as $row) {
                $pair = $row->interface_name . '|' . $row->ip_address;
                if (!in_array($pair, $keepPairs, true)) {
                    DB::table('host_network_interfaces')->where('id', $row->id)->delete();
                }
            }
        }

        $this->info("Upserted {$upserted} real interface rows across " . count($tenants) . " tenants.");
        return self::SUCCESS;
    }
}
