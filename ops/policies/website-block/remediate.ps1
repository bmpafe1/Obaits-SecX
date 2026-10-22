# Real Website Block remediate -- Windows. Reads PRIOR_STATE (frozen
# per-domain JSON from this device's own first check) -- only adds a
# domain if prior_state says it was NOT already blocked (false). Writes
# into a marker block scoped to THIS assignment's own $runId -- stable
# for the lifetime of this (assignment, device) pair, keeping this
# policy's own additions cleanly separable from any other mechanism
# touching the hosts file.
$HOSTS_FILE = "$env:SystemRoot\System32\drivers\etc\hosts"
$BEGIN_MARKER = "# OBAITS-WEBSITE-BLOCK-BEGIN-$runId"
$END_MARKER = "# OBAITS-WEBSITE-BLOCK-END-$runId"

try {
    $paramsJson = (Invoke-WebRequest -Uri "$u/enroll/policy-params?run_id=$runId" -UseBasicParsing).Content
} catch {
    Write-Host "ERROR: could not fetch policy parameters: $($_.Exception.Message)"
    exit 1
}

$domains = ($paramsJson | ConvertFrom-Json)
$hostname = $env:COMPUTERNAME

$priorParts = $priorState -split '\|', 2
$priorJson = if ($priorParts.Count -gt 1) { $priorParts[1] | ConvertFrom-Json } else { $null }

$toAdd = @()
foreach ($domain in $domains) {
    $wasBlocked = $false
    if ($priorJson -and ($priorJson.PSObject.Properties.Name -contains $domain)) {
        $wasBlocked = [bool]($priorJson.$domain)
    }
    if (-not $wasBlocked) {
        $toAdd += $domain
    }
}

# Real, safe rebuild: strip any PRIOR block for THIS run id only (never
# touches anything else in the hosts file), then append one fresh block
# built from exactly the domains we own.
$existingContent = Get-Content -Path $HOSTS_FILE -ErrorAction SilentlyContinue
$filtered = @()
$insideBlock = $false
foreach ($line in $existingContent) {
    if ($line.Trim() -eq $BEGIN_MARKER) { $insideBlock = $true; continue }
    if ($line.Trim() -eq $END_MARKER) { $insideBlock = $false; continue }
    if (-not $insideBlock) { $filtered += $line }
}

if ($toAdd.Count -gt 0) {
    $filtered += $BEGIN_MARKER
    foreach ($domain in $toAdd) { $filtered += "0.0.0.0 $domain" }
    $filtered += $END_MARKER
}

Set-Content -Path $HOSTS_FILE -Value $filtered -Force

$addedCount = $toAdd.Count

# Real, current state AFTER remediation -- built fresh, same as check.ps1.
$hostsContent = Get-Content -Path $HOSTS_FILE -ErrorAction SilentlyContinue
$unblockedCount = 0
$jsonParts = @()
foreach ($domain in $domains) {
    $pattern = "^\s*[0-9.]+\s+" + [regex]::Escape($domain) + "\s*$"
    $alreadyBlocked = [bool]($hostsContent | Select-String -Pattern $pattern -Quiet)
    if ($alreadyBlocked) {
        $jsonParts += "`"$domain`":true"
    } else {
        $jsonParts += "`"$domain`":false"
        $unblockedCount++
    }
}
$jsonBlob = "{" + ($jsonParts -join ",") + "}"

if ($unblockedCount -gt 0) {
    $state = "domains_unblocked:$unblockedCount|$jsonBlob"
} else {
    $state = "up_to_date|$jsonBlob"
}

try {
    Invoke-WebRequest -Uri "$u/enroll/policy-result" -Method POST -Body @{ policy_run_id = $runId; hostname = $hostname; result = $state } -UseBasicParsing | Out-Null
} catch {
    Write-Host "WARNING: could not report result: $($_.Exception.Message)"
}
Write-Host "Website block remediate: added $addedCount new domain(s), $unblockedCount still unblocked, result=$state"
