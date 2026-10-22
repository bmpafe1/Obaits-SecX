# Real IP Block revert -- Windows. Removes firewall rules ONLY for IPs
# where PRIOR_STATE genuinely recorded false (WE added the block). Any
# IP recorded true (already blocked before us) or with no record at all
# is left untouched -- conservative by design, never removes a rule we
# have no proof we added ourselves.
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

$priorParts = $priorState -split '\|', 2
$priorJson = if ($priorParts.Count -gt 1) { $priorParts[1] | ConvertFrom-Json } else { $null }

$reverted = 0
foreach ($ip in $ips) {
    $wasBlocked = $true  # default conservative: if unknown, leave alone
    if ($priorJson -and ($priorJson.PSObject.Properties.Name -contains $ip)) {
        $wasBlocked = [bool]($priorJson.$ip)
    }
    if (-not $wasBlocked) {
        $ruleName = Get-SafeRuleName $ip
        try {
            Remove-NetFirewallRule -DisplayName "Obaits-IPBlock-$ruleName-In" -ErrorAction SilentlyContinue
            Remove-NetFirewallRule -DisplayName "Obaits-IPBlock-$ruleName-Out" -ErrorAction SilentlyContinue
            $reverted++
        } catch {
            Write-Host "WARNING: could not remove rule for $ip : $($_.Exception.Message)"
        }
    }
    # $wasBlocked = $true or unknown -> deliberately left alone
}

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
$state = "ips_unblocked:$unblockedCount|$jsonBlob"

try {
    Invoke-WebRequest -Uri "$u/enroll/policy-result" -Method POST -Body @{ policy_run_id = $runId; hostname = $hostname; result = $state } -UseBasicParsing | Out-Null
} catch {
    Write-Host "WARNING: could not report result: $($_.Exception.Message)"
}
Write-Host "IP block revert: removed $reverted rule(s) we added, left any pre-existing/unknown blocks untouched"
