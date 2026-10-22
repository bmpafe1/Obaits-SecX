$hostname = $env:COMPUTERNAME
if ($priorState -eq 'firewall_disabled') {
    Set-NetFirewallProfile -All -Enabled False
}
$after = if ((Get-NetFirewallProfile | Select-Object -First 1 -ExpandProperty Enabled)) { 'firewall_enabled' } else { 'firewall_disabled' }
try {
    Invoke-WebRequest -Uri "$u/enroll/policy-result" -Method POST -Body @{ policy_run_id = $runId; hostname = $hostname; result = $after } -UseBasicParsing | Out-Null
} catch {
    Write-Host "WARNING: could not report result: $($_.Exception.Message)"
}
Write-Host "Firewall revert: prior_state=$priorState after=$after"
