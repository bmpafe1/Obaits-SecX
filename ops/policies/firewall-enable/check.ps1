$hostname = $env:COMPUTERNAME
$fw = (Get-NetFirewallProfile | Select-Object -First 1 -ExpandProperty Enabled)
$result = if ($fw) { 'firewall_enabled' } else { 'firewall_disabled' }
try {
    Invoke-WebRequest -Uri "$u/enroll/policy-result" -Method POST -Body @{ policy_run_id = $runId; hostname = $hostname; result = $result } -UseBasicParsing | Out-Null
} catch {
    Write-Host "WARNING: could not report result: $($_.Exception.Message)"
}
Write-Host "Firewall check: $result"
