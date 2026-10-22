# Real Port Block revert -- Windows. Removes rules ONLY for ports where
# PRIOR_STATE genuinely recorded false (WE added them). Both directions.
function Get-SafeRuleName {
    param([string]$portProto)
    return ($portProto -replace '[/]', '_')
}

try {
    $paramsJson = (Invoke-WebRequest -Uri "$u/enroll/policy-params?run_id=$runId" -UseBasicParsing).Content
} catch {
    Write-Host "ERROR: could not fetch policy parameters: $($_.Exception.Message)"
    exit 1
}

$ports = ($paramsJson | ConvertFrom-Json)
$hostname = $env:COMPUTERNAME

$priorParts = $priorState -split '\|', 2
$priorJson = if ($priorParts.Count -gt 1) { $priorParts[1] | ConvertFrom-Json } else { $null }

$reverted = 0
foreach ($portProto in $ports) {
    $wasBlocked = $true
    if ($priorJson -and ($priorJson.PSObject.Properties.Name -contains $portProto)) {
        $wasBlocked = [bool]($priorJson.$portProto)
    }
    if (-not $wasBlocked) {
        $ruleName = "Obaits-PortBlock-$(Get-SafeRuleName $portProto)"
        try {
            Remove-NetFirewallRule -DisplayName "$ruleName-In" -ErrorAction SilentlyContinue
            Remove-NetFirewallRule -DisplayName "$ruleName-Out" -ErrorAction SilentlyContinue
            $reverted++
        } catch {
            Write-Host "WARNING: could not remove rule for $portProto : $($_.Exception.Message)"
        }
    }
}

$unblockedCount = 0
$jsonParts = @()
foreach ($portProto in $ports) {
    $ruleName = "Obaits-PortBlock-$(Get-SafeRuleName $portProto)-In"
    $existing = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
    if ($existing) {
        $jsonParts += "`"$portProto`":true"
    } else {
        $jsonParts += "`"$portProto`":false"
        $unblockedCount++
    }
}
$jsonBlob = "{" + ($jsonParts -join ",") + "}"
$state = "ports_unblocked:$unblockedCount|$jsonBlob"

try {
    Invoke-WebRequest -Uri "$u/enroll/policy-result" -Method POST -Body @{ policy_run_id = $runId; hostname = $hostname; result = $state } -UseBasicParsing | Out-Null
} catch {
    Write-Host "WARNING: could not report result: $($_.Exception.Message)"
}
Write-Host "Port block revert: removed $reverted rule(s) we added, left any pre-existing blocks untouched"
