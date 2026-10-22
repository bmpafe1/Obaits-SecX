# Real OS Update Enforcement check -- Windows.
#
# REAL BUG FOUND 2026-08-12, confirmed live on the real AICEXPERTS
# Windows Server: Get-WUList returns its results as a SINGLE
# COM-wrapped Collection object (System.__ComObject elements), NOT as
# individual objects flowing through the pipeline the normal PowerShell
# way. Every "| Where-Object"/"| Select-Object"/"| Measure-Object"
# against its output silently operated on the one outer wrapper only --
# confirmed directly: this caused every SQL Server update to be
# reported as "excluded" when it was never actually being evaluated at
# all, and the check overall wrongly reported "up_to_date" while 9 real
# updates (including 4 real SQL Server ones) were genuinely pending.
# The ONLY reliable fix is direct index/foreach enumeration against the
# Collection -- never pipe Get-WUList's output through "|" at all.
#
# Excludes this platform's own managed agents (Fleet, Tailscale, Wazuh,
# Zabbix) AND the tenant's own real production SQL Server 2019 stack,
# confirmed present on a real device -- a botched SQL Server update is a
# genuinely worse real-world outcome than a service restart.
try {
    Import-Module PSWindowsUpdate -ErrorAction Stop
} catch {
    Write-Host "ERROR: PSWindowsUpdate module not available on this device."
    exit 1
}

$excludeTitles = @("*Fleet osquery*", "*Tailscale*", "*Wazuh*", "*Zabbix*", "*SQL Server*")

# TEMPORARY, ENVIRONMENT-SPECIFIC exclusion, added 2026-08-13: KB5099538
# is an 18GB Windows Server cumulative update that cannot realistically
# download on this device's current unstable/limited internet connection
# -- confirmed live, the install attempt failed partway through. This is
# NOT a platform-safety exclusion like the ones above; it exists purely
# because of a real, current bandwidth constraint on this one device and
# should be removed once that constraint is gone. Matched by KB number,
# not title text, since it's a precise, unambiguous identifier.
$excludeKBs = @("KB5099538")
$col = Get-WUList -MicrosoftUpdate
$pending = 0

foreach ($item in $col) {
    $title = $item.Title
    $excluded = $false
    foreach ($pattern in $excludeTitles) {
        if ($title -like $pattern) { $excluded = $true; break }
    }
    if ($excludeKBs -contains $item.KB) { $excluded = $true }
    if (-not $excluded) { $pending++ }
}

$hostname = $env:COMPUTERNAME
if ($pending -gt 0) {
    $result = "updates_pending:$pending"
} else {
    $result = "up_to_date"
}

try {
    Invoke-WebRequest -Uri "$u/enroll/policy-result" -Method POST -Body @{ policy_run_id = $runId; hostname = $hostname; result = $result } -UseBasicParsing | Out-Null
} catch {
    Write-Host "WARNING: could not report result: $($_.Exception.Message)"
}
Write-Host "OS update check: $result"
