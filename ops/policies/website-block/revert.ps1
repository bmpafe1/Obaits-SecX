# Real Website Block revert -- Windows. Removes THIS assignment's own
# marker block entirely -- safe by construction, since remediate.ps1
# only ever writes domains marked false (WE added them) into it.
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

$existingContent = Get-Content -Path $HOSTS_FILE -ErrorAction SilentlyContinue
$filtered = @()
$insideBlock = $false
$removedCount = 0
foreach ($line in $existingContent) {
    if ($line.Trim() -eq $BEGIN_MARKER) { $insideBlock = $true; continue }
    if ($line.Trim() -eq $END_MARKER) { $insideBlock = $false; continue }
    if ($insideBlock) { $removedCount++; continue }
    $filtered += $line
}

Set-Content -Path $HOSTS_FILE -Value $filtered -Force

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
$state = "domains_unblocked:$unblockedCount|$jsonBlob"

try {
    Invoke-WebRequest -Uri "$u/enroll/policy-result" -Method POST -Body @{ policy_run_id = $runId; hostname = $hostname; result = $state } -UseBasicParsing | Out-Null
} catch {
    Write-Host "WARNING: could not report result: $($_.Exception.Message)"
}
Write-Host "Website block revert: removed $removedCount domain(s) we added, left any pre-existing/baseline-protected domains untouched"
