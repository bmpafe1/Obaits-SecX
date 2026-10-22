# Real OS Update Enforcement remediate -- Windows.
#
# REAL BUGS FOUND 2026-08-12, confirmed live against a real device with
# real SQL Server 2019 production data, before anything was ever
# installed:
#   1. Get-WUList's output is a COM-wrapped Collection that PowerShell's
#      "|" pipe does not reliably enumerate -- see check.ps1's own
#      comment. Never pipe its output; always foreach directly.
#   2. -NotTitle does NOT accept a string array -- confirmed directly,
#      throws a real ParameterBindingException.
#   3. -NotTitle treats its argument as a REGULAR EXPRESSION, not a
#      wildcard pattern -- confirmed directly, "*SQL Server*" throws a
#      real regex parse error ("quantifier not preceded by anything").
#      -NotTitle is NOT a safe or reliable exclusion mechanism in this
#      PSWindowsUpdate version and is not used anywhere in this script.
#
# Real, robust fix: build the exact list of SAFE update IDs ourselves,
# using the same proven foreach-based title matching as check.ps1, then
# pass those explicit GUIDs via -UpdateID (a real WUAPI-native parameter,
# confirmed to accept a string[] via Get-Help). No title-matching of any
# kind happens at install time -- only real, pre-verified update IDs are
# ever passed to Install-WindowsUpdate.
#
# REAL INCIDENT 2026-08-12: even with a fully correct exclusion list, a
# real remediate run on this exact device (with real SQL Server 2019
# production data) caused MSSQLSERVER and WazuhSvc to both stop --
# confirmed directly, neither was ever in the update list at all; this
# was a real side effect of installing a large, unrelated, correctly
# non-excluded cumulative Windows update (an 18GB Windows Server
# cumulative update). Exclusion alone is NOT sufficient protection on
# Windows the way it is on Linux -- large cumulative updates can disrupt
# unrelated running services as part of their own normal installation
# behaviour. Real fix: snapshot every running service BEFORE the install,
# snapshot again AFTER, and restart anything that was running before and
# isn't after -- a genuine before/after diff, not a hardcoded list, so
# this generalises to any tenant's own real, unpredictable software
# (SQL Server here; something else entirely on a different device),
# not just this platform's own four managed agents.
#
# Excludes this platform's own managed agents and the tenant's real SQL
# Server 2019 stack from being UPDATED. Never reboots -- reboot is
# always left to a human.
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
$hostname = $env:COMPUTERNAME

function Get-SafeUpdates {
    # Returns the FULL PSWindowsUpdate objects (not just IDs) for every
    # currently-pending update that does NOT match an exclusion pattern.
    # Same proven enumeration as check.ps1 -- direct foreach against the
    # Collection, never piped.
    $col = Get-WUList -MicrosoftUpdate
    $safe = @()
    foreach ($item in $col) {
        $title = $item.Title
        $excluded = $false
        foreach ($pattern in $excludeTitles) {
            if ($title -like $pattern) { $excluded = $true; break }
        }
        if ($excludeKBs -contains $item.KB) { $excluded = $true }
        if (-not $excluded) { $safe += $item }
    }
    return $safe
}

function Get-RunningServiceNames {
    # Real, current snapshot of every running service's short Name --
    # used before AND after the install to compute a genuine diff,
    # rather than checking a fixed, hardcoded list of services that
    # would not generalise beyond this one platform's own agents.
    return @(Get-Service | Where-Object { $_.Status -eq 'Running' } | Select-Object -ExpandProperty Name)
}

# --- Snapshot BEFORE anything touches updates -------------------------------
$servicesBefore = Get-RunningServiceNames

$beforeList = Get-SafeUpdates
$before = $beforeList.Count

if ($before -eq 0) {
    $result = "up_to_date"
    $exitCode = 0
    Write-Host "No non-excluded updates pending -- nothing to install."
} else {
    $safeUpdateIDs = @($beforeList | ForEach-Object { $_.Identity.UpdateID })
    Write-Host "Installing $before update(s), explicit IDs only, excluding platform agents and SQL Server:"
    # REAL BUG FOUND 2026-08-13, confirmed live on two separate real
    # install runs: this loop variable was named $u, silently colliding
    # with and overwriting the trigger-injected portal URL variable of
    # the SAME name -- the loop only runs when real updates are actually
    # found, which is exactly why this never surfaced during the earlier
    # no-op tests. By the time the script tried to POST its real result,
    # $u no longer held the URL string; it held the last update object
    # from this loop, and PowerShell's string interpolation on that COM
    # object silently produced the literal text "system.__comobject",
    # which then failed to resolve as a hostname. Renamed to $updateItem
    # -- never reuse $u, $t, $runId, or $priorState as a loop variable
    # anywhere in a policy script; these are all reserved by the trigger.
    foreach ($updateItem in $beforeList) { Write-Host "  - $($updateItem.Title)" }

    try {
        Install-WindowsUpdate -MicrosoftUpdate -UpdateID $safeUpdateIDs -AcceptAll -IgnoreReboot -Confirm:$false -ErrorAction Stop | Out-Null
        $exitCode = 0
    } catch {
        Write-Host "ERROR during install: $($_.Exception.Message)"
        $exitCode = 1
    }

    $afterList = Get-SafeUpdates
    $after = $afterList.Count

    if ($after -gt 0) {
        $result = "updates_pending:$after"
    } else {
        $result = "up_to_date"
    }
}

# --- Post-install health check + auto-recovery, ALWAYS runs regardless -----
# of whether the install above succeeded or failed -- a partially-applied
# update is exactly the scenario most likely to leave a service down.
Write-Host "Checking for any service that stopped during this run..."
$servicesAfter = Get-RunningServiceNames
$stoppedServices = @($servicesBefore | Where-Object { $servicesAfter -notcontains $_ })

$recoveredServices = @()
$failedRecoveries = @()

if ($stoppedServices.Count -eq 0) {
    Write-Host "  No services stopped -- nothing to recover."
} else {
    foreach ($svcName in $stoppedServices) {
        Write-Host "  RECOVERING: $svcName was running before this update and is not running now."
        try {
            Start-Service -Name $svcName -ErrorAction Stop
            $recoveredServices += $svcName
            Write-Host "    Restarted successfully."
        } catch {
            $failedRecoveries += $svcName
            Write-Host "    WARNING: could not restart $svcName : $($_.Exception.Message)"
        }
    }
}

# REAL FIX 2026-08-13: the original version of this block built $result
# via a dense, single-line conditional that WORKED correctly (traced
# through by hand: install_failed + recovery info both preserved
# together) but was genuinely hard to verify by eye for something this
# safety-critical. Rewritten as clear, sequential, independently
# readable steps -- same final outcome, much easier to trust.
#
# $updateOutcome always reflects whether the INSTALL itself succeeded --
# computed once here, kept separate from $result (which may also carry
# recovery/reboot info appended after it).
if ($exitCode -eq 0) {
    $updateOutcome = $result
} else {
    $updateOutcome = "update_failed:exit_$exitCode"
}

$recoveryInfo = ""
if ($recoveredServices.Count -gt 0) {
    $recoveryInfo = "$recoveryInfo`:recovered_" + ($recoveredServices -join ',')
}
if ($failedRecoveries.Count -gt 0) {
    $recoveryInfo = "$recoveryInfo`:RECOVERY_FAILED_" + ($failedRecoveries -join ',')
}

$rebootInfo = ""
$rebootPending = Test-Path "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired"
if ($rebootPending) {
    $rebootInfo = ":reboot_required"
}

$result = "$updateOutcome$recoveryInfo$rebootInfo"

try {
    Invoke-WebRequest -Uri "$u/enroll/policy-result" -Method POST -Body @{ policy_run_id = $runId; hostname = $hostname; result = $result } -UseBasicParsing | Out-Null
} catch {
    Write-Host "WARNING: could not report result: $($_.Exception.Message)"
}
Write-Host "OS update remediate: before=$before exit=$exitCode stopped_services=$($stoppedServices.Count) recovered=$($recoveredServices.Count) failed_recovery=$($failedRecoveries.Count) result=$result"
