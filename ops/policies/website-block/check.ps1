# Real Website Block check -- Windows. Mirrors the Linux design exactly
# (ops/policies/website-block/check.sh): fetches its own assignment's
# domain list via /enroll/policy-params, builds the same hybrid result --
# a summary count plus a real per-domain JSON map (true=already blocked
# before us, false=not) that PolicyRunner auto-freezes into prior_state
# at first remediate. "Already blocked" checks the WHOLE hosts file, not
# just this policy's own marker block -- correctly treats a domain
# already blocked by anything else (including baseline protection, if a
# Windows equivalent existed) as true, leave alone.
$HOSTS_FILE = "$env:SystemRoot\System32\drivers\etc\hosts"

try {
    $paramsJson = (Invoke-WebRequest -Uri "$u/enroll/policy-params?run_id=$runId" -UseBasicParsing).Content
} catch {
    Write-Host "ERROR: could not fetch policy parameters: $($_.Exception.Message)"
    exit 1
}

$domains = ($paramsJson | ConvertFrom-Json)
$hostname = $env:COMPUTERNAME

if (-not $domains -or $domains.Count -eq 0) {
    Write-Host "Website block check: no domains configured for this assignment."
    Invoke-WebRequest -Uri "$u/enroll/policy-result" -Method POST -Body @{ policy_run_id = $runId; hostname = $hostname; result = "up_to_date" } -UseBasicParsing | Out-Null
    exit 0
}

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
Write-Host "Website block check: $unblockedCount of $($domains.Count) domain(s) not yet blocked"
