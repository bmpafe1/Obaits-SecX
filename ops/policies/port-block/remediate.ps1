# Real Port Block remediate -- Windows. Blocks BOTH directions for each
# port marked false in PRIOR_STATE (WE own it).
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

$blockedNow = 0
foreach ($portProto in $ports) {
    $wasBlocked = $false
    if ($priorJson -and ($priorJson.PSObject.Properties.Name -contains $portProto)) {
        $wasBlocked = [bool]($priorJson.$portProto)
    }
    if (-not $wasBlocked) {
        $parts = $portProto -split '/'
        $portNum = $parts[0]
        $proto = $parts[1].ToUpper()
        $ruleName = "Obaits-PortBlock-$(Get-SafeRuleName $portProto)"
        try {
            if (-not (Get-NetFirewallRule -DisplayName "$ruleName-In" -ErrorAction SilentlyContinue)) {
                New-NetFirewallRule -DisplayName "$ruleName-In" -Direction Inbound -LocalPort $portNum -Protocol $proto -Action Block -ErrorAction Stop | Out-Null
            }
            if (-not (Get-NetFirewallRule -DisplayName "$ruleName-Out" -ErrorAction SilentlyContinue)) {
                New-NetFirewallRule -DisplayName "$ruleName-Out" -Direction Outbound -LocalPort $portNum -Protocol $proto -Action Block -ErrorAction Stop | Out-Null
            }
            $blockedNow++
        } catch {
            Write-Host "WARNING: could not block $portProto : $($_.Exception.Message)"
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
Write-Host "Port block remediate: blocked $blockedNow new port(s), $unblockedCount still unblocked, result=$state"
