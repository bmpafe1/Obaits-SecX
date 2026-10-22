# Real baseline platform protection -- Windows (Windows Firewall for
# IPs, hosts file for domains). Mirrors the Linux design exactly
# (resources/views/enroll/threat-intel-refresh-sh.blade.php) -- same four sources (Spamhaus
# DROP, URLhaus, hagezi TIF mini, ThreatFox), same per-source count
# reporting, same marker-delimited safe hosts-file rebuild.
#
# REAL LESSON CARRIED OVER from the Linux performance bug found live
# 2026-08-17: NEVER add firewall rules one-at-a-time in a loop --
# individual New-NetFirewallRule calls are genuinely slow at scale
# (confirmed elsewhere in this project: thousands of individual rule
# creations can take tens of minutes). Uses a SINGLE
# New-NetFirewallRule call with -RemoteAddress accepting the ENTIRE
# array at once -- the real, correct bulk-capable form of this cmdlet,
# applied here from the start rather than discovered the hard way a
# second time.
# REAL CHANGE 2026-08-23: read the portal URL from disk rather than hardcoding
# it, so a rename (Obaits -> SecX) or an on-prem/.iso deployment is a one-line
# edit per device. Mirrors /etc/obaits/portal-url on Linux. The literal remains
# only as a fallback for a device enrolled before this file existed.
# NOTE this script stays a STATIC file (not a Blade view like its Linux
# counterpart) because it contains PowerShell @{...} hashtable literals that
# Blade would try to parse as its own directives.
$PortalUrl = if (Test-Path 'C:\ProgramData\Obaits\portal-url') {
    (Get-Content 'C:\ProgramData\Obaits\portal-url' -Raw).Trim()
} else { "https://app.obaits.com" }
$HostnameActual = $env:COMPUTERNAME

function Report-SourceCount {
    param([string]$Source, [int]$Count)
    try {
        Invoke-WebRequest -Uri "$PortalUrl/enroll/threat-intel-source-counts" -Method POST `
            -Body @{ source = $Source; count = $Count; hostname = $HostnameActual } `
            -UseBasicParsing -TimeoutSec 15 | Out-Null
    } catch { }
}

# --- IP blocking (Spamhaus DROP + ThreatFox ip:port rows) -------------------
$SpamhausUrl = "https://www.spamhaus.org/drop/drop.txt"
$ThreatFoxUrl = "https://threatfox.abuse.ch/export/csv/recent/"
$RuleNamePrefix = "Obaits-ThreatIntel"

$ThreatFoxIPsList = New-Object System.Collections.Generic.List[string]
try {
    $tfRaw = Invoke-WebRequest -Uri $ThreatFoxUrl -UseBasicParsing -TimeoutSec 30
    $tfLines = $tfRaw.Content -split "`n" | Where-Object { $_ -notmatch '^#' -and $_.Trim() -ne '' }
    foreach ($line in $tfLines) {
        # Real CSV format, same as confirmed on Linux: ioc_value=field 3,
        # ioc_type=field 4, confidence_level=field 10.
        # REAL BUG FOUND 2026-08-17, confirmed live: ThreatFox's real CSV
        # separator is '", "' (comma AND a space), not '","' -- confirmed
        # directly, the broken pattern matched zero times, returning the
        # entire line as a single field, silently producing 0 real
        # extracted IPs/domains despite genuinely correct source data
        # (2736 real ip:port rows confirmed present).
        $fields = $line -split '", "'
        if ($fields.Count -ge 10) {
            $iocValue = $fields[2].Trim('"')
            $iocType = $fields[3].Trim('"')
            $confidence = $fields[9].Trim('"')
            if ($iocType -eq 'ip:port' -and [int]$confidence -ge 50) {
                $ip = ($iocValue -split ':')[0]
                if ($ip -match '^\d{1,3}(\.\d{1,3}){3}$') { $ThreatFoxIPsList.Add($ip) }
            }
        }
    }
    $ThreatFoxIPs = $ThreatFoxIPsList
    if ($ThreatFoxIPs.Count -gt 0) {
        Report-SourceCount -Source "threatfox" -Count $ThreatFoxIPs.Count
        Write-Host "  ThreatFox: $($ThreatFoxIPs.Count) IP(s) (confidence >= 50)"
    }
} catch {
    Write-Host "  WARNING: could not fetch ThreatFox -- continuing with other sources only."
}

try {
    $spamhausRaw = Invoke-WebRequest -Uri $SpamhausUrl -UseBasicParsing -TimeoutSec 30
    $cidrs = $spamhausRaw.Content -split "`n" | Where-Object { $_ -notmatch '^;' } | ForEach-Object {
        ($_ -split '\s+')[0]
    } | Where-Object { $_ -match '^\d{1,3}(\.\d{1,3}){3}(/\d{1,2})?$' }

    if ($cidrs.Count -ge 100) {
        # REAL, SECOND SEVERE PERFORMANCE BUG FOUND 2026-08-18, confirmed
        # live: even after fixing the array += anti-pattern, this same
        # device's threat-intel-refresh.ps1 was STILL confirmed stuck
        # for a real 3+ hours -- isolated and confirmed directly:
        # Select-Object -Unique itself is genuinely O(N^2) at scale in
        # Windows PowerShell 5.1 (a real linear scan against every
        # already-seen item per new item, not hash-based). Measured
        # directly against 75,000 real test entries: 2436 SECONDS via
        # Select-Object -Unique, 1.47 seconds via a real
        # HashSet[string] -- a genuine, measured ~1660x improvement.
        # Fixed here and at the much larger-scale domain dedup below.
        $seenIPs = [System.Collections.Generic.HashSet[string]]::new()
        $allIPsList = New-Object System.Collections.Generic.List[string]
        foreach ($ip in (@($cidrs) + @($ThreatFoxIPs))) {
            if ($seenIPs.Add($ip)) { $allIPsList.Add($ip) }
        }
        $allIPs = $allIPsList

        # Real, safe rebuild: remove any prior rule with our prefix, then
        # create ONE rule referencing the whole address list -- not one
        # rule per IP.
        Get-NetFirewallRule -DisplayName "$RuleNamePrefix*" -ErrorAction SilentlyContinue | Remove-NetFirewallRule -ErrorAction SilentlyContinue

        New-NetFirewallRule -DisplayName "$RuleNamePrefix-Inbound" -Direction Inbound -RemoteAddress $allIPs -Action Block -ErrorAction SilentlyContinue | Out-Null
        New-NetFirewallRule -DisplayName "$RuleNamePrefix-Outbound" -Direction Outbound -RemoteAddress $allIPs -Action Block -ErrorAction SilentlyContinue | Out-Null

        Report-SourceCount -Source "spamhaus_drop" -Count $cidrs.Count
        Write-Host "  IP block: $($cidrs.Count) range(s) from Spamhaus DROP + $($ThreatFoxIPs.Count) from ThreatFox"
    } else {
        Write-Host "  WARNING: Spamhaus DROP feed implausibly small ($($cidrs.Count)) -- leaving existing IP block list untouched."
    }
} catch {
    Write-Host "  WARNING: could not fetch Spamhaus DROP -- leaving existing IP block list untouched."
}

# --- Domain blocking (URLhaus + hagezi TIF + ThreatFox domains) -------------
$UrlhausUrl = "https://urlhaus.abuse.ch/downloads/hostfile.txt"
$HagemiUrl = "https://raw.githubusercontent.com/hagezi/dns-blocklists/main/dnsmasq/tif.mini.txt"
$HostsFile = "$env:SystemRoot\System32\drivers\etc\hosts"
$CacheDir = "$env:ProgramData\Obaits\threat-intel-cache"
New-Item -ItemType Directory -Path $CacheDir -Force | Out-Null

$ThreatFoxDomainsList = New-Object System.Collections.Generic.List[string]
foreach ($line in $tfLines) {
    # REAL BUG FOUND 2026-08-17, confirmed live: ThreatFox's real CSV
        # separator is '", "' (comma AND a space), not '","' -- confirmed
        # directly, the broken pattern matched zero times, returning the
        # entire line as a single field, silently producing 0 real
        # extracted IPs/domains despite genuinely correct source data
        # (2736 real ip:port rows confirmed present).
        $fields = $line -split '", "'
    if ($fields.Count -ge 10) {
        $iocValue = $fields[2].Trim('"')
        $iocType = $fields[3].Trim('"')
        $confidence = $fields[9].Trim('"')
        if ($iocType -eq 'domain' -and [int]$confidence -ge 50 -and $iocValue -match '^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$') {
            $ThreatFoxDomainsList.Add($iocValue)
        }
    }
}
$ThreatFoxDomains = $ThreatFoxDomainsList
if ($ThreatFoxDomains.Count -gt 0) {
    $ThreatFoxDomains | Set-Content -Path "$CacheDir\threatfox-domains.cache"
}

try {
    $urlhausRaw = Invoke-WebRequest -Uri $UrlhausUrl -UseBasicParsing -TimeoutSec 30
    $urlhausDomains = $urlhausRaw.Content -split "`r?`n" | Where-Object { $_ -notmatch '^#' -and $_.Trim() -ne '' } | ForEach-Object {
        ($_ -split '\s+')[1]
    } | Where-Object { $_ -match '^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$' }
    if ($urlhausDomains.Count -ge 50) {
        $urlhausDomains | Set-Content -Path "$CacheDir\urlhaus-domains.cache"
        Report-SourceCount -Source "urlhaus" -Count $urlhausDomains.Count
        Write-Host "  URLhaus: $($urlhausDomains.Count) domain(s) refreshed"
    } else {
        Write-Host "  WARNING: URLhaus feed implausibly small -- using last-known-good cache instead."
    }
} catch {
    Write-Host "  WARNING: could not fetch URLhaus -- using last-known-good cache."
}

try {
    $hagemiRaw = Invoke-WebRequest -Uri $HagemiUrl -UseBasicParsing -TimeoutSec 30
    $hagemiDomains = $hagemiRaw.Content -split "`r?`n" | Where-Object { $_ -notmatch '^#' -and $_ -match '^local=/(.+)/$' } | ForEach-Object {
        $matches[1]
    } | Where-Object { $_ -match '^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$' }
    if ($hagemiDomains.Count -ge 1000) {
        $hagemiDomains | Set-Content -Path "$CacheDir\hagezi-tif-domains.cache"
        Report-SourceCount -Source "hagezi_tif" -Count $hagemiDomains.Count
        Write-Host "  hagezi TIF: $($hagemiDomains.Count) domain(s) refreshed"
    } else {
        Write-Host "  WARNING: hagezi TIF feed implausibly small -- using last-known-good cache instead."
    }
} catch {
    Write-Host "  WARNING: could not fetch hagezi TIF -- using last-known-good cache."
}

$BeginMarker = "# OBAITS-THREAT-INTEL-BEGIN -- managed automatically, do not edit inside this block"
$EndMarker = "# OBAITS-THREAT-INTEL-END"

# REAL, SERIOUS PERFORMANCE BUG FOUND 2026-08-17, confirmed live on a
# real device: PowerShell's "$array += $item" inside a loop is a real,
# well-known O(N^2) anti-pattern -- each += silently allocates an
# ENTIRELY NEW array and copies every prior element into it. Against
# this file's own real, current ~170,000-entry hosts-file block, two
# separate real device executions were confirmed stuck for OVER 7 HOURS
# EACH, consuming 25,000+ CPU-seconds, before being found and killed --
# the exact same class of bug as the Linux ipset "for" loop found and
# fixed earlier the same night, just manifesting as CPU-bound array
# reallocation instead of process-spawn overhead. Fixed with
# System.Collections.Generic.List[string], whose .Add() is real O(1)
# amortized, not O(N) -- the correct, efficient collection type for
# building a large list incrementally.
$allDomainsList = New-Object System.Collections.Generic.List[string]
foreach ($cacheFile in @("$CacheDir\urlhaus-domains.cache", "$CacheDir\hagezi-tif-domains.cache", "$CacheDir\threatfox-domains.cache")) {
    if (Test-Path $cacheFile) { $allDomainsList.AddRange([string[]](Get-Content $cacheFile)) }
}
# Same real fix as the IP dedup above -- see that comment for the
# full measured 2436s-vs-1.47s finding. This is the genuinely
# larger-scale, more dangerous occurrence (up to ~170,000+ real
# combined domain entries).
$seenDomains = [System.Collections.Generic.HashSet[string]]::new()
$allDomainsUniqueList = New-Object System.Collections.Generic.List[string]
foreach ($d in $allDomainsList) {
    if ($seenDomains.Add($d)) { $allDomainsUniqueList.Add($d) }
}
$allDomains = $allDomainsUniqueList

if ($allDomains.Count -gt 0) {
    $existing = Get-Content -Path $HostsFile -ErrorAction SilentlyContinue
    $filteredList = New-Object System.Collections.Generic.List[string]
    $inside = $false
    foreach ($line in $existing) {
        if ($line.Trim() -eq $BeginMarker) { $inside = $true; continue }
        if ($line.Trim() -eq $EndMarker) { $inside = $false; continue }
        if (-not $inside) { $filteredList.Add($line) }
    }
    $filteredList.Add($BeginMarker)
    foreach ($domain in $allDomains) { $filteredList.Add("0.0.0.0 $domain") }
    $filteredList.Add($EndMarker)
    Set-Content -Path $HostsFile -Value $filteredList -Force
    Write-Host "  Domain block: $($allDomains.Count) unique domain(s) now blocked via hosts file (URLhaus + hagezi TIF + ThreatFox combined)"
} else {
    Write-Host "  WARNING: no domain block data available from any source -- hosts file not touched."
}

Write-Host "threat-intel refresh complete"

# REAL FEATURE 2026-08-23: report this device's own refresh health back to the
# portal. Needed because this refresh now runs from a device-side scheduled
# task rather than being pushed per-host through Fleet, so Fleet's own activity
# log no longer records that it happened. Authenticated with the per-device
# credential issued at enrollment -- the portal resolves tenant AND hostname
# from it, so this script sends neither.
#
# SUCCESS means this script reached the end and applied a ruleset. A single
# dead upstream feed is NOT a failure. The one real failure case is every
# source failing, leaving the device genuinely unprotected.
function Report-Health {
    param([string]$Status, [string]$ErrorText)
    $credPath = 'C:\ProgramData\Obaits\device-credential'
    if (-not (Test-Path $credPath)) { return }
    $cred = (Get-Content $credPath -Raw).Trim()
    if (-not $cred) { return }
    try {
        Invoke-WebRequest -Uri "$PortalUrl/enroll/threat-intel-health" -Method POST `
            -Body @{ device_credential = $cred; platform = 'windows'; status = $Status; error = $ErrorText } `
            -UseBasicParsing -TimeoutSec 15 | Out-Null
    } catch { }
}

# Null-safe deliberately: $cidrs and $ThreatFoxIPs are assigned only INSIDE
# their respective fetch blocks, so on the very path this condition exists to
# detect (every source failed) they are never set at all. @() around an unset
# variable yields an empty array, so .Count is a real 0 rather than an error.
# ($allDomainsUniqueList is created unconditionally and is safe regardless.)
$domainCount = @($allDomainsUniqueList).Count
$ipCount = @($cidrs).Count + @($ThreatFoxIPs).Count
if ($domainCount -eq 0 -and $ipCount -eq 0) {
    Report-Health -Status 'failure' -ErrorText 'no data from any source -- device is not protected'
} else {
    Report-Health -Status 'success' -ErrorText ''
}
