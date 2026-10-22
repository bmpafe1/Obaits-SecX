<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Operational overview of every tenant's Headscale instance — service
 * health, port bindings, and cached node counts, all in one table instead
 * of checking each tenant one at a time via systemctl.
 *
 * Read-only. Safe to run as any user for the DB-only columns; the
 * systemctl/ss checks need root to see other users' processes accurately,
 * but degrade gracefully (report "unknown") rather than fail outright if
 * run unprivileged.
 */
#[Signature('headscale:status')]
#[Description('Show a one-line health summary for every tenant Headscale instance')]
class HeadscaleStatus extends Command
{
    public function handle(): int
    {
        $instances = DB::table('headscale_instances')->orderBy('slug')->get();

        if ($instances->isEmpty()) {
            $this->info('No Headscale instances provisioned yet.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($instances as $i) {
            $serviceActive = $this->serviceStatus("headscale-{$i->slug}");
            $portListening = $this->isPortListening($i->port_main);

            $nodeCounts = DB::table('headscale_node_cache')
                ->where('tenant_id', $i->tenant_id)
                ->selectRaw('count(*) as total, sum(case when online then 1 else 0 end) as online')
                ->first();

            $keyExpiry = $i->preauth_key_expires_at
                ? \Illuminate\Support\Carbon::parse($i->preauth_key_expires_at)
                : null;
            $keyStatus = $keyExpiry
                ? ($keyExpiry->isPast() ? 'EXPIRED' : $keyExpiry->diffForHumans(null, true) . ' left')
                : 'unknown';

            $rows[] = [
                $i->slug,
                $i->status,
                $serviceActive,
                $i->port_main . '/' . $i->port_metrics . '/' . $i->port_grpc,
                $portListening ? 'yes' : 'NO',
                ($nodeCounts->online ?? 0) . '/' . ($nodeCounts->total ?? 0) . ' online',
                $keyStatus,
            ];
        }

        $this->table(
            ['Tenant', 'DB status', 'Service', 'Ports', 'Listening', 'Nodes', 'Pre-auth key'],
            $rows
        );

        return self::SUCCESS;
    }

    private function serviceStatus(string $unit): string
    {
        $p = new Process(['systemctl', 'is-active', $unit]);
        $p->run();
        $out = trim($p->getOutput());
        return $out !== '' ? $out : 'unknown';
    }

    private function isPortListening(int $port): bool
    {
        $p = new Process(['ss', '-ltn']);
        $p->run();
        return str_contains($p->getOutput(), "127.0.0.1:{$port} ");
    }
}
