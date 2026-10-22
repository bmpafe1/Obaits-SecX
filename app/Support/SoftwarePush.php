<?php
namespace App\Support;

use App\Support\Backup\FleetScriptRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Real Phase B of software push automation: pushes an assigned,
 * malware-scanned installer to devices via the same real, proven Fleet
 * Script trigger architecture already used for every Tier 2 role and for
 * backups. Works around Fleet Free's lack of native software-push.
 */
class SoftwarePush
{
    /**
     * REAL BUG FOUND 2026-08-06: this was 15 minutes, far too short for
     * Fleet's real behaviour. A script launched against an offline device is
     * HELD by Fleet and delivered whenever that host next reports back --
     * confirmed directly and documented in FleetScriptRunner's own header.
     * With a 15-minute token, any device offline longer than that downloads
     * nothing and the install fails -- exactly the case the reconciliation
     * safety net exists to serve. 24 hours is still tightly bounded, and the
     * token remains single-use and scoped to one tenant + one file.
     */
    public const TOKEN_TTL_HOURS = 24;

    /**
     * How many real attempts before a device+software pair is left alone for
     * a human. Without a cap, a genuinely broken install retries every 15
     * minutes forever. Deferrals (host_busy) deliberately do not count.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * Real, safe MSI auto-detection: msiexec's own silent-install convention
     * is genuinely universal, confirmed against this project's own real MSI
     * installs (Wazuh, Zabbix, Fleet, Tailscale, restic). Anything else
     * requires the admin's explicit install_command.
     */
    public static function isMsi(string $originalName): bool
    {
        return str_ends_with(strtolower($originalName), '.msi');
    }

    public static function pushToDevice(string $assignmentId, int $fleetHostId, string $hostname, string $platform, ?string $actor = null): array
    {
        $assignment = DB::table('software_group_assignments')->where('id', $assignmentId)->first();
        if (!$assignment) return ['ok' => false, 'error' => 'assignment_not_found'];

        $file = DB::table('software_files')->where('id', $assignment->software_file_id)->first();
        if (!$file || $file->scan_status !== 'clean') return ['ok' => false, 'error' => 'file_not_clean'];

        $existing = DB::table('software_install_runs')
            ->where('software_group_assignment_id', $assignmentId)
            ->where('fleet_host_id', $fleetHostId)
            ->first();

        $installCommand = self::isMsi($file->original_name)
            ? 'Start-Process msiexec.exe -ArgumentList "/i `"%%INSTALLER_PATH%%`" /qn" -Wait'
            : ($assignment->install_command ?: null);

        if (!$installCommand) {
            // Recorded as a real, visible failure rather than silently dropped.
            self::record($existing, $assignmentId, $assignment->tenant_id, $fleetHostId, $hostname, [
                'status'  => 'failed',
                'output'  => 'No silent install command was provided for this non-MSI installer.',
                'attempt' => true,
            ]);
            return ['ok' => false, 'error' => 'no_install_command'];
        }

        $token = bin2hex(random_bytes(32));
        DB::table('software_install_tokens')->insert([
            'id' => (string) Str::uuid(),
            'token' => $token,
            'tenant_id' => $assignment->tenant_id,
            'software_file_id' => $file->id,
            'expires_at' => now()->addHours(self::TOKEN_TTL_HOURS),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $portalUrl = config('app.url');
        $downloadUrl = "{$portalUrl}/enroll/software-installer?token={$token}";
        $ext = pathinfo($file->original_name, PATHINFO_EXTENSION);

        if ($platform === 'windows') {
            $installerPath = '$env:TEMP\\obaits-software-install.' . $ext;
            $realInstallCommand = str_replace('%%INSTALLER_PATH%%', $installerPath, $installCommand);
            $script = "\$ErrorActionPreference = 'Stop'\n"
                . "\$installerPath = \"{$installerPath}\"\n"
                . "try {\n"
                . "  Invoke-WebRequest -Uri '{$downloadUrl}' -OutFile \$installerPath -UseBasicParsing\n"
                . "} catch {\n"
                . "  Write-Host \"ERROR: could not download the installer: \$(\$_.Exception.Message)\"\n"
                . "  exit 1\n"
                . "}\n"
                . "{$realInstallCommand}\n"
                . "Write-Host 'Install command completed.'\n";
        } else {
            $installerPath = '/tmp/obaits-software-install.' . $ext;
            $realInstallCommand = str_replace('%%INSTALLER_PATH%%', $installerPath, $installCommand);
            $script = "#!/bin/bash\n"
                . "set -e\n"
                . "INSTALLER_PATH=\"{$installerPath}\"\n"
                . "curl -fsSL '{$downloadUrl}' -o \"\$INSTALLER_PATH\"\n"
                . "{$realInstallCommand}\n"
                . "echo 'Install command completed.'\n";
        }

        $launched = FleetScriptRunner::launch($fleetHostId, $script, $actor, $assignment->tenant_id);

        // REAL DISTINCTION FOUND 2026-08-06: Fleet SERIALISES script execution
        // per host and returns 409 'host_busy' if something is already in
        // flight. Both real push call sites loop over every assignment in a
        // group back-to-back against the same host, so with two software items
        // in one group the second reliably 409s. That is TRANSIENT -- recording
        // it as 'failed' turned "try again shortly" into a permanent, silently
        // wrong result. Now left 'pending' for the reconciler, and deliberately
        // not counted against MAX_ATTEMPTS.
        $isBusy = !$launched['ok'] && ($launched['error'] ?? '') === 'host_busy';

        self::record($existing, $assignmentId, $assignment->tenant_id, $fleetHostId, $hostname, [
            'status'       => $launched['ok'] ? 'running' : ($isBusy ? 'pending' : 'failed'),
            'execution_id' => $launched['execution_id'] ?? null,
            'output'       => $launched['ok']
                ? null
                : ($isBusy
                    ? 'Deferred: another script is already running on this host. Will retry.'
                    : 'Launch failed: ' . $launched['error']),
            'attempt'      => !$isBusy,
        ]);

        return $launched;
    }

    /**
     * REAL BUG FOUND 2026-08-06: this previously used updateOrInsert() with
     * 'id' and 'created_at' inside the VALUES array -- so every re-push against
     * an existing device+software pair silently replaced that row's own UUID
     * and reset its creation time. Harmless while nothing read that history,
     * but the reconciler depends on attempt history being real and continuous,
     * so insert and update are now genuinely separate.
     */
    private static function record(?object $existing, string $assignmentId, string $tenantId, int $fleetHostId, string $hostname, array $data): void
    {
        $attempts = (int) ($existing->attempts ?? 0) + (($data['attempt'] ?? false) ? 1 : 0);

        if ($existing) {
            DB::table('software_install_runs')->where('id', $existing->id)->update([
                'hostname'           => $hostname,
                'fleet_execution_id' => $data['execution_id'] ?? null,
                'status'             => $data['status'],
                'attempts'           => $attempts,
                'output'             => $data['output'],
                'finished_at'        => $data['status'] === 'failed' ? now() : null,
                'updated_at'         => now(),
            ]);
            return;
        }

        DB::table('software_install_runs')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'software_group_assignment_id' => $assignmentId,
            'fleet_host_id' => $fleetHostId,
            'hostname' => $hostname,
            'fleet_execution_id' => $data['execution_id'] ?? null,
            'status' => $data['status'],
            'attempts' => $attempts,
            'output' => $data['output'],
            'finished_at' => $data['status'] === 'failed' ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
