# Real Port Allow remediate -- Windows. Reads PRIOR_STATE, only allows a
# port if it was NOT already allowed (false). Parses "port/protocol"
# strings (e.g. "443/tcp") into New-NetFirewallRule's real -LocalPort/
# -Protocol parameters.
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

$allowedNow = 0
foreach ($portProto in $ports) {
    $wasAllowed = $false
    if ($priorJson -and ($priorJson.PSObject.Properties.Name -contains $portProto)) {
        $wasAllowed = [bool]($priorJson.$portProto)
    }
    if (-not $wasAllowed) {
        $parts = $portProto -split '/'
        $portNum = $parts[0]
        $proto = $parts[1].ToUpper()
        $ruleName = "Obaits-PortAllow-$(Get-SafeRuleName $portProto)"
        try {
            if (-not (Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue)) {
                New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -LocalPort $portNum -Protocol $proto -Action Allow -ErrorAction Stop | Out-Null
            }
            $allowedNow++
        } catch {
            Write-Host "WARNING: could not allow $portProto : $($_.Exception.Message)"
        }
    }
}

$unblockedCount = 0
$jsonParts = @()
foreach ($portProto in $ports) {
    $ruleName = "Obaits-PortAllow-$(Get-SafeRuleName $portProto)"
    $existing = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
    if ($existing) {
        $jsonParts += "`"$portProto`":true"
    } else {
        $jsonParts += "`"$portProto`":false"
        $unblockedCount++
    }
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
Write-Host "Port allow remediate: allowed $allowedNow new port(s), $unblockedCount still unallowed, result=$state"
