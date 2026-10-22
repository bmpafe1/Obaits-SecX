# Real IP Block remediate -- Windows. Reads PRIOR_STATE (the frozen
# per-IP JSON from this device's own first check, auto-captured by
# PolicyRunner) -- only blocks an IP if prior_state says it was NOT
# already blocked (false). An IP already blocked by something unrelated
# to this policy is left completely untouched, both here and on revert.
function Get-SafeRuleName {
    param([string]$ip)
    return ($ip -replace '[./]', '_')
}

try {
    $paramsJson = (Invoke-WebRequest -Uri "$u/enroll/policy-params?run_id=$runId" -UseBasicParsing).Content
} catch {
    Write-Host "ERROR: could not fetch policy parameters: $($_.Exception.Message)"
    exit 1
}

$ips = ($paramsJson | ConvertFrom-Json)
$hostname = $env:COMPUTERNAME

# PRIOR_STATE looks like "ips_unblocked:2|{...}" or "up_to_date|{...}" --
# the real per-IP JSON is everything after the first "|".
$priorParts = $priorState -split '\|', 2
$priorJson = if ($priorParts.Count -gt 1) { $priorParts[1] | ConvertFrom-Json } else { $null }

$blockedNow = 0
foreach ($ip in $ips) {
    $wasBlocked = $false
    if ($priorJson -and ($priorJson.PSObject.Properties.Name -contains $ip)) {
        $wasBlocked = [bool]($priorJson.$ip)
    }
    if (-not $wasBlocked) {
        $ruleName = Get-SafeRuleName $ip
        try {
            if (-not (Get-NetFirewallRule -DisplayName "Obaits-IPBlock-$ruleName-In" -ErrorAction SilentlyContinue)) {
                New-NetFirewallRule -DisplayName "Obaits-IPBlock-$ruleName-In" -Direction Inbound -RemoteAddress $ip -Action Block -ErrorAction Stop | Out-Null
            }
            if (-not (Get-NetFirewallRule -DisplayName "Obaits-IPBlock-$ruleName-Out" -ErrorAction SilentlyContinue)) {
                New-NetFirewallRule -DisplayName "Obaits-IPBlock-$ruleName-Out" -Direction Outbound -RemoteAddress $ip -Action Block -ErrorAction Stop | Out-Null
            }
            $blockedNow++
        } catch {
            Write-Host "WARNING: could not block $ip : $($_.Exception.Message)"
        }
    }
}

# Real, current state AFTER remediation -- built fresh, same as check.ps1.
$unblockedCount = 0
$jsonParts = @()
foreach ($ip in $ips) {
    $ruleName = "Obaits-IPBlock-$(Get-SafeRuleName $ip)-In"
    $existing = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
    if ($existing) {
        $alreadyBlocked = "true"
    } else {
        $alreadyBlocked = "false"
        $unblockedCount++
    }
    $jsonParts += "`"$ip`":$alreadyBlocked"
}
$jsonBlob = "{" + ($jsonParts -join ",") + "}"

if ($unblockedCount -gt 0) {
    $state = "ips_unblocked:$unblockedCount|$jsonBlob"
} else {
    $state = "up_to_date|$jsonBlob"
}

try {
    Invoke-WebRequest -Uri "$u/enroll/policy-result" -Method POST -Body @{ policy_run_id = $runId; hostname = $hostname; result = $state } -UseBasicParsing | Out-Null
} catch {
    Write-Host "WARNING: could not report result: $($_.Exception.Message)"
}
Write-Host "IP block remediate: blocked $blockedNow new IP(s), $unblockedCount still unblocked, result=$state"
