<?php

namespace App\Livewire\Portal;

use Livewire\Component;
use Illuminate\Support\Facades\DB;

class Enroll extends Component
{
    public string $tenantId;
    public string $os = 'linux';   // linux | macos | windows

    // Phase 4.1 Stage 3, Tier 1: which BASE machine-type profile this
    // device gets, chosen once at enrollment. Maps to a real Wazuh group
    // seeded by `wazuh:seed-role-groups`. macOS has only one option
    // (macos-client), so no selector is shown for it.
    public string $machineType = 'server';   // server | desktop (linux); workstation | dc (windows)

    private const TIER1_GROUPS = [
        'linux'   => ['server' => 'linux-servers', 'desktop' => 'linux-desktop'],
        'windows' => ['workstation' => 'windows-client', 'dc' => 'windows-dc'],
        'macos'   => ['default' => 'macos-client'],
    ];

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { return redirect('/login'); }
    }

    public function setOs(string $os)
    {
        $this->os = $os;
        // Reset to each OS's first real option so a stale selection from a
        // previous OS (e.g. 'dc' while switching from windows to linux)
        // never silently produces an invalid group name.
        $this->machineType = array_key_first(self::TIER1_GROUPS[$os]);
    }

    public function setMachineType(string $type) { $this->machineType = $type; }

    private function tier1Group(): string
    {
        return self::TIER1_GROUPS[$this->os][$this->machineType]
            ?? self::TIER1_GROUPS[$this->os][array_key_first(self::TIER1_GROUPS[$this->os])];
    }

    public function render()
    {
        // The tenant's already-provisioned agent identities.
        $fleetSecret = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)      // ISOLATION
            ->where('tool', 'fleet')
            ->where('artifact_type', 'enroll_secret')
            ->value('external_id');

        $wazuhGroup = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)
            ->where('tool', 'wazuh')
            ->where('artifact_type', 'agent_group')
            ->value('external_id');

        // Real, per-tenant Zabbix registration secret, matching the same
        // real isolation pattern as fleetSecret/wazuhGroup above -- this
        // is what lets a real device's Zabbix agent automatically land in
        // the CORRECT tenant's host group via Zabbix's own native
        // HostMetadata mechanism (see ZabbixStep/ZabbixClient).
        $zabbixSecretRow = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)
            ->where('tool', 'zabbix')
            ->where('artifact_type', 'registration_secret')
            ->value('metadata');
        $zabbixSecret = $zabbixSecretRow ? (json_decode($zabbixSecretRow, true)['secret'] ?? null) : null;

        // Agents connect to the Wazuh MANAGER host, not the API URL.
        $wazuhManager = config('services.wazuh.agent_address')
                        ?: config('services.wazuh.base_url');
        // Wazuh's authd requires a shared enrollment password as of 2026-07-23
        // (use_password enabled — previously any machine with network access to
        // port 1515 could register as an agent on any tenant, no credential at all).
        $enrollPassword = config('services.wazuh.enroll_password');
        // The platform's own URL. The script and the agent package come from here,
        // NOT from Fleet — they are different services. The Fleet address is baked
        // into the package at build time, so the script never needs it.
        $portalUrl = rtrim((string) config('services.obaits.portal_url'), '/');

        // Pinned to the manager. Wazuh refuses any agent newer than its manager,
        // so we read the version rather than hardcoding one that will rot.
        $wazuhVersion = app(\App\Clients\WazuhClient::class)->managerVersion();

        // Which of this tenant's devices are already enrolled. hostsForTenant()
        // resolves the tenant's Fleet label, so a host appears only once it
        // carries this tenant's marker file.
        $enrolled = [];
        if ($fleetSecret) {
            try {
                $enrolled = app(\App\Clients\FleetClient::class)->hostsForTenant($fleetSecret);
            } catch (\Throwable $e) {
                \Log::warning('Enroll page: hostsForTenant failed', ['error' => $e->getMessage()]);
                $enrolled = [];
            }
        }

        $ready = $fleetSecret && $wazuhGroup && $wazuhVersion && $portalUrl && $enrollPassword && $zabbixSecret;

        // Combined group string: agent-auth's own -G flag accepts a
        // comma-separated list, so Tier 1 needs no script changes at all —
        // this is the tenant's isolation group PLUS the chosen base
        // machine-type profile, both applied at initial registration.
        $combinedGroup = $wazuhGroup . ',' . $this->tier1Group();

        $command = $ready
            ? $this->buildCommand($fleetSecret, $combinedGroup, $wazuhManager, $wazuhVersion, $portalUrl, $enrollPassword, $zabbixSecret)
            : null;

        return view('livewire.portal.enroll', [
            'ready'         => $ready,
            'command'       => $command,
            'enrolled'      => $enrolled,
            'os'            => $this->os,
            'machineType'   => $this->machineType,
            'tier1Options'  => self::TIER1_GROUPS[$this->os],
        ])->layout('components.layouts.portal');
    }

    /**
     * The single install command for the chosen OS.
     *
     * OBAITS_TENANT_ID is not decoration: the script writes /etc/obaits/tenant-{id}
     * and this tenant's Fleet label matches that exact path. Fleet Free has no Teams
     * and does not report which enroll secret a host used, so that marker file IS
     * the attribution. Omit it and the machine enrols and belongs to nobody.
     *
     * OBAITS_WAZUH_ENROLL_PASSWORD (added 2026-07-23): required by Wazuh's authd
     * now that use_password is enabled. Without it, agent-auth is refused with
     * "Invalid password (from manager)".
     */
    private function buildCommand(
        string $secret,
        string $group,
        string $wazuhManager,
        string $wazuhVersion,
        string $portalUrl,
        string $enrollPassword,
        string $zabbixSecret
    ): string {
        $zabbixAddress = config('services.zabbix.agent_address');
        // REAL FIX 2026-08-22, found during a real portability audit:
        // this used to silently default to a specific VPS's own real
        // IP if missing. Failing loudly here instead, so a fresh
        // deployment missing this real config value breaks visibly and
        // immediately, not silently, via a broken enrollment command
        // discovered only much later when a device can't reach Zabbix.
        if (!$zabbixAddress) {
            throw new \RuntimeException('ZABBIX_AGENT_ADDRESS is not configured. Set it in .env before enrolling devices.');
        }

        $env = "OBAITS_PORTAL_URL='{$portalUrl}'"
             . " OBAITS_ENROLL_SECRET='{$secret}'"
             . " OBAITS_WAZUH_MANAGER='{$wazuhManager}'"
             . " OBAITS_WAZUH_GROUP='{$group}'"
             . " OBAITS_TENANT_ID='{$this->tenantId}'"
             . " OBAITS_WAZUH_ENROLL_PASSWORD='{$enrollPassword}'"
             . " OBAITS_WAZUH_VERSION='{$wazuhVersion}-1'"
             . " OBAITS_ZABBIX_SERVER='{$zabbixAddress}'"
             . " OBAITS_ZABBIX_SECRET='{$zabbixSecret}'";

        return match ($this->os) {
            'windows' =>
                "# Run in PowerShell as Administrator:\n" .
                "\$env:OBAITS_PORTAL_URL='{$portalUrl}'; " .
                "\$env:OBAITS_ENROLL_SECRET='{$secret}'; " .
                "\$env:OBAITS_WAZUH_MANAGER='{$wazuhManager}'; " .
                "\$env:OBAITS_WAZUH_GROUP='{$group}'; " .
                "\$env:OBAITS_TENANT_ID='{$this->tenantId}'; " .
                "\$env:OBAITS_WAZUH_ENROLL_PASSWORD='{$enrollPassword}'; " .
                "\$env:OBAITS_WAZUH_VERSION='{$wazuhVersion}-1'; " .
                "\$env:OBAITS_ZABBIX_SERVER='{$zabbixAddress}'; " .
                "\$env:OBAITS_ZABBIX_SECRET='{$zabbixSecret}';\n" .
                "irm {$portalUrl}/enroll/install.ps1 | iex",

            'macos' =>
                "# Run in Terminal:\n" .
                "{$env} /bin/bash -c \"\$(curl -fsSL {$portalUrl}/enroll/install.sh)\"",

            default => // linux
                "# Run in a terminal (needs sudo):\n" .
                "{$env} sudo -E bash -c \"\$(curl -fsSL {$portalUrl}/enroll/install.sh)\"",
        };
    }
}