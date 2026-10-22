$hostname = $env:COMPUTERNAME
$before = if ((Get-NetFirewallProfile | Select-Object -First 1 -ExpandProperty Enabled)) { 'firewall_enabled' } else { 'firewall_disabled' }
if ($before -eq 'firewall_disabled') {
    Set-NetFirewallProfile -All -Enabled True
}
$after = if ((Get-NetFirewallProfile | Select-Object -First 1 -ExpandProperty Enabled)) { 'firewall_enabled' } else { 'firewall_disabled' }
try {
    Invoke-WebRequest -Uri "$u/enroll/policy-result" -Method POST -Body @{ policy_run_id = $runId; hostname = $hostname; result = $after; prior_state = $before } -UseBasicParsing | Out-Null
} catch {
    Write-Host "WARNING: could not report result: $($_.Exception.Message)"
}
Write-Host "Firewall remediate: before=$before after=$after"
