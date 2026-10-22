<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Support\Health\OperatorAlert;

/**
 * Notice when the platform's device records stop matching reality.
 *
 * WHY THIS EXISTS: four separate classes of orphaned record have been found in
 * this codebase, and EVERY ONE was found by accident -- while investigating
 * something else. The most recent, on 2026-08-31, was a duplicate credential
 * created by a test that reported itself as passing.
 *
 * REPORTS, NEVER DELETES. A host can be absent from Fleet for entirely ordinary
 * reasons -- powered off during the check, mid-reinstall, an API timeout -- and
 * removing a customer's credential because their laptop was closed would be far
 * worse than the drift it was meant to fix. An operator decides.
 *
 * COMPARES ON THE SHORT NAME. Windows sends its SHORT hostname while Fleet
 * stores the FQDN; the two forms have diverged NINE times here. Comparing them
 * literally would report drift on every Windows device in the estate.
 */
#[Signature('obaits:check-device-drift')]
#[Description('Report where device credentials and Fleet hosts disagree')]
class CheckDeviceDrift extends Command
{
    public function handle(): int
    {
        try {
            $token = config('services.fleet.api_token');
            $base = rtrim(config('services.fleet.base_url'), '/');
            $hosts = Http::withToken($token)->withoutVerifying()->timeout(20)
                ->get($base . '/api/v1/fleet/hosts')->json('hosts') ?? [];
        } catch (\Throwable $e) {
            // Fleet being unreachable is not drift. Say so and stop -- reporting
            // every device as missing would be worse than reporting nothing.
            $this->warn('Fleet unreachable; no drift check performed: ' . $e->getMessage());
            return self::SUCCESS;
        }

        $fleetShort = [];
        foreach ($hosts as $h) {
            $name = $h['hostname'] ?? '';
            if ($name !== '') {
                $fleetShort[strtolower(explode('.', $name)[0])] = $name;
            }
        }

        $creds = DB::table('device_credentials as d')
            ->join('tenants as t', 't.id', '=', 'd.tenant_id')
            ->whereNull('d.revoked_at')
            ->get(['t.slug', 'd.hostname']);

        $credShort = [];
        foreach ($creds as $c) {
            $credShort[strtolower(explode('.', $c->hostname)[0])] = $c;
        }

        $problems = [];

        // A credential the platform issued for a host Fleet has never seen, or
        // has lost. Could be a decommissioned machine whose credential was
        // never revoked -- which is a device slot the customer is paying for.
        foreach ($credShort as $short => $c) {
            if (!isset($fleetShort[$short])) {
                $this->warn("  credential with no Fleet host: {$c->slug} / {$c->hostname}");
                $problems[] = [
                    'level' => 'warning', 'area' => 'devices',
                    'message' => "Tenant {$c->slug} holds a credential for {$c->hostname}, which Fleet does not know about. "
                               . 'If the device is gone, the credential should be revoked -- it is occupying a device slot.',
                ];
            }
        }

        // A host in Fleet with no credential. It is being managed without the
        // platform having issued it an identity, so removal and rotation would
        // both miss it.
        foreach ($fleetShort as $short => $name) {
            if (!isset($credShort[$short])) {
                $this->warn("  Fleet host with no credential: {$name}");
                $problems[] = [
                    'level' => 'warning', 'area' => 'devices',
                    'message' => "Fleet manages {$name} but no active device credential exists for it. "
                               . 'Removal and credential rotation would both miss this host.',
                ];
            }
        }

        // Duplicates within one tenant -- what the 2026-08-31 bug produced.
        $dupes = DB::table('device_credentials')
            ->whereNull('revoked_at')
            ->selectRaw('tenant_id, lower(split_part(hostname, \'.\', 1)) as short, count(*) as n')
            ->groupBy('tenant_id', 'short')
            ->having(DB::raw('count(*)'), '>', 1)
            ->get();

        foreach ($dupes as $d) {
            $this->error("  duplicate credentials for {$d->short}: {$d->n}");
            $problems[] = [
                'level' => 'critical', 'area' => 'devices',
                'message' => "{$d->n} active credentials exist for '{$d->short}' in one tenant. "
                           . 'One machine is consuming several device slots.',
            ];
        }

        $this->info(count($problems) . ' issue(s) from ' . count($hosts) . ' Fleet host(s) and ' . $creds->count() . ' credential(s).');

        if ($problems) {
            OperatorAlert::send(count($problems) . ' device record discrepancies', $problems);
        }

        return self::SUCCESS;
    }
}
