# Real Port Allow check -- Windows. Mirrors Linux's exact design (real
# per-port prior-state tracking). "Allow" means inbound only.
#
# REAL LESSON CARRIED OVER from Port Allow/Block's own Linux bug found
# live: Laravel's json_encode() escapes forward slashes as \/ by
# default -- ConvertFrom-Json handles this correctly natively (PowerShell
# JSON parsing understands \/  as an escaped /), so no special handling
# is needed here the way the Linux bash regex needed fixing. Confirmed
# this is a bash-regex-specific problem, not a general JSON problem.
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
    Write-Host "Port allow check: no ports configured for this assignment."
    Invoke-WebRequest -Uri "$u/enroll/policy-result" -Method POST -Body @{ policy_run_id = $runId; hostname = $hostname; result = "up_to_date" } -UseBasicParsing | Out-Null
    exit 0
}

$unblockedCount = 0
$jsonParts = @()
foreach ($portProto in $ports) {
    $ruleName = "Obaits-PortAllow-$(Get-SafeRuleName $portProto)"
    $existing = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
    if ($existing) {
        $alreadyAllowed = "true"
    } else {
        $alreadyAllowed = "false"
        $unblockedCount++
    }
    $jsonParts += "`"$portProto`":$alreadyAllowed"
}
$jsonBlob = "{" + ($jsonParts -join ",") + "}"

if ($unblockedCount -gt 0) {
    $state = "ports_unallowed:$unblockedCount|$jsonBlob"
} else {
    $state = "up_to_date|$jsonBlob"
}

try {
    Invoke-WebRequest -Uri "$u/enroll/policy-result" -Method POST -Body @{ policy_run_id = $runId; hostname = $hostname; result = $state } -UseBasicParsing | Out-Null
} catch {
    Write-Host "WARNING: could not report result: $($_.Exception.Message)"
}
Write-Host "Port allow check: $unblockedCount of $($ports.Count) port(s) not yet allowed"
