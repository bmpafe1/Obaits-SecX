# =====================================================================
# Obaits: MariaDB/MySQL audit setup - Windows
# Mirrors mysql-db.sh's Linux logic exactly, but using real, verified
# Windows facts (confirmed directly on WinClient, MariaDB 12.3):
#   - mysql.exe is NOT on PATH by default; must use the real service
#     binary path, found via the service's own PathName.
#   - plugin_dir is a real, verified path under "Program Files"
#   - log_error defaults to a PER-HOSTNAME file (e.g. WinClient.err),
#     not a generic name - must be resolved dynamically, never assumed.
#   - the audit plugin ships as server_audit.dll (not .so), same
#     bundled-but-not-enabled pattern as Linux.
# =====================================================================
$ErrorActionPreference = 'Continue'
Write-Host "=== Obaits: MariaDB/MySQL audit setup (Windows) ==="

$service = Get-CimInstance Win32_Service -Filter "Name='MariaDB'" -ErrorAction SilentlyContinue
if (-not $service) {
    Write-Host "ERROR: MariaDB service not found."
    exit 1
}

# Real binary path confirmed via the service itself - e.g.
# "C:\Program Files\MariaDB 12.3\bin\mysqld.exe" "--defaults-file=...\my.ini" "MariaDB"
if ($service.PathName -match '"([^"]+mysqld\.exe)"') {
    $mysqldPath = $matches[1]
    $binDir = Split-Path $mysqldPath -Parent
    $mysqlExe = Join-Path $binDir "mysql.exe"
} else {
    Write-Host "ERROR: could not parse MariaDB binary path from service."
    exit 1
}

if (-not (Test-Path $mysqlExe)) {
    Write-Host "ERROR: mysql.exe not found at expected path: $mysqlExe"
    exit 1
}

Write-Host "Using mysql client: $mysqlExe"

# Real, tenant-provided credential - fetched at RUNTIME via a short-
# lived, single-use token (injected at the top of this script by
# launchRoleSetup()), never embedded as a permanent value in the script
# text itself. Fleet stores script_contents verbatim in its own
# database, so a real, reusable password baked in here would be a
# second, uncontrolled copy of something we otherwise encrypt carefully.
if (-not $env:OBAITS_ROLE_CREDENTIAL_TOKEN -or -not $env:OBAITS_PORTAL_URL) {
    Write-Host "ERROR: no credential token provided - cannot authenticate to the database."
    exit 1
}

try {
    $credResponse = Invoke-WebRequest -Uri "$($env:OBAITS_PORTAL_URL)/enroll/role-credential" -Method POST -Body @{ token = $env:OBAITS_ROLE_CREDENTIAL_TOKEN } -UseBasicParsing
    $cred = $credResponse.Content | ConvertFrom-Json
    $dbUsername = $cred.username
    $rootPassword = $cred.password
} catch {
    Write-Host "ERROR: could not fetch database credential: $($_.Exception.Message)"
    exit 1
}

if (-not $rootPassword) {
    Write-Host "ERROR: credential fetch succeeded but returned no password."
    exit 1
}

function Invoke-Mysql {
    param([string]$Sql)
    & $mysqlExe -u $dbUsername "-p$rootPassword" -N -e $Sql 2>&1
}

$pluginDir = (Invoke-Mysql "SHOW VARIABLES LIKE 'plugin_dir';") -split "\t" | Select-Object -Last 1
$pluginDll = Join-Path $pluginDir.Trim() "server_audit.dll"

if (-not (Test-Path $pluginDll)) {
    Write-Host "ERROR: server_audit.dll not found in plugin directory: $pluginDir"
    exit 1
}

$alreadyLoaded = Invoke-Mysql "SHOW PLUGINS;" | Select-String "SERVER_AUDIT"
if ($alreadyLoaded) {
    Write-Host "server_audit plugin already loaded."
} else {
    Write-Host "Installing server_audit plugin..."
    Invoke-Mysql "INSTALL PLUGIN server_audit SONAME 'server_audit.dll';"
}

# Real log_error path resolved dynamically - confirmed this is genuinely
# per-hostname (WinClient.err), never a fixed generic filename.
$logError = (Invoke-Mysql "SHOW VARIABLES LIKE 'log_error';") -split "\t" | Select-Object -Last 1
$logError = $logError.Trim()
$dataDir = Split-Path $logError -Parent
# REAL BUG FOUND on a real device: a backslash-joined path came back
# from the config file with a character silently dropped -- confirmed
# directly: SHOW VARIABLES showed a mangled path with the leading
# "s" of "server_audit.log" swallowed along with its separator. This
# project's own Fleet Scripts transport has already shown it can
# mangle backslash-adjacent content today (backticks, non-ASCII) --
# since this file's own pre-existing datadir line uses forward slashes
# and has never been corrupted, using forward slashes here too.
$auditLogPathRaw = $dataDir -replace '\\', '/'
$auditLogPathRaw = $auditLogPathRaw.TrimEnd('/')
$auditLogPath = "$auditLogPathRaw/server_audit.log"



Write-Host "Real audit log will be written to: $auditLogPath"

# Configure real, persistent settings. Unlike Linux (a separate,
# dedicated config snippet file under conf.d), this Windows MariaDB
# install has no confirmed conf.d convention to rely on - writing
# directly to the real my.ini next to the data directory instead.
$obaitsConfPath = Join-Path $dataDir "my.ini"

# REAL BUG FOUND on a real device: a blind Add-Content append landed
# our settings after whatever section happened to be LAST in the file
# ([client], not [mysqld]) -- .ini files are sectioned, and MariaDB
# correctly rejected server-only settings filed under [client] as
# unknown variables. Confirmed directly: SHOW PLUGINS never showed
# SERVER_AUDIT loaded, and mysql.exe itself errored on every one of our
# settings at every invocation after the "fix". Real fix: insert directly
# after the real [mysqld] section header, line by line, not appended
# blindly to file end.
$existingConf = Get-Content $obaitsConfPath -Raw -ErrorAction SilentlyContinue
if ($existingConf -match 'server_audit_logging') {
    Write-Host "Audit settings already present in my.ini."
} else {
    Write-Host "Adding audit settings to the [mysqld] section of my.ini..."
    $confLinesArray = Get-Content $obaitsConfPath
    $mysqldIndex = -1
    for ($i = 0; $i -lt $confLinesArray.Count; $i++) {
        if ($confLinesArray[$i].Trim() -eq '[mysqld]') {
            $mysqldIndex = $i
            break
        }
    }

    if ($mysqldIndex -eq -1) {
        Write-Host "ERROR: no [mysqld] section found in my.ini -- cannot safely add settings."
        exit 1
    }

    $newSettings = @(
        "server_audit_logging=ON",
        "server_audit_events=CONNECT,QUERY,TABLE",
        "server_audit_file_path=$auditLogPath",
        "server_audit_output_type=FILE"
    )

    $updatedLines = @()
    $updatedLines += $confLinesArray[0..$mysqldIndex]
    $updatedLines += $newSettings
    if ($mysqldIndex + 1 -lt $confLinesArray.Count) {
        $updatedLines += $confLinesArray[($mysqldIndex + 1)..($confLinesArray.Count - 1)]
    }

    Set-Content -Path $obaitsConfPath -Value $updatedLines
}

Write-Host "Restarting MariaDB to apply audit configuration..."
Restart-Service -Name "MariaDB"
Start-Sleep -Seconds 5

$svc = Get-Service -Name "MariaDB"
if ($svc.Status -ne 'Running') {
    Write-Host "ERROR: MariaDB did not come back online after restart!"
    exit 1
}
Write-Host "MariaDB restarted successfully and is online."

# REAL, MISSING PREREQUISITE, confirmed necessary on Linux for both
# Postgres and MySQL - the Wazuh agent's OWN Windows service account
# needs read access to the audit log file. On Windows, the Wazuh agent
# runs as LocalSystem by default (unlike Linux's dedicated 'wazuh' user),
# which typically already has broad read access - but this is NOT yet
# independently verified on a real device the way the Linux permission
# fixes were. Flagged honestly rather than assumed.
Write-Host "NOTE: file-permission behavior for the Wazuh agent reading this log has NOT yet been independently verified on Windows - check directly before trusting this is sufficient."

Write-Host "=== Obaits: MariaDB/MySQL audit setup (Windows) complete ==="
