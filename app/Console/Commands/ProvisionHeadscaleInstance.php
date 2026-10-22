<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Provisions a per-tenant Headscale instance — automates exactly what was
 * proven manually for 'aicexperts' (Phase 4 addendum Part E): own config,
 * own systemd unit, own nginx vhost, own database, own ports, a Headscale
 * user, and a reusable pre-auth key.
 *
 * MUST BE RUN AS ROOT. Writing to /etc/headscale-*, /etc/systemd/system,
 * /etc/nginx/sites-enabled, and calling systemctl/nginx all require root —
 * the same constraint BuildAgentPackage already has for fleetctl. This is
 * NOT safe to trigger from a web request; it is an operator/queue-worker
 * action only, same as agent package builds.
 *
 * Isolation model (deliberate, decided explicitly): ONE INSTANCE PER
 * TENANT, not a shared instance with ACL-based separation. Headscale's own
 * docs confirm a shared instance with no/incomplete ACL policy defaults to
 * allow-all between every user — a config mistake would be a real
 * cross-tenant network breach. Per-tenant instances make isolation
 * structural rather than policy-dependent.
 */
#[Signature('headscale:provision {tenant : tenant id or slug}')]
#[Description('Provision a dedicated Headscale VPN instance for a tenant')]
class ProvisionHeadscaleInstance extends Command
{
    /** Base ports for the FIRST tenant instance — matches what was proven
     *  by hand for aicexperts. Each subsequent tenant gets base + 10*N,
     *  leaving room for a possible 4th (DERP STUN) port later without
     *  colliding with the next tenant's block. */
    private const BASE_PORT_MAIN = 8081;
    private const BASE_PORT_METRICS = 9091;
    private const BASE_PORT_GRPC = 50444;
    // STUN for the per-tenant embedded DERP relay. Added 2026-08-26 after
    // confirming every peer on every tailnet was relaying through Tailscale's
    // public DERP -- the scanner via Dallas, Cameroon devices via London --
    // making a one-host scan take 2.5 hours instead of minutes.
    private const BASE_PORT_STUN = 3478;
    // Advertised to clients as the DERP relay's address. Must be this host's
    // real public IPv4 -- the base template ships a documentation placeholder.
    private const PUBLIC_IPV4 = '38.247.149.204';
    private const PORT_STRIDE = 10;

    public function handle(): int
    {
        if (posix_getuid() !== 0) {
            $this->error('This command must run as root (writes to /etc, manages systemd/nginx).');
            return self::FAILURE;
        }

        $ref = $this->argument('tenant');
        $isUuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ref);

        $tenant = DB::table('tenants')
            ->when($isUuid, fn ($q) => $q->where('id', $ref)->orWhere('slug', $ref))
            ->when(!$isUuid, fn ($q) => $q->where('slug', $ref))
            ->first();

        if (!$tenant) {
            $this->error("No such tenant: {$ref}");
            return self::FAILURE;
        }

        $existing = DB::table('headscale_instances')->where('tenant_id', $tenant->id)->first();
        if ($existing && $existing->status === 'active') {
            $this->info("Tenant {$tenant->slug} already has an active Headscale instance (ports {$existing->port_main}/{$existing->port_metrics}/{$existing->port_grpc}).");
            return self::SUCCESS;
        }

        [$portMain, $portMetrics, $portGrpc, $portStun] = $this->allocatePorts($tenant->id);
        $slug = $tenant->slug;
        $configDir = "/etc/headscale-{$slug}";
        $dataDir = "/var/lib/headscale-{$slug}";
        // REAL FIX 2026-08-22, found during a real portability audit:
        // was hardcoded to "obaits.com" directly.
        $baseDomain = config('platform.base_domain');
        $hostname = "vpn-{$slug}.{$baseDomain}";

        $this->info("Provisioning Headscale for {$slug}: ports {$portMain}/{$portMetrics}/{$portGrpc}/stun {$portStun}, {$hostname}");

        $instanceId = $existing->id ?? (string) Str::uuid();
        DB::table('headscale_instances')->updateOrInsert(
            ['tenant_id' => $tenant->id],
            [
                'id' => $instanceId, 'slug' => $slug,
                'port_main' => $portMain, 'port_metrics' => $portMetrics, 'port_grpc' => $portGrpc,
                'port_stun' => $portStun,
                'status' => 'provisioning', 'last_error' => null,
                'created_at' => $existing->created_at ?? now(), 'updated_at' => now(),
            ]
        );

        try {
            $this->writeConfig($configDir, $dataDir, $hostname, $portMain, $portMetrics, $portGrpc, $portStun);
            $this->writeSystemdUnit($slug, $configDir);
            $this->writeNginxVhost($slug, $hostname, $portMain);
            $this->chownDirs($configDir, $dataDir);

            $this->runProcess(['systemctl', 'daemon-reload']);
            $this->runProcess(['systemctl', 'enable', '--now', "headscale-{$slug}"]);
            $this->waitForActive($slug);

            $this->runProcess(['nginx', '-t']);
            $this->runProcess(['systemctl', 'reload', 'nginx']);

            $userId = $this->ensureHeadscaleUser($configDir, $slug);
            $preauthKey = $this->createPreauthKey($configDir, $userId);

            DB::table('headscale_instances')->where('id', $instanceId)->update([
                'headscale_user_id' => $userId,
                'preauth_key_encrypted' => Crypt::encryptString($preauthKey),
                'preauth_key_expires_at' => now()->addYear(),
                'status' => 'active',
                'updated_at' => now(),
            ]);

            \App\Support\Audit::log($tenant->id, 'operator', 'headscale.instance_provisioned', 'success', 'headscale', [
                'ports' => [$portMain, $portMetrics, $portGrpc, $portStun], 'hostname' => $hostname,
            ]);

            $this->info("Done. {$hostname} is live, Headscale user id {$userId}.");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::table('headscale_instances')->where('id', $instanceId)->update([
                'status' => 'failed', 'last_error' => $e->getMessage(), 'updated_at' => now(),
            ]);
            \App\Support\Health\OperatorAlert::send('Headscale provisioning failed', [
                ['level' => 'critical', 'area' => 'headscale',
                 'message' => "tenant={$slug} error=" . $e->getMessage()],
            ]);
            $this->error('Provisioning failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /** Next free port block, based on the highest already allocated —
     *  robust against gaps left by a deleted/failed instance. */
    private function allocatePorts(string $excludeTenantId): array
    {
        $maxMain = DB::table('headscale_instances')
            ->where('tenant_id', '!=', $excludeTenantId)
            ->max('port_main');

        if (!$maxMain) {
            return [self::BASE_PORT_MAIN, self::BASE_PORT_METRICS, self::BASE_PORT_GRPC, self::BASE_PORT_STUN];
        }

        $offset = $maxMain - self::BASE_PORT_MAIN + self::PORT_STRIDE;
        return [
            self::BASE_PORT_MAIN + $offset,
            self::BASE_PORT_METRICS + $offset,
            self::BASE_PORT_GRPC + $offset,
            self::BASE_PORT_STUN + $offset,
        ];
    }

    private function writeConfig(string $configDir, string $dataDir, string $hostname, int $portMain, int $portMetrics, int $portGrpc, int $portStun): void
    {
        if (!is_dir($configDir) && !mkdir($configDir, 0750, true)) {
            throw new \RuntimeException("Could not create {$configDir}");
        }
        if (!is_dir($dataDir) && !mkdir($dataDir, 0750, true)) {
            throw new \RuntimeException("Could not create {$dataDir}");
        }

        $base = file_get_contents('/etc/headscale/config.yaml');
        if ($base === false) {
            throw new \RuntimeException('Base template /etc/headscale/config.yaml not found — is the headscale package installed?');
        }

        // Exactly the substitutions proven by hand in the addendum — every
        // path that differs per instance, nothing assumed beyond that.
        $slug = basename($configDir, false);
        $replacements = [
            'server_url: http://127.0.0.1:8080' => "server_url: https://{$hostname}",
            'listen_addr: 127.0.0.1:8080' => "listen_addr: 127.0.0.1:{$portMain}",
            'metrics_listen_addr: 127.0.0.1:9090' => "metrics_listen_addr: 127.0.0.1:{$portMetrics}",
            'grpc_listen_addr: 127.0.0.1:50443' => "grpc_listen_addr: 127.0.0.1:{$portGrpc}",
            'private_key_path: /var/lib/headscale/noise_private.key' => "private_key_path: {$dataDir}/noise_private.key",
            'path: /var/lib/headscale/db.sqlite' => "path: {$dataDir}/db.sqlite",
            'private_key_path: /var/lib/headscale/derp_server_private.key' => "private_key_path: {$dataDir}/derp_server_private.key",
            'unix_socket: /var/run/headscale/headscale.sock' => "unix_socket: /var/run/{$this->runtimeDirName($configDir)}/headscale.sock",
            'trusted_proxies: []' => 'trusted_proxies: ["127.0.0.1/32"]',

            // --- Per-tenant embedded DERP relay, added 2026-08-26 ---------
            //
            // WHY: confirmed live that EVERY peer on every tenant tailnet was
            // relaying through Tailscale's PUBLIC DERP -- not one direct
            // connection across seven devices in four regions. The scanner
            // relayed via Dallas while Cameroon devices relayed via London,
            // and a scan of one host took 2.5 hours against 6 minutes for the
            // same scan against localhost.
            //
            // netcheck showed MappingVariesByDestIP: false on both ends
            // (endpoint-independent NAT, the favourable case), so direct
            // connections SHOULD be achievable. Running our own STUN is what
            // lets clients discover their external addresses and attempt NAT
            // traversal at all.
            //
            // PER-TENANT rather than one shared relay: Headscale's embedded
            // DERP verifies clients against ITS OWN instance and denies
            // everyone else, so a shared relay would reject every tenant but
            // the one hosting it. Per-tenant also matches the isolation model
            // used everywhere else here.
            //
            // region_id must be UNIQUE per instance -- a collision would have
            // two tenants' relays claiming the same region and clients
            // resolving the wrong one. Derived from the port offset so it
            // cannot collide by construction.
            //
            // Tailscale's public DERP map is deliberately LEFT IN PLACE as a
            // fallback: ours wins on latency because it is local, and losing
            // all connectivity because one host is unreachable is a worse
            // failure than occasionally relaying publicly.
            "    enabled: false

    # Region ID to use for the embedded DERP server." =>
                "    enabled: true

    # Region ID to use for the embedded DERP server.",
            'region_id: 999' => 'region_id: ' . (900 + (($portStun - self::BASE_PORT_STUN) / self::PORT_STRIDE)),
            'stun_listen_addr: "0.0.0.0:3478"' => "stun_listen_addr: \"0.0.0.0:{$portStun}\"",

            // REAL BUG CAUGHT 2026-08-26 on the first real enablement: the base
            // template ships RFC-reserved DOCUMENTATION addresses here
            // (198.51.100.1 and 2001:db8::1) which route nowhere. Headscale
            // advertises them to clients as the relay's address, so every
            // client would be told the relay lives somewhere unreachable.
            //
            // Only visible because the startup log printed the advertised node.
            // systemctl is-active reported success throughout.
            //
            // IPv6 is commented out rather than set: netcheck reports this host
            // has none, and advertising an address clients cannot reach is
            // worse than omitting it.
            '    ipv4: 198.51.100.1' => '    ipv4: ' . self::PUBLIC_IPV4,
            '    ipv6: 2001:db8::1' => '    # ipv6: (not configured -- this host has no IPv6)',
        ];

        $config = $base;
        foreach ($replacements as $old => $new) {
            if (!str_contains($config, $old)) {
                throw new \RuntimeException("Template substitution anchor not found in base config: {$old}");
            }
            $config = str_replace($old, $new, $config);
        }

        file_put_contents("{$configDir}/config.yaml", $config);
    }

    private function runtimeDirName(string $configDir): string
    {
        // $configDir is already "/etc/headscale-{slug}" — basename() alone
        // gives "headscale-{slug}", exactly matching the systemd
        // RuntimeDirectory= name set in writeSystemdUnit(). REAL BUG,
        // caught on the first fresh-tenant test: this used to prepend a
        // SECOND "headscale-" on top, producing the doubled
        // /var/run/headscale-headscale-{slug} that systemd never created,
        // so the CLI's unix-socket connection timed out.
        return basename($configDir);
    }

    private function writeSystemdUnit(string $slug, string $configDir): void
    {
        $stock = file_get_contents('/usr/lib/systemd/system/headscale.service');
        if ($stock === false) {
            throw new \RuntimeException('Stock headscale.service unit not found');
        }

        $unit = strtr($stock, [
            'X-Restart-Triggers=/etc/headscale/config.yaml' => "X-Restart-Triggers={$configDir}/config.yaml",
            'ExecStart=/usr/bin/headscale serve' => "ExecStart=/usr/bin/headscale serve --config {$configDir}/config.yaml",
            'WorkingDirectory=/var/lib/headscale' => "WorkingDirectory=/var/lib/headscale-{$slug}",
            'ReadWritePaths=/var/lib/headscale' => "ReadWritePaths=/var/lib/headscale-{$slug}",
            'RuntimeDirectory=headscale' => "RuntimeDirectory=headscale-{$slug}",
            'StateDirectory=headscale' => "StateDirectory=headscale-{$slug}",
        ]);

        file_put_contents("/etc/systemd/system/headscale-{$slug}.service", $unit);
    }

    private function writeNginxVhost(string $slug, string $hostname, int $portMain): void
    {
        $vhost = <<<NGINX
server {
    listen 80;
    server_name {$hostname};
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl;
    server_name {$hostname};
    ssl_certificate     /etc/letsencrypt/live/{$baseDomain}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{$baseDomain}/privkey.pem;

    modsecurity on;
    modsecurity_rules 'SecRuleEngine DetectionOnly';
    modsecurity_rules_file /etc/nginx/modsec/main.conf;

    client_max_body_size 16M;

    location / {
        proxy_pass http://127.0.0.1:{$portMain};
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 3600;
    }
}
NGINX;

        $path = "/etc/nginx/sites-available/headscale-{$slug}";
        file_put_contents($path, $vhost);

        $link = "/etc/nginx/sites-enabled/headscale-{$slug}";
        if (!file_exists($link)) {
            symlink($path, $link);
        }
    }

    private function chownDirs(string $configDir, string $dataDir): void
    {
        $this->runProcess(['chown', '-R', 'headscale:headscale', $configDir, $dataDir]);
    }

    private function waitForActive(string $slug, int $timeoutSeconds = 15): void
    {
        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            $p = new Process(['systemctl', 'is-active', "headscale-{$slug}"]);
            $p->run();
            if (trim($p->getOutput()) === 'active') {
                return;
            }
            sleep(1);
        }
        throw new \RuntimeException("headscale-{$slug}.service did not become active within {$timeoutSeconds}s — check journalctl -u headscale-{$slug}");
    }

    private function ensureHeadscaleUser(string $configDir, string $slug): int
    {
        $this->runProcess(['headscale', '--config', "{$configDir}/config.yaml", 'users', 'create', $slug], allowFailure: true);

        $p = $this->runProcess(['headscale', '--config', "{$configDir}/config.yaml", 'users', 'list', '--output', 'json']);
        $users = json_decode($p->getOutput(), true) ?: [];

        foreach ($users as $u) {
            if (($u['name'] ?? null) === $slug) {
                return (int) $u['id'];
            }
        }

        throw new \RuntimeException("Could not find headscale user '{$slug}' after create — got: " . $p->getOutput());
    }

    private function createPreauthKey(string $configDir, int $userId): string
    {
        $p = $this->runProcess([
            'headscale', '--config', "{$configDir}/config.yaml", 'preauthkeys', 'create',
            '--user', (string) $userId, '--reusable', '--expiration', '8760h', '--output', 'json',
        ]);

        $data = json_decode($p->getOutput(), true);
        $key = $data['key'] ?? null;

        if (!$key) {
            throw new \RuntimeException('Could not parse pre-auth key from output: ' . $p->getOutput());
        }

        return $key;
    }

    private function runProcess(array $command, bool $allowFailure = false): Process
    {
        $p = new Process($command, null, null, null, 60);
        $p->run();
        if (!$p->isSuccessful() && !$allowFailure) {
            throw new \RuntimeException(implode(' ', $command) . ' failed: ' . $p->getErrorOutput());
        }
        return $p;
    }
}
