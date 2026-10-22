# Real IP Block check -- Windows. Mirrors the exact design already
# proven on Linux (ops/policies/ip-block/check.sh): fetches its own
# assignment's IP list via /enroll/policy-params (trusted by $runId
# alone), builds the same hybrid result -- a summary count for existing
# prefix-matching, plus a real per-IP JSON map (true=already blocked
# before us, false=not) that PolicyRunner auto-freezes into prior_state
# at first remediate, giving genuine per-IP accuracy with zero further
# schema changes.
#
# Rules are identified by a consistent naming convention
# (Obaits-IPBlock-<ip>-In/-Out) so remediate/revert can reliably find
# and manage exactly the rules this policy itself created.
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

if (-not $ips -or $ips.Count -eq 0) {
    Write-Host "IP block check: no IPs configured for this assignment."
    Invoke-WebRequest -Uri "$u/enroll/policy-result" -Method POST -Body @{ policy_run_id = $runId; hostname = $hostname; result = "up_to_date" } -UseBasicParsing | Out-Null
    exit 0
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
Write-Host "IP block check: $unblockedCount of $($ips.Count) IP(s) not yet blocked"
