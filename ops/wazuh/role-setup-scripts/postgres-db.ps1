# =====================================================================
# Obaits: PostgreSQL audit setup - Windows
# Real facts confirmed directly on WinClient (PostgreSQL 18, community
# edition, NOT EDB Advanced Server - confirmed edb_audit settings do
# NOT exist in postgresql.conf on this install):
#   - pg_hba.conf uses scram-sha-256 (password auth) throughout, even
#     for local/loopback connections - unlike SQL Server's Windows-only
#     default, this needs a real, tenant-provided database credential.
#   - pgaudit has NO official pre-built Windows binaries at all - the
#     only documented path is compiling from source with Visual Studio,
#     genuinely impractical to automate via a Fleet Script. Real,
#     honest alternative: PostgreSQL's own built-in log_statement
#     setting, confirmed via pg_settings.context = 'superuser' - can be
#     changed at RUNTIME (ALTER SYSTEM + pg_reload_conf()), no service
#     restart needed at all, genuinely simpler than MariaDB/MSSQL.
# =====================================================================
$ErrorActionPreference = 'Continue'
Write-Host "=== Obaits: PostgreSQL audit setup (Windows) ==="

$service = Get-CimInstance Win32_Service -Filter "Name LIKE 'postgresql%'" -ErrorAction SilentlyContinue
if (-not $service) {
    Write-Host "ERROR: PostgreSQL service not found."
    exit 1
}

# Real binary path confirmed via the service itself, same lesson as
# MariaDB/MSSQL: never trust PATH inside a Fleet Script.
if ($service.PathName -match '"([^"]+)\\bin\\pg_ctl\.exe"') {
    $binDir = $matches[1] + '\bin'
    $psqlExe = Join-Path $binDir "psql.exe"
} else {
    Write-Host "ERROR: could not parse PostgreSQL binary path from service."
    exit 1
}

if (-not (Test-Path $psqlExe)) {
    Write-Host "ERROR: psql.exe not found at expected path: $psqlExe"
    exit 1
}

Write-Host "Using psql client: $psqlExe"

if (-not $env:OBAITS_ROLE_CREDENTIAL_TOKEN -or -not $env:OBAITS_PORTAL_URL) {
    Write-Host "ERROR: no credential token provided - cannot authenticate to the database."
    exit 1
}

try {
    $credResponse = Invoke-WebRequest -Uri "$($env:OBAITS_PORTAL_URL)/enroll/role-credential" -Method POST -Body @{ token = $env:OBAITS_ROLE_CREDENTIAL_TOKEN } -UseBasicParsing
    $cred = $credResponse.Content | ConvertFrom-Json
    $dbUsername = $cred.username
    $dbPassword = $cred.password
} catch {
    Write-Host "ERROR: could not fetch database credential: $($_.Exception.Message)"
    exit 1
}

if (-not $dbPassword) {
    Write-Host "ERROR: credential fetch succeeded but returned no password."
    exit 1
}

$env:PGPASSWORD = $dbPassword

function Get-PgScalar {
    param([string]$Query)
    $r = & $psqlExe -U $dbUsername -h localhost -t -A -c $Query 2>&1
    return ($r | Select-Object -Last 1).ToString().Trim()
}

$currentSetting = Get-PgScalar "SHOW log_statement;"
if ($currentSetting -eq 'all') {
    Write-Host "log_statement already set to 'all'."
} else {
    Write-Host "Setting log_statement to 'all'..."
    & $psqlExe -U $dbUsername -h localhost -c "ALTER SYSTEM SET log_statement = 'all';" 2>&1
    & $psqlExe -U $dbUsername -h localhost -c "SELECT pg_reload_conf();" 2>&1

    $verifySetting = Get-PgScalar "SHOW log_statement;"
    if ($verifySetting -ne 'all') {
        Write-Host "ERROR: log_statement is still not 'all' after reload (got: $verifySetting)."
        exit 1
    }
}

Write-Host "Confirmed: log_statement genuinely set to 'all' (no restart needed for this setting)."
Write-Host "Real audit content will appear in PostgreSQL's own log directory."
Write-Host "=== Obaits: PostgreSQL audit setup (Windows) complete ==="
