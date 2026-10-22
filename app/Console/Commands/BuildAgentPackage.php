<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use App\Support\Audit;

#[AsCommand(name: 'obaits:agent-package', description: 'Build the Fleet agent package for a tenant')]
class BuildAgentPackage extends Command
{
    protected $signature = 'obaits:agent-package {tenant : tenant id or slug} {--type=deb : deb|rpm|pkg|msi}';
    protected $description = 'Build the Fleet agent package for a tenant';

    public function handle(): int
    {
        $ref  = $this->argument('tenant');
        $type = $this->option('type');

        if (!in_array($type, ['deb', 'rpm', 'pkg', 'msi'], true)) {
            $this->error("Unsupported package type: {$type}");
            return 1;
        }

        // Postgres will not compare a non-UUID string against a uuid column — it
        // errors rather than returning no rows. So only try the id match when the
        // argument actually looks like one.
        $isUuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ref);

        $tenant = DB::table('tenants')
            ->when($isUuid, fn ($q) => $q->where('id', $ref)->orWhere('slug', $ref))
            ->when(!$isUuid, fn ($q) => $q->where('slug', $ref))
            ->first();
        if (!$tenant) { $this->error("No such tenant: {$ref}"); return 1; }

        $secret = DB::table('tenant_artifacts')
            ->where('tenant_id', $tenant->id)
            ->where('tool', 'fleet')->where('artifact_type', 'enroll_secret')
            ->value('external_id');

        if (!$secret) {
            $this->error('This tenant has no Fleet enroll secret. Provisioning never completed.');
            return 1;
        }

        // Refuse to build a package around a secret Fleet does not know.
        // This exact drift — recorded in our database, absent from Fleet — silently
        // refused every agent enrollment, with no error surfacing anywhere. A package
        // built now would install cleanly and never enrol.
        $exists = app(\App\Clients\FleetClient::class)->enrollSecretExists($secret);

        if ($exists === false) {
            $this->error('The enroll secret is NOT registered in Fleet.');
            $this->line('  Fix it first:  php artisan agents:reconcile --tenant=' . $tenant->slug);
            return 1;
        }
        if ($exists === null) {
            $this->warn('Could not reach Fleet to verify the enroll secret.');
            if (!$this->confirm('Build anyway?', false)) return 1;
        }

        $out = storage_path('app/private/agent-packages/' . $tenant->id);
        if (!is_dir($out) && !mkdir($out, 0750, true) && !is_dir($out)) {
            $this->error('Could not create ' . $out);
            return 1;
        }

        // Remove any previous package of this type — a stale one alongside a fresh
        // one means the download endpoint's glob picks arbitrarily.
        foreach (glob($out . '/fleet-osquery*.' . $type) ?: [] as $old) {
            @unlink($old);
        }

        $fleetUrl = config('services.fleet.agent_url');

        $args = [
            'fleetctl', 'package',
            '--type=' . $type,
            '--fleet-url=' . $fleetUrl,
            '--enroll-secret=' . $secret,
            '--enable-scripts',   // required for Obaits Backup: restic runs are pushed via Fleet Scripts
        ];

        // Baking the server certificate in is what makes the agent TRUST the server.
        // --insecure alone was not enough: osquery still failed with "certificate
        // signed by unknown authority". This is also the correct production pattern
        // when the certificate is not from a public CA.
        $cert = config('services.fleet.cert_path');
        if ($cert && is_readable($cert)) {
            $args[] = '--fleet-certificate=' . $cert;
        } else {
            $this->warn('No FLEET_CERT_PATH configured — the agent will only work if the '
                      . 'Fleet certificate is trusted by the system store.');
        }

        $this->info("Building {$type} package for {$tenant->slug} against {$fleetUrl} …");

        $p = new Process($args, $out, null, null, 900);
        $p->run(fn ($t, $buffer) => $this->output->write($buffer));

        if (!$p->isSuccessful()) {
            $this->error('fleetctl package failed.');
            Audit::log($tenant->id, 'operator', 'agent.package.built', 'failed', 'fleet',
                ['type' => $type, 'error' => substr($p->getErrorOutput(), 0, 500)]);
            return 1;
        }

        $built = glob($out . '/fleet-osquery*.' . $type);
        if (!$built) {
            $this->error('fleetctl reported success but no package file was produced.');
            return 1;
        }

        // fleetctl writes the package as the running user with a default umask
        // (0600 owner-only), and the directory is 0750 — so PHP-FPM (www-data)
        // can neither traverse the directory nor read the file, and the download
        // route 404s on an empty glob. Make the tree group-readable by the web
        // server. Done in code (not a one-off chmod) so EVERY build is serveable.
        // Observed twice: aicexperts 2026-07-21, mbonjiltd 2026-07-22.
        $webGroup = config('services.fleet.web_group', 'www-data');
        @chgrp($out, $webGroup);
        @chmod($out, 0750);
        @chgrp($built[0], $webGroup);
        @chmod($built[0], 0640);

        // Verify rather than assume — this is the exact thing that has bitten us.
        $perms = substr(sprintf('%o', fileperms($built[0])), -4);
        $this->line('Permissions: ' . $perms . ' ' . (posix_getpwuid(fileowner($built[0]))['name'] ?? '?')
                  . ':' . (posix_getgrgid(filegroup($built[0]))['name'] ?? '?'));

        $this->info('Package: ' . $built[0]);
        $this->line('Size: ' . number_format(filesize($built[0]) / 1048576, 1) . ' MB');

        Audit::log($tenant->id, 'operator', 'agent.package.built', 'success', 'fleet',
            ['type' => $type, 'file' => basename($built[0]), 'fleet_url' => $fleetUrl]);

        return 0;
    }
}