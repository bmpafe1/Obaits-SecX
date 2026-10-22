<?php
namespace App\Support\Health;
 
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
 
class HealthMonitor
{
    // Thresholds — tuned to THIS machine (15GB RAM, ~146GB disk).
    private const DISK_WARN = 80;   // %
    private const DISK_CRIT = 90;
    private const MEM_WARN  = 90;   // % used
    private const MEM_CRIT  = 95;
 
    private array $report = [];

    /**
     * Containers this platform expects to be running.
     *
     * The static list in config covers platform-wide services. PER-TENANT
     * containers cannot live there: they are named by tenant slug and appear
     * and disappear as tenants are provisioned and removed, so a hardcoded list
     * would be wrong the moment anything changed.
     *
     * They are derived from the database instead, which is accurate by
     * construction -- a container the platform believes it provisioned is
     * watched, and one it has torn down is not.
     *
     * ONLY instances whose status says they SHOULD be running. A suspended or
     * stopped tenant's container is meant to be down, and alerting on it would
     * be noise that teaches people to ignore the alerts.
     *
     * DELIBERATELY NOT discovered from `docker ps`: a health monitor that
     * learns its watch list from what is currently running can never notice
     * something missing, which is the entire point of having one.
     */
    private function containers(): array
    {
        $static = config('services.health.containers', []);

        $perTenant = [];
        try {
            $perTenant = \Illuminate\Support\Facades\DB::table('cctv_instances')
                ->where('status', 'running')
                ->pluck('container_name')
                ->filter()
                ->all();
        } catch (\Throwable $e) {
            // The health monitor must never fail because a lookup failed --
            // reporting on the static list is far better than reporting nothing.
            $this->add('warning', 'health', 'Could not read per-tenant containers: ' . $e->getMessage());
        }

        return array_values(array_unique(array_merge($static, $perTenant)));
    }

    private function tenantImpact(): array
    {
        return config('services.health.tenant_impact', []);
    }
 
    public function run(bool $autoAct = true): array
    {
        $this->report = [];
 
        $this->checkDisk();
        $this->checkMemory();
        $this->checkQueueWorker($autoAct);
        $this->checkContainers($autoAct);
        $this->checkPostgres();
 
        $this->dispatchAlerts();
        return $this->report;
    }
 
    private function add(string $level, string $area, string $message, array $meta = []): void
    {
        $this->report[] = compact('level', 'area', 'message', 'meta');
    }
 
    // ---- Disk: ALERT ONLY. Never auto-delete; that is how data is lost. ----
    private function checkDisk(): void
    {
        $pct = (int) trim(shell_exec("df / --output=pcent | tail -1 | tr -dc '0-9'"));
        if ($pct >= self::DISK_CRIT) {
            $this->add('critical', 'disk', "Disk is {$pct}% full. The platform can corrupt data or crash. Free space now (e.g. docker system prune -f).");
        } elseif ($pct >= self::DISK_WARN) {
            $this->add('warning', 'disk', "Disk is {$pct}% full. Plan to free space soon.");
        } else {
            $this->add('ok', 'disk', "Disk {$pct}%.");
        }
    }
 
    // ---- Memory ----
    private function checkMemory(): void
    {
        $meminfo = @file_get_contents('/proc/meminfo');
        if (!$meminfo) { $this->add('warning', 'memory', 'Could not read memory state.'); return; }
        preg_match('/MemTotal:\s+(\d+)/', $meminfo, $t);
        preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $a);
        if (empty($t) || empty($a)) { return; }
        $used = 100 - (int) round(($a[1] / $t[1]) * 100);
 
        if ($used >= self::MEM_CRIT) {
            $this->add('critical', 'memory', "Memory {$used}% used. The machine may start killing processes.");
        } elseif ($used >= self::MEM_WARN) {
            $this->add('warning', 'memory', "Memory {$used}% used.");
        } else {
            $this->add('ok', 'memory', "Memory {$used}% used.");
        }
    }
 
    // ---- Queue worker: the gap that silently broke AI generation. Watch + restart. ----
    private function checkQueueWorker(bool $autoAct): void
    {
        $running = trim(shell_exec("pgrep -f 'artisan queue:work' | head -1")) !== '';
        if ($running) { $this->add('ok', 'queue', 'Queue worker running.'); return; }
 
        if (!$autoAct) { $this->add('critical', 'queue', 'Queue worker is NOT running. Background jobs will not process.'); return; }
 
        if ($this->restartCapped('queue')) {
            $this->add('critical', 'queue', 'Queue worker keeps stopping — restarted too many times. Needs a human. Check logs.');
            return;
        }
 
        // Start it detached, with the timeout AI generation needs.
        $base = base_path();
        shell_exec("cd {$base} && nohup php artisan queue:work --tries=1 --timeout=180 >> storage/logs/worker.log 2>&1 &");
        $this->add('warning', 'queue', 'Queue worker was down; restarted it.');
    }
 
    // ---- Containers: watch, restart safe ones, cap restarts. ----
    private function checkContainers(bool $autoAct): void
    {
        $psRaw = shell_exec("docker ps --format '{{.Names}}|{{.Status}}' 2>/dev/null") ?? '';
        $up = [];
        foreach (explode("\n", trim($psRaw)) as $line) {
            if (!$line) continue;
            [$name, $status] = array_pad(explode('|', $line, 2), 2, '');
            $up[$name] = $status;
        }
 
        foreach ($this->containers() as $name) {
            $status = $up[$name] ?? null;
 
            if ($status === null || !str_starts_with($status, 'Up')) {
                // Down.
                if ($autoAct && !$this->restartCapped("container:{$name}")) {
                    shell_exec("docker start " . escapeshellarg($name) . " 2>&1");
                    $this->add('warning', 'container', "{$name} was down; restarted it.", ['name' => $name, 'tenant_impact' => $this->tenantImpact()[$name] ?? null]);
                } else {
                    $this->add('critical', 'container', "{$name} is down and could not be kept up. Needs a human.", ['name' => $name, 'tenant_impact' => $this->tenantImpact()[$name] ?? null]);
                }
            } elseif (str_contains($status, 'unhealthy')) {
                // Up but unhealthy — do NOT auto-restart (may be mid-startup); alert operator.
                $this->add('warning', 'container', "{$name} is running but unhealthy.", ['name' => $name]);
            } else {
                $this->add('ok', 'container', "{$name} up.");
            }
        }
    }
 
    // ---- Postgres: the platform's own database. ----
    private function checkPostgres(): void
    {
        try {
            DB::select('select 1');
            $this->add('ok', 'database', 'Database reachable.');
        } catch (\Throwable $e) {
            $this->add('critical', 'database', 'The application database is unreachable.');
        }
    }
 
    // ---- Restart cap: 3 restarts / 10 min per thing, then stop and escalate. ----
    private function restartCapped(string $key): bool
    {
        $cacheKey = "health:restarts:{$key}";
        $count = (int) Cache::get($cacheKey, 0);
        if ($count >= 3) return true;               // capped
        Cache::put($cacheKey, $count + 1, now()->addMinutes(10));
        return false;
    }
 
    // ---- Alerts: operator gets everything; tenants get only protection-affecting outages. ----
    private function dispatchAlerts(): void
    {
        $problems = array_filter($this->report, fn ($r) => in_array($r['level'], ['warning', 'critical'], true));
        if (empty($problems)) return;
 
        // De-dupe: do not re-alert the same problem set within 30 minutes.
        $signature = md5(json_encode(array_map(fn ($p) => $p['area'] . $p['message'], $problems)));
        if (Cache::get('health:last_alert') === $signature) return;
        Cache::put('health:last_alert', $signature, now()->addMinutes(30));
 
        // --- Operator alert: the full picture. ---
        $criticals = array_filter($problems, fn ($p) => $p['level'] === 'critical');
        OperatorAlert::send(
            empty($criticals) ? 'Obaits health: warnings' : 'Obaits health: CRITICAL',
            $problems
        );
 
        // --- Tenant alerts: ONLY for outages that affect their protection, in their words. ---
        foreach ($problems as $p) {
            $impact = $p['meta']['tenant_impact'] ?? null;
            if (!$impact) continue;                       // infra noise — tenants never see it
            $this->notifyAffectedTenants($impact, $p['level']);
        }
    }
 
    private function notifyAffectedTenants(string $capability, string $level): void
    {
        // Which tenants actually USE the affected capability?
        $tool = match ($capability) {
            'monitoring' => 'wazuh',
            'devices'    => 'fleet',
            default      => null,
        };
        if (!$tool) return;
 
        $tenantIds = DB::table('tenant_artifacts')->where('tool', $tool)
            ->distinct()->pluck('tenant_id');
 
        $message = match ($capability) {
            'monitoring' => __('Continuous security monitoring is temporarily interrupted. We are working to restore it, and will confirm when it is back.'),
            'devices'    => __('The device inventory is temporarily unavailable. Your devices are unaffected; only the list in Obaits is delayed.'),
            default      => __('A service you rely on is temporarily interrupted.'),
        };
 
        foreach ($tenantIds as $tid) {
            \App\Support\Notifications::raise($tid, __('Service interruption'), [
                'level' => 'warning',
                'body'  => $message,
            ]);
        }
    }
}
