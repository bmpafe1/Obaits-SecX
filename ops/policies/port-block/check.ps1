# Real Port Block check -- Windows. Mirrors Port Allow's exact design,
# but blocks BOTH directions (matching IP Block's dual-direction
# default, and Linux Port Block's own design).
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

if (-not $ports -or $ports.Count -eq 0) {
    Write-Host "Port block check: no ports configured for this assignment."
    Invoke-WebRequest -Uri "$u/enroll/policy-result" -Method POST -Body @{ policy_run_id = $runId; hostname = $hostname; result = "up_to_date" } -UseBasicParsing | Out-Null
    exit 0
}

$unblockedCount = 0
$jsonParts = @()
foreach ($portProto in $ports) {
    $ruleName = "Obaits-PortBlock-$(Get-SafeRuleName $portProto)-In"
    $existing = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
    if ($existing) {
        $alreadyBlocked = "true"
    } else {
        $alreadyBlocked = "false"
        $unblockedCount++
    }
    $jsonParts += "`"$portProto`":$alreadyBlocked"
}
$jsonBlob = "{" + ($jsonParts -join ",") + "}"

if ($unblockedCount -gt 0) {
    $state = "ports_unblocked:$unblockedCount|$jsonBlob"
} else {
    $state = "up_to_date|$jsonBlob"
}

try {
    Invoke-WebRequest -Uri "$u/enroll/policy-result" -Method POST -Body @{ policy_run_id = $runId; hostname = $hostname; result = $state } -UseBasicParsing | Out-Null
} catch {
    Write-Host "WARNING: could not report result: $($_.Exception.Message)"
}
Write-Host "Port block check: $unblockedCount of $($ports.Count) port(s) not yet blocked"
