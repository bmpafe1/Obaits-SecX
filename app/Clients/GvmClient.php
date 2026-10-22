<?php
namespace App\Clients;
 
use Illuminate\Support\Facades\Process;
 
class GvmClient
{
    /**
     * Run a helper action on the scanner host.
     *
     * @param array $payload  Non-secret arguments. Safe on the command line.
     * @param array $secrets  Passwords and keys. Sent over STDIN.
     *
     * Secrets do NOT go in the command. Arguments are readable by any user on
     * the scanner host via `ps` for as long as the command runs — escapeshellarg
     * prevents injection, it does not hide anything. The GVM password was
     * previously passed that way; once tenant SSH keys travel this path it
     * becomes a leak of customer server credentials to every local process.
     */
    private function run(string $action, array $payload = [], array $secrets = []): array
    {
        $cfg = config('services.gvm');

        // REAL BUG FOUND 2026-08-24, on the first scan ever attempted on this
        // platform: this used to SSH to the scanner host and run the helper
        // there. The scanner IS this host (GVM_SSH_HOST=127.0.0.1), so it was
        // an SSH hop to itself -- and it had never worked once. There was no
        // private key for the administrator user at all, and 127.0.0.1 was not
        // in its known_hosts, so ssh failed immediately with "Host key
        // verification failed". GvmClient reported that as "GVM create_target
        // failed", which pointed at GVM when GVM was never reached.
        //
        // The queue worker already runs as administrator on this host, so the
        // helper is now invoked DIRECTLY. Confirmed working by hand first: the
        // helper authenticates to gvmd over its unix socket and both version
        // and create_target return correctly.
        //
        // Dropping the hop removes an entire failure mode and needs no key
        // management. If GVM is ever moved to a separate host, reintroduce the
        // remote call deliberately -- do not restore it speculatively.
        //
        // sudo -n: never prompt. The worker has no TTY, so a prompt would hang
        // until the 900s worker timeout rather than failing cleanly.
        $cmd = [
            'sudo', '-n', 'python3',
            $cfg['helper'],
            $action,
            json_encode($payload),
        ];

        // The GVM password travels the same way as everything else secret.
        $secrets['gvm_password'] = $cfg['password'];

        $result = Process::timeout(7200)
            ->input(json_encode($secrets))
            ->run($cmd);

        if (!$result->successful()) {
            // The helper's stderr can echo back what it was called with, and this
            // message lands in laravel.log. Say what failed, not what was sent.
            throw new \RuntimeException('GVM ' . $action . ' failed (see scanner logs)');
        }

        $out = trim($result->output());
        $data = json_decode($out, true);
        if ($data === null) {
            throw new \RuntimeException('GVM ' . $action . ' returned non-JSON');
        }

        return $data;
    }
 
    public function version(): string
    {
        return $this->run('version')['version'] ?? 'unknown';
    }
 
    /**
     * Ask gvmd to actually connect to a scanner over TLS and report its version.
     *
     * NOT sufficient on its own to decide a pool member is healthy. Confirmed
     * 2026-08-25: this returned 200 while the scanner's ospd container sat in an
     * orphaned network namespace with an EMPTY routing table, able to reach
     * nothing at all. gvmd could talk to it; it could talk to no devices.
     * Reachability must be checked separately.
     */
    public function verifyScanner(string $scannerId): array
    {
        return $this->run('verify_scanner', ['scanner_id' => $scannerId]);
    }

    public function getScanners(): array
    {
        return $this->run('get_scanners');
    }

    public function createTarget(string $name, string $host): string
    {
        // REAL DIAGNOSTIC GAP 2026-08-24: this returned ['id'] blindly. When GVM
        // refuses, the helper returns a null id, and PHP's return type then threw
        // "must be of type string, null returned" -- an error that says nothing
        // about what GVM actually objected to. The helper now also reports GVM's
        // own status and status_text; surface them instead of discarding them.
        $r = $this->run('create_target', ['name' => $name, 'host' => $host]);

        if (empty($r['id'])) {
            throw new \RuntimeException(
                'GVM refused create_target for ' . $host
                . ' (status ' . ($r['status'] ?? '?') . '): '
                . ($r['status_text'] ?? 'no reason given')
            );
        }

        return $r['id'];
    }
 
    public function createTask(string $name, string $configId, string $targetId, string $scannerId): string
    {
        return $this->run('create_task', [
            'name' => $name, 'config_id' => $configId,
            'target_id' => $targetId, 'scanner_id' => $scannerId,
        ])['id'];
    }
 
    public function startTask(string $taskId): void
    {
        $this->run('start_task', ['task_id' => $taskId]);
    }
 
    public function taskStatus(string $taskId): array
    {
        return $this->run('task_status', ['task_id' => $taskId]);
    }
 
    public function getResults(string $taskId): array
    {
        return $this->run('get_results', ['task_id' => $taskId])['findings'] ?? [];
    }

    public function stopTask(string $taskId): void
    {
        $this->run('stop_task', ['task_id' => $taskId]);
    }

        public function deleteTask(string $taskId): void
    {
        $this->run('delete_task', ['task_id' => $taskId]);
    }
 
    public function deleteTarget(string $targetId): void
    {
        $this->run('delete_target', ['target_id' => $targetId]);
    }

}
