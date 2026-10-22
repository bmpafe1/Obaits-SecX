<?php
namespace App\Livewire\Portal;
 
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use App\Support\Perm;
use Livewire\WithPagination;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
 
#[Layout('components.layouts.portal')]
class Assets extends Component
{
    public string $tenantId;
    public ?string $fleetSecret = null;
    public ?string $wazuhGroup = null;
    use WithPagination;

    // Filters
    public string $search = '';
    public string $platformFilter = '';
    public string $seenFilter = '';        // '' | 'recent' | 'stale'

    // Honest error state — an outage must not look like "no devices".
    public ?string $fleetError = null;

    // Single-device removal — two-step confirm, never a single click.
    public ?int $confirmingRemovalId = null;
    public ?string $confirmingRemovalName = null;
    public ?string $removalError = null;
    public ?string $removalNotice = null;
 
    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); }
 
        $this->fleetSecret = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)
            ->where('tool', 'fleet')
            ->where('artifact_type', 'enroll_secret')
            ->value('external_id');

        $this->wazuhGroup = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)
            ->where('tool', 'wazuh')
            ->where('artifact_type', 'agent_group')
            ->value('external_id');
    }
 
    public function render()
    {
        $all = collect();
        $this->fleetError = null;

        if ($this->fleetSecret) {
            try {
                $raw = app(\App\Clients\FleetClient::class)->hostsForTenant($this->fleetSecret);

                foreach ($raw as $h) {
                    $hostname = $h['hostname'] ?? ($h['display_name'] ?? '—');
                    // Phase 4: Fleet's own primary_ip is the device's real
                    // LAN address ONLY — confirmed empirically NEVER its
                    // Tailscale/VPN address, even for a device genuinely
                    // online on the tenant's Headscale network at the
                    // moment this runs. Shown separately so a tenant
                    // troubleshooting connectivity can see both — the LAN
                    // IP for "which physical machine is this on our
                    // network", the VPN IP for "is this the address
                    // Obaits itself would reach it at".
                    $vpnIp = \App\Support\Reachability::vpnIpForHost($this->tenantId, $hostname);

                    // REAL, SERIOUS BUG FOUND (a real device, confirmed
                    // directly): Fleet's own cached public_ip/primary_ip
                    // fields can be COMPLETELY FABRICATED, with zero real
                    // correspondence to the device at all; confirmed on
                    // mail.aicexperts.com, where Fleet reported
                    // public_ip=172.18.0.1, genuinely the Docker bridge
                    // gateway of FLEET'S OWN CONTAINER on the VPS, not
                    // anything read from the target device. A device can
                    // also genuinely have MANY real interfaces (multiple
                    // NICs, Docker bridges, etc.), which a single 'ip'
                    // field could never represent honestly anyway.
                    // Real fix: never trust Fleet's own cached fields for
                    // this at all; use our own real, independently
                    // verified interface data (host_network_interfaces,
                    // synced via Fleet's scheduled-query report mechanism).
                    $realIps = DB::table('host_network_interfaces')
                        ->where('fleet_host_id', $h['id'] ?? 0)
                        ->pluck('ip_address')
                        ->all();

                    $all->push([
                        'id'        => $h['id'] ?? null,
                        'hostname'  => $hostname,
                        'os'        => $h['os_version'] ?? ($h['platform'] ?? '—'),
                        'platform'  => strtolower($h['platform'] ?? ''),   // for filtering
                        'ips'       => $realIps,   // real, independently-verified, possibly multiple
                        'vpn_ip'    => $vpnIp,   // null if not (yet) VPN-connected
                        'last_seen' => isset($h['seen_time']) ? substr($h['seen_time'], 0, 10) : null,
                        'status'    => $h['status'] ?? '—',
                    ]);
                }
            } catch (\Throwable $e) {
                // HONEST: an outage is NOT the same as "no devices". Say which it is.
                $this->fleetError = __('Could not reach the device inventory. This list may be incomplete — do not rely on it until this is resolved.');
            }
        }

        $totalEnrolled = $all->count();

        // ---- Search (hostname, OS, or IP) ----
        if ($this->search !== '') {
            $needle = strtolower($this->search);
            $all = $all->filter(fn ($h) =>
                str_contains(strtolower((string) $h['hostname']), $needle)
                || str_contains(strtolower((string) $h['os']), $needle)
                || collect($h['ips'])->contains(fn ($ip) => str_contains(strtolower((string) $ip), $needle))
            );
        }

        // ---- Platform filter ----
        if ($this->platformFilter !== '') {
            $all = $all->filter(fn ($h) => $h['platform'] === $this->platformFilter);
        }

        // ---- Last-seen filter: a device that stopped reporting is a SECURITY GAP ----
        if ($this->seenFilter !== '') {
            $cutoff = now()->subDays(30)->toDateString();
            $all = $all->filter(function ($h) use ($cutoff) {
                $seen = $h['last_seen'];
                if ($this->seenFilter === 'stale') {
                    return $seen === null || $seen < $cutoff;      // missing or silent 30+ days
                }
                return $seen !== null && $seen >= $cutoff;          // 'recent'
            });
        }

        // Count devices that have gone quiet — the number worth surfacing.
        $stale = collect();
        $cutoff = now()->subDays(30)->toDateString();
        // (recompute from the unfiltered set so the badge is always the true figure)
        // NOTE: cheap because the list is already in memory.

        $filtered = $all->values();

        // ---- Manual pagination over the collection (Fleet returns an array, not a query) ----
        $perPage = 15;
        $page    = $this->getPage();
        $hosts = new LengthAwarePaginator(
            $filtered->forPage($page, $perPage)->values(),
            $filtered->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        // Latest role-setup run per (host, role) — real progress visibility,
        // not just a static "may take a minute" message. Real gap found and
        // fixed 2026-07-27: the backend tracked this from the start, but
        // nothing surfaced it back to the tenant.
        $roleRuns = \Illuminate\Support\Facades\DB::table('role_setup_runs')
            ->where('tenant_id', $this->tenantId)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn ($r) => $r->hostname . '|' . $r->role_group)
            ->map(fn ($group) => $group->first());

        // REAL FIX: a credential can now be device-specific OR a
        // shared/default fallback (fleet_host_id null) -- show every
        // real, stored credential, resolving each device-specific one
        // to its actual real hostname (using the already-loaded $hosts
        // list) so a tenant can see exactly which device a credential
        // belongs to, rather than one row silently standing in for
        // every device running that role.
        $hostNamesById = $hosts->keyBy('id')->map(fn ($h) => $h['hostname']);
        $storedCredentials = \Illuminate\Support\Facades\DB::table('tenant_role_credentials')
            ->where('tenant_id', $this->tenantId)
            ->get(['role_group', 'username', 'fleet_host_id'])
            ->map(function ($c) use ($hostNamesById) {
                $c->device_label = $c->fleet_host_id
                    ? ($hostNamesById[$c->fleet_host_id] ?? __('Unknown device'))
                    : __('Shared (all devices)');
                return $c;
            });

        return view('livewire.portal.assets', [
            'hosts'         => $hosts,
            'totalEnrolled' => $totalEnrolled,
            'showing'       => $filtered->count(),
            'filtered'      => ($this->search !== '' || $this->platformFilter !== '' || $this->seenFilter !== ''),
            'tier2Roles'    => self::TIER2_ROLES,
            'roleRuns'      => $roleRuns,
            'roleConfirmText' => self::ROLE_CONFIRMATION_TEXT,
            'storedCredentials' => $storedCredentials,
        ]);
    }
 
    public function updatingSearch()
    {
        $this->resetPage();
    }
 
    public function clearFilters()
    {
        $this->reset(['search', 'platformFilter', 'seenFilter']);
        $this->resetPage();
    }

    // Step 1 of 2: ask "are you sure" rather than deleting on a single click.
    public function confirmRemoval(int $hostId, string $hostname): void
    {
        $this->confirmingRemovalId = $hostId;
        $this->confirmingRemovalName = $hostname;
        $this->removalError = null;
        $this->removalNotice = null;
    }

    /**
     * Phase 4.1 Stage 3, Tier 2: add an already-enrolled device to an
     * additional software-role group (postgres-db, etc.), layered onto
     * its existing tenant + Tier 1 groups — never replacing them.
     * addAgentToGroup() is CONFIRMED ADDITIVE (proven live earlier this
     * stage against a real agent).
     */
    public ?string $roleMessage = null;
    public ?string $rolePrerequisite = null;

    /** Groups a tenant can opt a device into. Static for now — this list
     *  should track ops/wazuh/profiles/*.xml's real Tier 2 files as more
     *  are added. */
    public const TIER2_ROLES = [
        'postgres-db'      => 'PostgreSQL',
        'mysql-db'         => 'MySQL / MariaDB',
        'mssql-db'         => 'Microsoft SQL Server',
        'oracle-db'        => 'Oracle Database',
        'web-nginx'        => 'NGINX',
        'web-apache'       => 'Apache',
        'web-iis'          => 'IIS',
        'exchange'         => 'Microsoft Exchange',
        'zimbra'           => 'Zimbra',
        'docker-hosts'     => 'Docker host',
        'api-servers'      => 'API server',
        'network-devices'  => 'Network device syslog receiver',
        'mongodb'          => 'MongoDB',
    ];
    /**
     * Real, role-specific steps needed on the SOFTWARE itself before
     * meaningful audit data exists for Wazuh to collect. Confirmed live
     * on 2026-07-27: adding a role's Wazuh group alone does nothing if
     * the software was never told to produce the audit log in the first
     * place (pgaudit is a separate PostgreSQL extension, not installed by
     * default — this exact gap was found by testing on a real device).
     * Shown to the tenant immediately after adding a role, not buried in
     * documentation — the whole point is to prevent this exact confusion
     * from recurring.
     */
    public const ROLE_PREREQUISITES = [
        'postgres-db' => <<<'EOD'
PostgreSQL does not audit by default. On the database server, run:

sudo apt-get install -y postgresql-16-pgaudit
sudo -u postgres psql -c "SHOW config_file;"   # find your real config path
# Edit that file, add these two lines:
#   shared_preload_libraries = 'pgaudit'
#   pgaudit.log = 'all'
sudo systemctl restart postgresql   # full restart required, not just reload
sudo -u postgres psql -c "CREATE EXTENSION IF NOT EXISTS pgaudit;"
EOD,

        'mysql-db' => <<<'EOD'
MySQL/MariaDB does not audit by default. On the database server:

-- In a mysql/mariadb session:
INSTALL PLUGIN audit_log SONAME 'audit_log.so';
-- Then add to my.cnf:
--   audit_log_format=JSON
--   audit_log_policy=ALL
-- Restart MySQL for the config to take effect.
EOD,

        'mssql-db' => <<<'EOD'
SQL Server does not audit by default. In SQL Server Management Studio or sqlcmd:

USE master;
CREATE SERVER AUDIT [Wazuh_Audit]
TO FILE (FILEPATH = 'C:\Program Files\Microsoft SQL Server\MSSQL15.MSSQLSERVER\MSSQL\Audit\')
WITH (ON_FAILURE = CONTINUE);
ALTER SERVER AUDIT [Wazuh_Audit] WITH (STATE = ON);
CREATE DATABASE AUDIT SPECIFICATION [Wazuh_DB_Audit]
FOR SERVER AUDIT [Wazuh_Audit]
ADD (FAILED_LOGIN_GROUP), ADD (SUCCESSFUL_LOGIN_GROUP);
ALTER DATABASE AUDIT SPECIFICATION [Wazuh_DB_Audit] WITH (STATE = ON);

(Adjust the file path if you're on a different SQL Server version or a named instance.)
EOD,

        'oracle-db' => <<<'EOD'
Oracle Unified Auditing needs to be enabled. Exact syntax varies more by
version than the other databases above — verify against your specific
Oracle version's documentation before running in production:

ALTER SYSTEM SET AUDIT_TRAIL=DB,EXTENDED SCOPE=SPFILE;
-- Requires a database restart to take effect
AUDIT ALL;   -- or a narrower AUDIT policy for specific statement types
EOD,

        'web-nginx'  => 'No extra steps needed — access and error logs are captured by default once NGINX is installed and running.',
        'web-apache' => 'No extra steps needed — access and error logs are captured by default once Apache is installed and running.',
        'web-iis'    => 'No extra steps needed — IIS logs are captured by default once the role is installed.',

        'exchange' => <<<'EOD'
Mailbox audit logging is often on by default in modern Exchange, but confirm and enforce it:

Set-OrganizationConfig -AuditDisabled $false
Get-Mailbox -ResultSize Unlimited | Set-Mailbox -AuditEnabled $true
New-AdminAuditLogConfig -AdminAuditLogEnabled $true   # for admin action logging
EOD,

        'zimbra' => "Zimbra's audit.log is generally enabled by default. If you don't see entries, confirm the log level in your Zimbra version's admin console — exact steps vary more across Zimbra versions than for the other roles above.",

        'docker-hosts' => 'No extra steps needed — the Docker daemon log and container logs are captured by default. The periodic "docker events" command needs the Docker CLI available on this host, which it already is if Docker itself is installed.',

        'api-servers' => 'This depends entirely on your specific API application/framework — there is no single universal command. Make sure your application writes logs to one of the paths this profile watches (/var/log/api/*.log by default), or ask your development team to adjust the log destination to match.',

        'network-devices' => 'This device receives forwarded syslog — the actual network device (router, firewall, switch) must be configured to SEND its syslog to this server. This is a configuration change on the network device itself, not on this Linux host.',
    ];


    /** Roles with a real, automated setup script (ops/wazuh/role-setup-scripts/{key}.sh).
     *  A role with no entry here has nothing to automate — either it
     *  genuinely needs no setup (web servers, Docker) or Stage 4 hasn't
     *  authored its script yet. */
    public const ROLE_SETUP_SCRIPTS = ['postgres-db', 'mysql-db', 'mongodb', 'mssql-db', 'docker-hosts'];

    /** Roles needing a REAL, tenant-provided database credential fetched
     *  at runtime — genuinely Windows-only right now: Linux's
     *  mysql-db.sh/postgres-db.sh both authenticate via root + local
     *  Unix-socket trust, no password ever needed. Windows database
     *  engines have no equivalent passwordless local auth — confirmed
     *  directly: MariaDB on Windows requires TCP+password for every
     *  account, always. Checked together with platform, never as a flat
     *  role list, so a Linux run of the same role never pointlessly
     *  gets a credential token it doesn't need. */
    public const ROLES_NEEDING_DB_CREDENTIAL = ['mysql-db', 'mssql-db', 'postgres-db'];

    /** Roles whose setup script restarts a live service — these need a
     *  confirmation step, mirroring the existing device-removal pattern,
     *  since a restart briefly interrupts real connections. */
    public const ROLE_NEEDS_CONFIRMATION = ['postgres-db', 'mysql-db', 'mssql-db', 'oracle-db', 'mongodb'];

    /** Real, role-specific confirmation text — the generic "this will
     *  briefly restart it" was WRONG for mongodb (its setup never
     *  restarts the database at all; it turns on full query logging
     *  indefinitely, a real, ongoing performance cost instead). Brian
     *  correctly caught this rather than let a technically-true-elsewhere
     *  message mislead a tenant about what a specific role actually does. */
    public const ROLE_CONFIRMATION_TEXT = [
        'postgres-db' => 'On Linux this briefly restarts PostgreSQL; on Windows it applies instantly with no restart.',
        'mysql-db'    => 'This will briefly restart MySQL/MariaDB.',
        'mssql-db'    => 'This grants a one-time permission using this account, then never uses it again -- it does not restart SQL Server.',
        'oracle-db'   => 'This will briefly restart Oracle Database.',
        'mongodb'     => 'This will turn on full query logging, which has an ongoing performance cost on a busy database — it does not restart MongoDB.',
    ];

    public ?string $confirmingRoleHostId = null;
    public ?string $confirmingRoleHostname = null;
    public ?string $confirmingRoleGroup = null;
    public ?string $confirmingRolePlatform = null;

    public ?int $credentialFormHostId = null;
    public ?string $credentialFormHostname = null;
    public ?string $credentialFormRoleGroup = null;
    public ?string $credentialFormPlatform = null;
    public string $credentialUsername = '';
    public string $credentialPassword = '';
    public bool $editingCredentialOnly = false;

    public function confirmAddRole(int $hostId, string $hostname, string $roleGroup, string $platform = ''): void
    {
        // Windows database roles need a REAL, tenant-provided credential
        // before setup can even run (no passwordless local auth exists
        // on Windows database engines, unlike Linux). If none is stored
        // yet for this role, capture it first — never proceed with a
        // guessed or hardcoded credential.
        if ($platform === 'windows' && in_array($roleGroup, self::ROLES_NEEDING_DB_CREDENTIAL, true)) {
            // REAL, SERIOUS GAP FIXED, TWICE: a first fix checked for
            // THIS device's own credential, falling back to a
            // tenant-wide "shared/default" one if it existed -- but
            // Brian correctly, directly identified this fallback
            // itself as the real, remaining danger: confirmed directly
            // that WinClient and the domain controller are BOTH real,
            // current members of the mssql-db Wazuh group, and a
            // shared fallback would silently apply ONE device's real
            // account (e.g. "AICEXPERTS\Administrateur") to a
            // genuinely different device that needs its OWN, different
            // one (e.g. "WinClient\Brian"). Real, complete fix: no
            // fallback at all -- always require an explicit, real,
            // device-specific credential.
            $existingForHost = \Illuminate\Support\Facades\DB::table('tenant_role_credentials')
                ->where('tenant_id', $this->tenantId)->where('role_group', $roleGroup)
                ->where('fleet_host_id', $hostId)->exists();
            if (!$existingForHost) {
                $this->credentialFormHostId = $hostId;
                $this->credentialFormHostname = $hostname;
                $this->credentialFormRoleGroup = $roleGroup;
                $this->credentialFormPlatform = $platform;
                return;
            }
        }

        if (in_array($roleGroup, self::ROLE_NEEDS_CONFIRMATION, true)) {
            $this->confirmingRoleHostId = $hostId;
            $this->confirmingRoleHostname = $hostname;
            $this->confirmingRoleGroup = $roleGroup;
            $this->confirmingRolePlatform = $platform;
            return;
        }
        $this->addRole($hostId, $hostname, $roleGroup, $platform);
    }

    /**
     * Real, necessary gap fix: previously, once a credential was stored,
     * there was NO way to correct it if a wrong username/password had
     * been entered -- the credential form only ever appeared again if
     * NONE existed yet. This lets a tenant deliberately re-open the form
     * for a role that already has a stored credential, to overwrite it.
     */
    public function editRoleCredential(string $roleGroup, ?int $hostId = null, string $hostname = ''): void
    {
        // REAL FIX: now accepts an optional real hostId, so a specific
        // device's own stored credential can be corrected directly,
        // not just the shared/default one -- matching the same
        // device-aware model now used everywhere else for credentials.
        $this->credentialFormHostId = $hostId; // null = editing the shared/default credential
        $this->credentialFormHostname = $hostname;
        $this->credentialFormRoleGroup = $roleGroup;
        $this->credentialFormPlatform = '';
        $this->credentialUsername = '';
        $this->credentialPassword = '';
        $this->editingCredentialOnly = true;
    }


    public function saveRoleCredential(): void
    {
        if (!$this->credentialUsername || !$this->credentialPassword) {
            $this->roleMessage = __('Please provide both a username and password.');
            return;
        }

        // REAL FIX: the match/insert key now includes fleet_host_id,
        // so a device-specific credential and the shared/default one
        // (fleet_host_id null) are genuinely, correctly distinct rows.
        \Illuminate\Support\Facades\DB::table('tenant_role_credentials')->updateOrInsert(
            [
                'tenant_id' => $this->tenantId,
                'role_group' => $this->credentialFormRoleGroup,
                'fleet_host_id' => $this->credentialFormHostId,
            ],
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'username' => $this->credentialUsername,
                'encrypted_password' => \Illuminate\Support\Facades\Crypt::encryptString($this->credentialPassword),
                'created_at' => now(), 'updated_at' => now(),
            ]
        );

        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'role_credential.stored', 'success', 'wazuh', [
            'role_group' => $this->credentialFormRoleGroup,
        ]);

        $hostId = $this->credentialFormHostId;
        $hostname = $this->credentialFormHostname;
        $roleGroup = $this->credentialFormRoleGroup;
        $platform = $this->credentialFormPlatform;
        $wasEditingOnly = $this->editingCredentialOnly;

        $this->credentialFormHostId = null;
        $this->credentialFormHostname = null;
        $this->credentialFormRoleGroup = null;
        $this->credentialFormPlatform = null;
        $this->credentialUsername = '';
        $this->credentialPassword = '';
        $this->editingCredentialOnly = false;

        if ($wasEditingOnly) {
            // Deliberate credential correction, not part of adding a role
            // to a specific device -- nothing further to launch.
            $this->roleMessage = __('Credential updated for :role.', ['role' => self::TIER2_ROLES[$roleGroup] ?? $roleGroup]);
            return;
        }

        // Credential now stored -- proceed with the original add-role request.
        $this->confirmAddRole($hostId, $hostname, $roleGroup, $platform);
    }

    public function cancelCredentialForm(): void
    {
        $this->credentialFormHostId = null;
        $this->credentialFormHostname = null;
        $this->credentialFormRoleGroup = null;
        $this->credentialFormPlatform = null;
        $this->credentialUsername = '';
        $this->credentialPassword = '';
        $this->editingCredentialOnly = false;
    }

    public function cancelAddRole(): void
    {
        $this->confirmingRoleHostId = null;
        $this->confirmingRoleHostname = null;
        $this->confirmingRoleGroup = null;
        $this->confirmingRolePlatform = null;
    }

    public function addRole(int $hostId, string $hostname, string $roleGroup, string $platform = ''): void
    {
        $this->roleMessage = null;
        $this->rolePrerequisite = null;
        $this->confirmingRoleHostId = null;
        $this->confirmingRoleHostname = null;
        $this->confirmingRoleGroup = null;
        $this->confirmingRolePlatform = null;

        if (!array_key_exists($roleGroup, self::TIER2_ROLES)) {
            $this->roleMessage = __('Unknown role.');
            return;
        }

        if (!$this->wazuhGroup) {
            $this->roleMessage = __('Security monitoring is not set up for this organization.');
            return;
        }

        try {
            $wazuh = app(\App\Clients\WazuhClient::class);
            $token = $wazuh->token();
            $agent = $wazuh->findAgentInGroup($token, $this->wazuhGroup, $hostname);

            if (!$agent || !$agent['id']) {
                $this->roleMessage = __('Could not find this device in monitoring — it may not be fully enrolled yet.');
                return;
            }

            $wazuh->addAgentToGroup($token, $agent['id'], $roleGroup);

            if (in_array($roleGroup, self::ROLE_SETUP_SCRIPTS, true)) {
                $this->launchRoleSetup($hostId, $hostname, $roleGroup, $platform);
                $this->roleMessage = __(':role monitoring added to :host. Setting up audit logging automatically — this may take a minute.', ['role' => self::TIER2_ROLES[$roleGroup], 'host' => $hostname]);
            } else {
                $this->roleMessage = __(':role monitoring added to :host.', ['role' => self::TIER2_ROLES[$roleGroup], 'host' => $hostname]);
                $this->rolePrerequisite = self::ROLE_PREREQUISITES[$roleGroup] ?? null;
            }
        } catch (\Throwable $e) {
            \Log::warning('Assets: addRole failed', ['host' => $hostname, 'group' => $roleGroup, 'error' => $e->getMessage()]);
            $this->roleMessage = __('Could not add that monitoring role. Please try again, or contact support.');
        }
    }

    /**
     * Launches the role's real setup script on the device via Fleet Scripts
     * — NON-BLOCKING (FleetScriptRunner::launch(), not runAndWait(), whose
     * own docs explicitly warn against calling it from a web request for
     * anything beyond a trivial, fast command; installing a package and
     * restarting a service is not that). A scheduled command
     * (role-setup:poll-runs) polls for completion separately.
     */
    /**
     * REAL ARCHITECTURAL CHANGE, after a whole session of real, repeated
     * problems: Fleet's OWN script_contents transport (not PowerShell,
     * not our own code) corrupts backtick escapes and non-ASCII
     * characters, and rejects anything over 10,000 characters -- a
     * hard, real ceiling hit repeatedly as real scripts grew with real
     * fixes and their own documenting comments (MSSQL's setup script
     * alone needed size-driven trims FOUR separate times in one night).
     *
     * NEW design: Fleet's own script_contents is now always a tiny,
     * generic TRIGGER script -- the same near-constant shape for every
     * role, well under 1,000 characters, immune to the size limit
     * forever. The trigger fetches the REAL, complete, unmodified
     * script from /enroll/role-script via a normal HTTPS call (which
     * has none of Fleet's own transport quirks -- a real script can now
     * be any length, use real backticks/em-dashes freely) and executes
     * it via Invoke-Expression / bash -c.
     *
     * The existing credential-token mechanism (role_credential_tokens,
     * /enroll/role-credential) is UNCHANGED -- the real script still
     * fetches its own database credential the same way it always did;
     * only how the SCRIPT ITSELF reaches the device has changed.
     */
    private function launchRoleSetup(int $hostId, string $hostname, string $roleGroup, string $platform = ''): void
    {
        $ext = ($platform === 'windows') ? 'ps1' : 'sh';
        $scriptPath = base_path("ops/wazuh/role-setup-scripts/{$roleGroup}.{$ext}");
        if (!file_exists($scriptPath)) {
            \Log::warning('Assets: role setup script missing', ['role' => $roleGroup, 'platform' => $platform, 'ext' => $ext]);
            return;
        }

        $scriptToken = bin2hex(random_bytes(32));
        \Illuminate\Support\Facades\DB::table('role_script_tokens')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'token' => $scriptToken,
            'tenant_id' => $this->tenantId,
            'role_group' => $roleGroup,
            'platform' => $platform,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $portalUrl = config('app.url');

        if ($platform === 'windows') {
            // REAL BUG FOUND on a real device: the original trigger had
            // no error handling at all -- when the fetch genuinely
            // failed (here: a real ModSecurity 403 on the response
            // body), Invoke-Expression errored on a null command, but
            // the overall exit code still let the run report as
            // successful. Added real error handling: check the fetch
            // genuinely succeeded and returned real content before
            // executing anything, exit 1 with a clear message otherwise.
            $trigger = "\$t = '{$scriptToken}'; \$u = '{$portalUrl}'\n"
                . "try {\n"
                . "  \$s = (Invoke-WebRequest -Uri \"\$u/enroll/role-script\" -Method POST -Body @{ token = \$t } -UseBasicParsing).Content\n"
                . "} catch {\n"
                . "  Write-Host \"ERROR: could not fetch the real setup script: \$(\$_.Exception.Message)\"\n"
                . "  exit 1\n"
                . "}\n"
                . "if (-not \$s) {\n"
                . "  Write-Host \"ERROR: script fetch returned empty content.\"\n"
                . "  exit 1\n"
                . "}\n"
                . "Invoke-Expression \$s\n";
        } else {
            // REAL BUG FOUND on a real device: this trigger had zero error
            // handling -- the same class of silent-failure bug already
            // found and fixed on the Windows trigger. Confirmed directly:
            // "curl: (52) Empty reply from server" was reported as the
            // real output, yet the overall run was recorded as
            // "success" -- because eval on EMPTY content is itself a
            // harmless no-op that exits 0, masking the real fetch
            // failure entirely. Fixed with the same real checks already
            // proven on the Windows side: verify curl itself succeeded,
            // and verify the fetched content is genuinely non-empty,
            // before ever running it.
            $trigger = "#!/bin/bash\n"
                . "T='{$scriptToken}'\n"
                . "U='{$portalUrl}'\n"
                . "S=\$(curl -sS -X POST \"\$U/enroll/role-script\" -d \"token=\$T\")\n"
                . "CURL_EXIT=\$?\n"
                . "if [ \$CURL_EXIT -ne 0 ]; then\n"
                . "  echo \"ERROR: could not fetch the real setup script (curl exit code \$CURL_EXIT).\"\n"
                . "  exit 1\n"
                . "fi\n"
                . "if [ -z \"\$S\" ]; then\n"
                . "  echo \"ERROR: script fetch returned empty content.\"\n"
                . "  exit 1\n"
                . "fi\n"
                . "eval \"\$S\"\n";
        }

        // Roles needing an authenticated database credential get a real,
        // short-lived, single-use token injected at the top of the
        // TRIGGER script -- the real script (fetched separately) reads
        // it from the same environment variables as before. Genuinely
        // necessary on Windows: unlike Linux (root + MariaDB's Unix-
        // socket trust auth, no password ever needed), Windows database
        // engines have no equivalent passwordless local auth.
        if ($platform === 'windows' && in_array($roleGroup, self::ROLES_NEEDING_DB_CREDENTIAL, true)) {
            $credToken = bin2hex(random_bytes(32));
            \Illuminate\Support\Facades\DB::table('role_credential_tokens')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'token' => $credToken,
                'tenant_id' => $this->tenantId,
                'role_group' => $roleGroup,
                'fleet_host_id' => $hostId,
                'expires_at' => now()->addMinutes(10),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $trigger = "\$env:OBAITS_ROLE_CREDENTIAL_TOKEN = '{$credToken}'\n\$env:OBAITS_PORTAL_URL = '{$portalUrl}'\n" . $trigger;
        }

        $launched = \App\Support\Backup\FleetScriptRunner::launch($hostId, $trigger, session('auth_email'), $this->tenantId);

        \Illuminate\Support\Facades\DB::table('role_setup_runs')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenantId,
            'fleet_host_id' => $hostId,
            'hostname' => $hostname,
            'role_group' => $roleGroup,
            'fleet_execution_id' => $launched['execution_id'] ?? null,
            'status' => $launched['ok'] ? 'running' : 'failed',
            'output' => $launched['ok'] ? null : ('Launch failed: ' . $launched['error']),
            'started_at' => now(),
            'finished_at' => $launched['ok'] ? null : now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function cancelRemoval(): void
    {
        $this->confirmingRemovalId = null;
        $this->confirmingRemovalName = null;
    }

    // Step 2 of 2: actually remove — but ONLY after re-confirming the host
    // still belongs to THIS tenant. Never trust a client-supplied id blindly,
    // even one that came from our own confirm dialog a moment earlier.
    public function removeDevice(int $hostId): void
    {
        $this->removalError = null;
        $this->removalNotice = null;

        if (!$this->fleetSecret) {
            $this->removalError = __('Device monitoring is not set up for this organization.');
            return;
        }

        $owned = collect(app(\App\Clients\FleetClient::class)->hostsForTenant($this->fleetSecret))
            ->first(fn ($h) => (int) ($h['id'] ?? 0) === $hostId);

        if (!$owned) {
            // Either already removed, or (should never happen) not this tenant's.
            // REAL BUG FOUND during i18n audit: single-quoted PHP strings do
            // NOT interpret \u{...} escape sequences -- that syntax only
            // works in double-quoted strings. This literal, broken text
            // was genuinely visible to real users. Fixed with an actual
            // em dash character.
            $this->removalError = __('That device could not be found in your organization — it may already have been removed.');
            $this->confirmingRemovalId = null;
            return;
        }

        $hostname = $owned['hostname'] ?? ($owned['display_name'] ?? 'that device');

        // REAL REDESIGN 2026-08-06: this method used to delete Fleet
        // SYNCHRONOUSLY, first, inside the web request -- then best-effort
        // Wazuh, then queue Headscale. Two real problems with that: (1) it
        // never touched Zabbix, backup_selections, or tenant_role_credentials
        // at all, leaving real access behind on every removal; (2) deleting
        // Fleet FIRST destroyed the host record every later step needed to
        // resolve the device, so a Fleet-adjacent failure could silently
        // abort revocation of the more dangerous systems (VPN, backups).
        //
        // Now this only ever QUEUES the removal. obaits:process-device-removals
        // (scheduled) does the real work, in the opposite, exposure-ordered
        // sequence -- most dangerous access revoked first, Fleet deleted
        // last -- and retries whatever fails rather than losing it.
        \Illuminate\Support\Facades\DB::table('device_removals')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenantId,
            'fleet_host_id' => $hostId,
            'hostname' => $hostname,
            'reason' => 'decommissioned',
            'requested_by' => session('auth_email'),
            'requested_at' => now(),
            'artifact_status' => json_encode([]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'device.removal_requested', 'success', null,
            ['hostname' => $hostname, 'fleet_host_id' => $hostId]);

        $this->confirmingRemovalId = null;
        $this->confirmingRemovalName = null;
        $this->removalNotice = __('Device removal has started. Access across all systems will be revoked shortly.');
    }
}