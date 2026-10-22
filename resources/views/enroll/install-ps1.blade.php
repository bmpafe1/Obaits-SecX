#Requires -RunAsAdministrator
$ErrorActionPreference = "Stop"

foreach ($v in @("OBAITS_PORTAL_URL","OBAITS_ENROLL_SECRET","OBAITS_WAZUH_MANAGER",
                 "OBAITS_WAZUH_GROUP","OBAITS_TENANT_ID","OBAITS_WAZUH_VERSION",
                 "OBAITS_WAZUH_ENROLL_PASSWORD","OBAITS_ZABBIX_SERVER","OBAITS_ZABBIX_SECRET")) {
    if (-not (Get-Item "env:$v" -ErrorAction SilentlyContinue)) { throw "$v is not set" }
}

# REAL, SERIOUS CONCERN RAISED by Brian: every real dependency below
# (restic, Tailscale, Wazuh, Zabbix) was previously fetched live from an
# external URL at enrollment time -- a temporary outage, an upstream URL
# change, or eventual end-of-life could all break a real enrollment. Real
# fix: try our own, self-hosted mirror (kept current by a real, proactive
# daily scheduled command) FIRST; fall back to the real, original
# external source only if our own mirror genuinely fails -- self-hosting
# must never become a NEW single point of failure of its own.
function Get-ObaitsPackage {
    param([string]$Tool, [string]$OutFile, [string]$FallbackUrl, [string]$WazuhVersion = $null)

    $mirrorUrl = "$($env:OBAITS_PORTAL_URL)/enroll/mirror/$Tool"
    if ($WazuhVersion) { $mirrorUrl += "?version=$WazuhVersion" }

    try {
        Invoke-WebRequest -Uri $mirrorUrl -Headers @{ "X-Obaits-Enroll-Secret" = $env:OBAITS_ENROLL_SECRET } -OutFile $OutFile -UseBasicParsing
        Write-Host "    (served from Obaits' own mirror)"
        return $true
    } catch {
        Write-Host "    (Obaits mirror unavailable, falling back to $FallbackUrl)"
        try {
            Invoke-WebRequest -Uri $FallbackUrl -OutFile $OutFile -UseBasicParsing
            return $true
        } catch {
            Write-Host "    WARNING: both the Obaits mirror and the original external source failed for $Tool."
            return $false
        }
    }
}

Write-Host "==> Obaits: enrolling this computer"

# 1. Tenant marker FIRST — the only thing attributing this machine to your organisation
$markerDir = "C:\ProgramData\Obaits"
New-Item -ItemType Directory -Force -Path $markerDir | Out-Null
New-Item -ItemType File -Force -Path "$markerDir\tenant-$($env:OBAITS_TENANT_ID)" | Out-Null
Write-Host "  - tenant marker written"

$tmp = Join-Path $env:TEMP ("obaits-" + [guid]::NewGuid())
New-Item -ItemType Directory -Force -Path $tmp | Out-Null
try {
    # 2. Fleet agent — pre-built for this organisation
    Write-Host "  - downloading Fleet agent"
    # The package builds on first request (queued), returning 503 while it's
    # being prepared — poll rather than fail on the first try, mirroring the
    # bash script's retry loop. Added 2026-07-23 on the first real Windows test.
    #
    # REAL BUG FOUND, same class as Wazuh's own fix above: WinClient's
    # Fleet agent was originally installed WITHOUT ENABLE_SCRIPTS=true
    # (that property didn't exist in this script until the first Windows
    # role genuinely needing Fleet Scripts, MariaDB, surfaced the gap).
    # Per the same WiX-repair-skips-first-time-setup lesson already
    # proven for Wazuh, simply adding the property to this script would
    # NOT retroactively enable scripts on an already-installed Fleet
    # agent via a repair — uninstall first, unconditionally, to force a
    # genuine fresh install that picks up the new property.
    $existingFleet = Get-WmiObject -Class Win32_Product -Filter "Name='Fleet osquery'" -ErrorAction SilentlyContinue
    if ($existingFleet) {
        Write-Host "  - removing existing Fleet agent (forces fresh install with scripts enabled)"
        Start-Process msiexec.exe -ArgumentList "/x", $existingFleet.IdentifyingNumber, "/quiet", "/norestart" -Wait
    }

    $pkgUrl = "$($env:OBAITS_PORTAL_URL)/enroll/package?type=msi"
    $pkgOut = "$tmp\fleet-agent.msi"
    $downloaded = $false
    for ($attempt = 1; $attempt -le 20; $attempt++) {
        try {
            Invoke-WebRequest -Uri $pkgUrl `
                -Headers @{ "X-Obaits-Enroll-Secret" = $env:OBAITS_ENROLL_SECRET } `
                -OutFile $pkgOut
            $downloaded = $true
            break
        } catch {
            $statusCode = $_.Exception.Response.StatusCode.value__
            if ($statusCode -eq 503) {
                if ($attempt -eq 1) { Write-Host "    (preparing your agent package, please wait...)" }
                Start-Sleep -Seconds 6
            } else {
                throw
            }
        }
    }
    if (-not $downloaded) { throw "The agent package was not ready after waiting. Try again shortly." }
    # REAL GAP FOUND: WinClient's Fleet agent was never enrolled with
    # scripts enabled at all — genuinely never needed until the first
    # Windows role (MariaDB) actually required running a Fleet Script.
    # Confirmed real Fleet mechanism for Windows: ENABLE_SCRIPTS=true as
    # an MSI property (different from Linux's --enable-scripts CLI
    # flag — Windows has no equivalent command-line install flag).
    Start-Process msiexec.exe -ArgumentList "/i `"$tmp\fleet-agent.msi`" ENABLE_SCRIPTS=true /quiet" -Wait
    Write-Host "  - Fleet agent installed"

    # 2.5 Install restic (Obaits Backup) -- winget is present on Windows 10
    # 21H2+ and current Windows 11 builds, but NOT on Windows Server 2019
    # or earlier (confirmed directly on a real Windows Server 2019 device
    # -- winget genuinely does not exist there at all). Real, durable
    # fix: a direct-download fallback using restic's own GitHub Releases
    # API, resolving the CURRENT real version at install time rather than
    # hardcoding a version number that would inevitably go stale.
    Write-Host "  - installing restic"
    $resticOk = $false
    if (Get-Command winget -ErrorAction SilentlyContinue) {
        try {
            winget install --id restic.restic -e --accept-source-agreements --accept-package-agreements --source winget --silent
        } catch {
            Write-Host "    WARNING: winget install of restic failed: $($_.Exception.Message)"
        }
        $env:Path = [System.Environment]::GetEnvironmentVariable("Path","Machine") + ";" + [System.Environment]::GetEnvironmentVariable("Path","User")
        if (Get-Command restic -ErrorAction SilentlyContinue) {
            $resticOk = $true
        } else {
            Write-Host "    WARNING: restic does not appear on PATH after winget install."
        }
    }
    if (-not $resticOk) {
        Write-Host "    winget unavailable or failed -- falling back to a direct download from restic's own GitHub Releases."
        try {
            $resticDir = "C:\Program Files\restic"
            New-Item -ItemType Directory -Path $resticDir -Force -ErrorAction SilentlyContinue | Out-Null
            $releaseInfo = Invoke-RestMethod -Uri "https://api.github.com/repos/restic/restic/releases/latest" -UseBasicParsing
            $asset = $releaseInfo.assets | Where-Object { $_.name -like "*windows_amd64.zip" } | Select-Object -First 1
            if (-not $asset) {
                throw "Could not find a windows_amd64.zip asset in the latest restic release."
            }
            $zipPath = Join-Path $env:TEMP "restic.zip"
            $gotIt = Get-ObaitsPackage -Tool "restic" -OutFile $zipPath -FallbackUrl $asset.browser_download_url
            if (-not $gotIt) { throw "Could not obtain the restic package from either the Obaits mirror or GitHub." }
            Expand-Archive -Path $zipPath -DestinationPath $resticDir -Force
            $resticExe = Get-ChildItem -Path $resticDir -Filter "restic*.exe" | Select-Object -First 1
            if ($resticExe) {
                Rename-Item -Path $resticExe.FullName -NewName "restic.exe" -Force -ErrorAction SilentlyContinue
            }
            Remove-Item -Path $zipPath -Force -ErrorAction SilentlyContinue

            # Persist the real install directory on Machine PATH so future
            # sessions/Fleet Scripts find it without needing a refresh.
            $machinePath = [System.Environment]::GetEnvironmentVariable("Path","Machine")
            if ($machinePath -notlike "*$resticDir*") {
                [System.Environment]::SetEnvironmentVariable("Path", "$machinePath;$resticDir", "Machine")
            }
            $env:Path = [System.Environment]::GetEnvironmentVariable("Path","Machine") + ";" + [System.Environment]::GetEnvironmentVariable("Path","User")

            if (Get-Command restic -ErrorAction SilentlyContinue) {
                $resticOk = $true
                Write-Host "    restic installed directly (no winget needed)."
            } else {
                Write-Host "    WARNING: direct restic download completed but restic.exe still not found on PATH."
            }
        } catch {
            Write-Host "    WARNING: direct restic download failed: $($_.Exception.Message)"
        }
    }
    if (-not $resticOk) {
        Write-Host "    Backup features will be unavailable on this device until restic is installed manually."
    }

    # 2.6 Install Tailscale + join this tenant's Headscale VPN (Phase 4)
    # Mirrors restic's install pattern: idempotent binary check, but the
    # VPN JOIN itself always re-runs regardless of whether the binary
    # needed installing — a re-enrolled/re-imaged device lost its prior
    # tailnet identity and needs to rejoin either way. Every failure path
    # warns and continues; a VPN hiccup must never block Fleet/Wazuh setup.
    Write-Host "  - installing Tailscale"
    $tailscaleExe = "C:\Program Files\Tailscale\tailscale.exe"
    $tailscaleOk = $false
    if (Get-Command winget -ErrorAction SilentlyContinue) {
        try {
            winget install --id Tailscale.Tailscale -e --accept-source-agreements --accept-package-agreements --source winget --silent
        } catch {
            Write-Host "    WARNING: winget install of Tailscale failed: $($_.Exception.Message)"
        }
        if (Test-Path $tailscaleExe) {
            $tailscaleOk = $true
        }
    }
    if (-not $tailscaleOk) {
        # REAL BUG, found on a real Windows Server 2019 device: winget
        # genuinely does not exist at all on this OS (only ships by
        # default on Windows 10 21H2+/current Windows 11/Server 2022+).
        # Real, durable fix: a direct MSI download, resolving the CURRENT
        # real version from Tailscale's own live packages page at install
        # time rather than hardcoding a version number that would
        # inevitably go stale.
        Write-Host "    winget unavailable or failed -- falling back to a direct MSI download from Tailscale's own packages site."
        try {
            $pkgPage = Invoke-WebRequest -Uri "https://pkgs.tailscale.com/stable/" -UseBasicParsing
            if ($pkgPage.Content -match 'tailscale-setup-([0-9.]+)-amd64\.msi') {
                $tsVersion = $matches[1]
                $msiUrl = "https://pkgs.tailscale.com/stable/tailscale-setup-$tsVersion-amd64.msi"
                $msiPath = Join-Path $env:TEMP "tailscale-setup.msi"
                $gotIt = Get-ObaitsPackage -Tool "tailscale" -OutFile $msiPath -FallbackUrl $msiUrl
                if (-not $gotIt) { throw "Could not obtain the Tailscale package from either the Obaits mirror or Tailscale's own site." }
                Start-Process msiexec.exe -ArgumentList "/i", "`"$msiPath`"", "/quiet", "/norestart" -Wait
                Remove-Item -Path $msiPath -Force -ErrorAction SilentlyContinue
                if (Test-Path $tailscaleExe) {
                    $tailscaleOk = $true
                    # Confirmed directly on a real device: this direct MSI
                    # install does NOT add its own directory to PATH the
                    # way winget's install does. Our own script always
                    # uses $tailscaleExe's full path directly regardless
                    # (matching the same discipline already proven for
                    # Fleet Scripts), but persisting PATH too matches
                    # winget's own real behavior for admin convenience.
                    $tsDir = Split-Path $tailscaleExe -Parent
                    $machinePathTs = [System.Environment]::GetEnvironmentVariable("Path","Machine")
                    if ($machinePathTs -notlike "*$tsDir*") {
                        [System.Environment]::SetEnvironmentVariable("Path", "$machinePathTs;$tsDir", "Machine")
                    }
                    Write-Host "    Tailscale $tsVersion installed directly (no winget needed)."
                } else {
                    Write-Host "    WARNING: direct Tailscale MSI install completed but tailscale.exe still not found."
                }
            } else {
                Write-Host "    WARNING: could not determine the current Tailscale version from the packages page."
            }
        } catch {
            Write-Host "    WARNING: direct Tailscale download/install failed: $($_.Exception.Message)"
        }
    }
    if (-not $tailscaleOk) {
        Write-Host "    This device will not be reachable over the tenant VPN until Tailscale is installed manually."
    } else {
        try {
            $hsEnv = Invoke-WebRequest -Uri "$($env:OBAITS_PORTAL_URL)/enroll/headscale-credentials" `
                -Headers @{ "X-Obaits-Enroll-Secret" = $env:OBAITS_ENROLL_SECRET } -UseBasicParsing
            $hsLines = $hsEnv.Content -split "`n"
            $hsServer = ($hsLines | Where-Object { $_ -match "^HEADSCALE_LOGIN_SERVER=" }) -replace "^HEADSCALE_LOGIN_SERVER=", ""
            $hsKey    = ($hsLines | Where-Object { $_ -match "^HEADSCALE_AUTH_KEY=" }) -replace "^HEADSCALE_AUTH_KEY=", ""

            if ($hsServer -and $hsKey) {
                $hsServerClean = $hsServer.Trim()
                $hsKeyClean = $hsKey.Trim()
                # REAL BUG FOUND 2026-08-15, reported live by a real client
                # (repeated Tailscale re-auth prompts despite multiple
                # re-enrollments): "tailscale up" REFUSES to apply new
                # settings once a device already has ANY non-default
                # Tailscale config from a prior run -- confirmed directly,
                # real error: "changing settings via 'tailscale up'
                # requires mentioning all non-default flags ... use
                # --reset". This device had --accept-routes and
                # --advertise-routes set from an earlier enrollment; this
                # exact re-run failed outright with that real error --
                # and the script printed "joined tenant VPN" anyway,
                # because $LASTEXITCODE was NEVER CHECKED. Two real fixes,
                # together: --reset makes every run start from a clean
                # slate (matching this project's own "always clear prior
                # identity, never special-case" philosophy already
                # applied to Fleet/Wazuh), and the exit code is now
                # genuinely checked before ever claiming success.
                & $tailscaleExe up "--login-server=$hsServerClean" "--authkey=$hsKeyClean" --accept-routes --reset
                if ($LASTEXITCODE -eq 0) {
                    Write-Host "    joined tenant VPN"
                } else {
                    Write-Host "    WARNING: 'tailscale up' failed (exit code $LASTEXITCODE) -- this device did NOT join the tenant VPN. Re-run the install script, or run 'tailscale up' manually to see the real error."
                }
            } else {
                Write-Host "    WARNING: no VPN instance provisioned yet for this tenant — skipping VPN join."
            }
        } catch {
            Write-Host "    WARNING: could not join tenant VPN: $($_.Exception.Message)"
        }
    }


    # --- Fetch this device's policy credential (Phase: device credentials) -----
    # Same reasoning as the Linux side: long-lived and rotated on every
    # enrollment run, because a launch-time token cannot survive a device
    # being offline for weeks (public holidays, staff leave). Warn-and-
    # continue, matching every other optional step in this script.
    Write-Host "  - fetching device credential"
    try {
        $credResp = Invoke-WebRequest -Uri "$($env:OBAITS_PORTAL_URL)/enroll/device-credential" -Method POST `
            -Headers @{ "X-Obaits-Enroll-Secret" = $env:OBAITS_ENROLL_SECRET } `
            -Body @{ hostname = $env:COMPUTERNAME } -UseBasicParsing

        $deviceSecret = $credResp.Content.Trim()
        if ($deviceSecret) {
            $credPath = "$markerDir\device-credential"
            # REAL BUG FOUND 2026-08-11, immediately after the icacls SID fix
            # below: -Force on Set-Content overrides a read-only FILE
            # ATTRIBUTE, but has NO EFFECT on an NTFS ACL permission -- those
            # are enforced by the OS below PowerShell's own file-attribute
            # layer. On a RE-ENROLLMENT (this credential rotates every run by
            # design), the file already exists from the PREVIOUS run, already
            # locked to Read-only for everyone including Administrators/SYSTEM
            # by the icacls call at the end of this same block -- so this
            # Set-Content call was silently denied, confirmed live: "L'acces
            # au chemin d'acces ... est refuse." This bug almost certainly
            # predates today -- the earlier hardcoded "Administrators" name
            # was silently FAILING on non-English Windows (the very bug fixed
            # below), meaning the lockdown was never actually being applied,
            # which is exactly why re-enrollment "worked" before purely by
            # accident. Fixing the SID exposed this second, previously-hidden
            # bug in the same code. Real fix: remove any existing file first,
            # so each run creates a genuinely fresh file that inherits normal
            # write permission from the parent directory, and the lockdown
            # below re-applies cleanly every single time.
            if (Test-Path $credPath) {
                # REAL BUG FOUND 2026-08-11, confirmed live on the actual
                # NOFIA-SA machine: Remove-Item -Force alone still fails
                # here with "access denied", EVEN after granting the file
                # explicit (R,D) rights via icacls -- confirmed directly,
                # that grant alone was NOT sufficient. Deleting a file
                # through its parent folder needs a specific NTFS right
                # (FILE_DELETE_CHILD) that icacls' basic grant shorthand
                # does not map onto reliably here. Taking ownership FIRST
                # sidesteps the whole DACL question -- confirmed live,
                # this is the one sequence that actually worked:
                # takeown -> grant full control to self -> delete.
                try {
                    takeown /F $credPath | Out-Null
                    icacls $credPath /grant:r "*S-1-5-32-544:(F)" | Out-Null
                    Remove-Item -Path $credPath -Force -ErrorAction Stop
                } catch {
                    Write-Host "    WARNING: could not remove the prior device credential file: $($_.Exception.Message)"
                }
            }
            Set-Content -Path $credPath -Value $deviceSecret -NoNewline -Force

            # No chmod equivalent on Windows -- restrict read access to
            # SYSTEM and Administrators only via ACL instead. Without this,
            # any local unprivileged user could read a credential capable
            # of fetching this tenant's policy scripts.
            #
            # REAL BUG FOUND 2026-08-11 on a real customer machine: "Administrators"
            # is the ENGLISH display name of the built-in Administrators group --
            # on a French Windows install (confirmed live) the group is actually
            # named "Administrateurs", and icacls failed with "Le mappage entre
            # les noms de compte et les ID de securite n'a pas ete effectue"
            # (account-name-to-SID mapping failed). Every non-English Windows
            # edition would hit the identical failure. Real, permanent fix: the
            # Administrators group's WELL-KNOWN SID (S-1-5-32-544) is IDENTICAL
            # on every language edition of Windows -- referencing it by SID
            # instead of by localized name works everywhere, on every locale,
            # with no translation table to maintain.
            icacls $credPath /inheritance:r | Out-Null
            icacls $credPath /grant:r "SYSTEM:(R)" "*S-1-5-32-544:(R)" | Out-Null

            Write-Host "    device credential stored"
        } else {
            Write-Host "    WARNING: device credential response was empty."
        }
    } catch {
        Write-Host "    WARNING: could not fetch a device credential: $($_.Exception.Message)"
        Write-Host "    Policy checks will be unavailable on this device until the install script is re-run."
    }

    # REAL FEATURE 2026-08-23: persist the portal URL so unattended,
    # device-side work (the threat-intel scheduled task below) can find the
    # portal without an environment. Mirrors /etc/obaits/portal-url on Linux.
    # Deliberately a FILE rather than a value baked into the task action: a
    # rename (Obaits -> SecX) or an on-prem/.iso deployment is then a one-line
    # edit per device instead of re-registering the task everywhere.
    #
    # Deliberately NOT ACL-locked like the device credential above -- this is
    # not a secret, and locking it would only risk the task being unable to
    # read it.
    Set-Content -Path "$markerDir\portal-url" -Value $env:OBAITS_PORTAL_URL -NoNewline -Force


    # 3. Wazuh agent, pinned to the manager version
    #
    # REAL BUG FOUND on a real device (WinClient): a re-enrollment always
    # ran as an MSI REPAIR, not a fresh install, since Wazuh Agent was
    # already present as an installed product (confirmed via
    # Get-WmiObject Win32_Product). Wazuh's installer, like most WiX-based
    # packages, deliberately only runs its first-time setup custom
    # actions (SetAgentGroup, SetAgentName, SetWazuhPermissions,
    # SetOSVersion0/1) on a genuine first install — confirmed directly in
    # a verbose MSI log: "Skipping action: SetAgentGroup (condition is
    # false)" on every repair. Removing client.keys alone was NEVER
    # sufficient to force a real re-registration with a new group list —
    # the actual package must be uninstalled first. Same class of lesson
    # already solved for Fleet on Linux (Part F: an in-place package
    # upgrade doesn't renegotiate --enable-scripts either), just never
    # previously applied to this specific Windows installer.
    $existingWazuh = Get-WmiObject -Class Win32_Product -Filter "Name='Wazuh Agent'" -ErrorAction SilentlyContinue
    if ($existingWazuh) {
        Write-Host "  - removing existing Wazuh agent (forces fresh registration)"
        Start-Process msiexec.exe -ArgumentList "/x", $existingWazuh.IdentifyingNumber, "/quiet", "/norestart" -Wait
    }


    # REAL BUG FOUND on a real device: a legitimate re-enrollment can
    # still collide with the manager's OWN prior record for this exact
    # hostname (the manager doesn't know the old install was just
    # removed) — confirmed directly: WinClient looped for over a minute
    # with "Duplicate agent name" before the record was cleared manually.
    # Windows installs run msiexec /q (silent) so we never see agent-
    # auth's own real-time output the way install.sh does on Linux — so
    # rather than trying to detect the error reactively from hidden MSI
    # output, call the SAME precheck endpoint Linux already uses,
    # proactively, every time, before ever attempting installation. A
    # DIFFERENT live device sharing this hostname is still correctly
    # refused server-side (409) — this only clears a genuinely dead
    # prior identity for THIS tenant.
    Write-Host "  - checking for a stale prior registration"
    try {
        Invoke-WebRequest -Uri "$($env:OBAITS_PORTAL_URL)/enroll/wazuh-precheck" -Method POST `
            -Headers @{ "X-Obaits-Enroll-Secret" = $env:OBAITS_ENROLL_SECRET } `
            -Body @{ agent_name = $env:COMPUTERNAME } -UseBasicParsing | Out-Null
    } catch {
        if ($_.Exception.Response.StatusCode.value__ -ne 404 -and $_.Exception.Response.StatusCode.value__ -ne 409) {
            Write-Host "    (precheck returned $($_.Exception.Response.StatusCode.value__) — continuing anyway)"
        }
    }
    $wazuhUrl = "https://packages.wazuh.com/4.x/windows/wazuh-agent-$($env:OBAITS_WAZUH_VERSION).msi"
    $gotIt = Get-ObaitsPackage -Tool "wazuh-agent" -OutFile "$tmp\wazuh-agent.msi" -FallbackUrl $wazuhUrl -WazuhVersion $env:OBAITS_WAZUH_VERSION
    if (-not $gotIt) { throw "Could not obtain the Wazuh agent package from either the Obaits mirror or Wazuh's own site." }
    # REAL BUG FOUND on a real device: WAZUH_AGENT_GROUP's value (a
    # comma-separated multigroup string, e.g. "tenant-x,windows-client")
    # was passed to msiexec WITHOUT the same quoting already correctly
    # used for the MSI path one line above. Confirmed on WinClient: after
    # a real re-enrollment, Wazuh's own API showed the agent registered
    # with ONLY the tenant group — Tier 1 (windows-client) never applied.
    # Quoting the value explicitly, matching the /i argument's own
    # existing pattern, so msiexec receives the full comma-containing
    # string as one contiguous property value.
    Start-Process msiexec.exe -ArgumentList @(
        "/i `"$tmp\wazuh-agent.msi`"", "/q",
        "WAZUH_MANAGER=$($env:OBAITS_WAZUH_MANAGER)",
        "WAZUH_AGENT_GROUP=`"$($env:OBAITS_WAZUH_GROUP)`"",
        "WAZUH_REGISTRATION_PASSWORD=$($env:OBAITS_WAZUH_ENROLL_PASSWORD)"
    ) -Wait
    Start-Service -Name WazuhSvc
    Write-Host "  - Wazuh agent installed"
}
finally { Remove-Item -Recurse -Force $tmp -ErrorAction SilentlyContinue }

# --- Audit enablement (Phase 4.1 Stage 2) -----------------------------------
# Transparent to the user — runs automatically, warns and continues on any
# failure rather than aborting the rest of enrollment (Fleet/Wazuh matter
# more). Fetched fresh each time, same reasoning as the Linux side.
Write-Host "  - enabling host audit logging (Sysmon, PowerShell logging, audit policy)"
try {
    Invoke-Expression (Invoke-WebRequest -Uri "$($env:OBAITS_PORTAL_URL)/enroll/audit-windows.ps1" -UseBasicParsing).Content
    Write-Host "    audit logging enabled"
} catch {
    Write-Host "    WARNING: audit enablement had issues: $($_.Exception.Message)"
    Write-Host "    Security monitoring will still work, just with less detail than intended."
}

# --- Zabbix agent (IT asset health monitoring) ------------------------------
# Real, official CDN "latest" alias for the 7.0 LTS branch (confirmed
# directly: cdn.zabbix.com/zabbix/binaries/stable/7.0/latest/...) -- no
# version resolution needed, unlike restic/Tailscale above, since Zabbix's
# own CDN provides a genuinely permanent pointer for this. Uses Zabbix's
# own native HostMetadata auto-registration mechanism (confirmed directly
# against Zabbix's own official API docs) -- the server-side action already
# created for this tenant (ZabbixStep) reads this real, random secret and
# automatically places the new host in the correct tenant's host group,
# with no manual host.create call needed here at all. Same real
# warn-and-continue philosophy as restic/Tailscale above: a Zabbix problem
# must never block Fleet/Wazuh, which matter more.
Write-Host "  - installing Zabbix agent (IT asset health monitoring)"
try {
    $zbxMsiUrl = "https://cdn.zabbix.com/zabbix/binaries/stable/7.0/latest/zabbix_agent2-7.0-latest-windows-amd64-openssl.msi"
    $zbxMsiPath = Join-Path $env:TEMP "zabbix-agent2.msi"
    $gotIt = Get-ObaitsPackage -Tool "zabbix-agent" -OutFile $zbxMsiPath -FallbackUrl $zbxMsiUrl
    if (-not $gotIt) { throw "Could not obtain the Zabbix agent package from either the Obaits mirror or Zabbix's own CDN." }
    # REAL BUG FOUND on a real device: this MSI's own installer has a
    # genuine, unconditional LaunchCondition requiring SERVER= to be set
    # -- confirmed directly via a verbose MSI log ("Please enter the
    # name or IP address in the Zabbix server IP/DNS field or set SERVER
    # variable on command line!") -- even though this deployment only
    # ever uses active checks (SERVERACTIVE). The install silently
    # aborted (LaunchConditions, return value 3) with zero real error
    # surfaced to our own try/catch, since msiexec itself still exited
    # cleanly. Real fix: set SERVER= to the same real address too,
    # purely to satisfy this real installer requirement -- it has no
    # other real effect since only active checks are actually used.
    $zbxServerHost = ($env:OBAITS_ZABBIX_SERVER -split ":")[0]
    Start-Process msiexec.exe -ArgumentList @(
        "/i `"$zbxMsiPath`"", "/qn",
        "SERVER=$zbxServerHost",
        "SERVERACTIVE=$($env:OBAITS_ZABBIX_SERVER)",
        "HOSTNAME=$($env:COMPUTERNAME)",
        "HOSTMETADATA=$($env:OBAITS_ZABBIX_SECRET)",
        "ENABLEPATH=1", "SKIP=fw"
    ) -Wait
    Remove-Item -Path $zbxMsiPath -Force -ErrorAction SilentlyContinue

    $zbxSvc = Get-Service -Name "Zabbix Agent 2" -ErrorAction SilentlyContinue
    if ($zbxSvc) {
        Start-Service -Name "Zabbix Agent 2" -ErrorAction SilentlyContinue
        Write-Host "    Zabbix agent installed and running"
    } else {
        Write-Host "    WARNING: Zabbix agent service not found after install."
    }
} catch {
    Write-Host "    WARNING: Zabbix agent install failed: $($_.Exception.Message)"
    Write-Host "    IT asset health monitoring will be unavailable on this device."
}


# --- Install PSWindowsUpdate for OS Update Enforcement -----------------------
# REAL DECISION 2026-08-12, made after a real incident on the Linux side
# (an unconstrained OS-update policy upgraded wazuh-agent unattended,
# wiping its own config/keys and taking real monitoring offline -- see
# install.sh's own apt-mark hold block for the full story). The Windows
# equivalent of that protection needs a REAL, named exclusion mechanism,
# not a workaround -- PSWindowsUpdate's -NotTitle/-NotKBArticleID gives us
# that. It is not installed by default, so it is added here, once, at
# enrollment, the same way restic/Tailscale are self-hosted rather than
# assumed present. Warn-and-continue: a failure here must never block
# Fleet/Wazuh/Zabbix, which matter more -- OS Update Enforcement simply
# will not function on this device until re-enrolled if this step fails.
Write-Host "  - installing PSWindowsUpdate module (for OS Update Enforcement)"
try {
    Install-PackageProvider -Name NuGet -MinimumVersion 2.8.5.201 -Force -Scope AllUsers -ErrorAction Stop | Out-Null
    Install-Module -Name PSWindowsUpdate -Force -Scope AllUsers -ErrorAction Stop
    Write-Host "    PSWindowsUpdate installed"
} catch {
    Write-Host "    WARNING: PSWindowsUpdate install failed: $($_.Exception.Message)"
    Write-Host "    OS Update Enforcement will be unavailable on this device."
}

# --- Baseline threat-intel protection, triggered immediately, backgrounded -
# REAL FIX 2026-08-17: same real gap this had on Linux -- without this,
# a freshly enrolled device only gets protection on the NEXT hourly
# sweep, up to a real 60-minute gap. Must never block the rest of
# enrollment -- Start-Job runs this fully detached, matching the exact
# real requirement already applied on Linux (nohup ... &).
Write-Host "  - starting baseline threat-intel protection (running in background)"
# REAL FIX 2026-08-22, found during a real portability audit: this
# was hardcoded to https://app.obaits.com instead of using
# $env:OBAITS_PORTAL_URL like the rest of this same script correctly
# does everywhere else. Passed explicitly via -ArgumentList rather than
# relying on env-var inheritance into the Start-Job's own runspace.
Start-Job -ScriptBlock {
    param($portalUrl)
    try {
        $s = (Invoke-WebRequest -Uri "$portalUrl/enroll/threat-intel-refresh.ps1" -UseBasicParsing).Content
        Invoke-Expression $s
    } catch { }
} -ArgumentList $env:OBAITS_PORTAL_URL | Out-Null

# REAL FEATURE 2026-08-23: hourly refresh now runs from a DEVICE-SIDE
# scheduled task. It previously ran by pushing a script to every host every
# hour through Fleet -- but Fleet SERIALISES script execution per host, and
# those pushes accumulated into a confirmed 240-item backlog across 3 hosts
# (oldest queued FIVE DAYS) that starved genuine Group Policy checks. Mirrors
# the systemd timer now installed by the Linux enrollment script.
#
# The immediate run above is deliberately KEPT: a freshly enrolled device must
# not wait up to an hour for its first protection cycle.
#
# Runs as SYSTEM deliberately -- the device credential this refresh reports
# health with is ACL-restricted to SYSTEM:(R) and Administrators:(R) by the
# credential block earlier in this same script. Any other principal could not
# read it, and health reporting would silently never happen.
#
# RandomDelay spreads devices across the hour so a hundred of them do not hit
# the portal, or the upstream feeds, simultaneously on the hour. Equivalent to
# RandomizedDelaySec=3000 on the Linux timer.
#
# A WRAPPER FILE, not an inline -Command: the inline form needs three levels
# of nested quoting (single-quoted argument, doubled quotes for the path,
# escaped quotes for the URL interpolation), which is genuinely error-prone
# and near-impossible to debug on a remote device. A real file on disk can be
# run by hand to see exactly what it does.
Write-Host "  - installing the hourly threat-intel refresh scheduled task"
try {
    $wrapperPath = "$markerDir\threat-intel-refresh-runner.ps1"
    $wrapper = @"
`$u = (Get-Content 'C:\ProgramData\Obaits\portal-url' -Raw).Trim()
`$s = (Invoke-WebRequest -Uri "`$u/enroll/threat-intel-refresh.ps1" -UseBasicParsing).Content
Invoke-Expression `$s
"@
    Set-Content -Path $wrapperPath -Value $wrapper -Force

    $tiAction = New-ScheduledTaskAction -Execute "powershell.exe" `
        -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$wrapperPath`""
    $tiTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date) `
        -RepetitionInterval (New-TimeSpan -Hours 1)
    $tiTrigger.RandomDelay = 'PT50M'
    $tiPrincipal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest
    Register-ScheduledTask -TaskName "Obaits-ThreatIntel" -Action $tiAction -Trigger $tiTrigger `
        -Principal $tiPrincipal -Description "Hourly Obaits baseline threat-intel refresh" -Force | Out-Null
    Write-Host "    threat-intel scheduled task registered"
} catch {
    Write-Host "    WARNING: could not register the threat-intel scheduled task: $($_.Exception.Message)"
    Write-Host "    This device will not refresh its threat-intel automatically." 
}

# --- CIS Baseline via Wazuh SCA, audit-only ---------------------------------
# REAL FEATURE 2026-08-18, same real design and safety decision as the
# Linux side (see install-sh.blade.php's own comment for the full real
# story): audit-only, never auto-remediate individual CIS checks. Real
# Windows Server CIS policies ARE genuinely present on this Wazuh
# version (confirmed directly: cis_win2019.yml, cis_win2016.yml,
# cis_win2022.yml all exist, shipped .disabled by default) -- unlike
# Ubuntu 24.04, no adaptation is needed here, just fetching and placing
# the real, correct, unmodified official file for the detected Windows
# Server version.
$osCaption = (Get-CimInstance Win32_OperatingSystem).Caption
$scaPolicyFile = $null
if ($osCaption -like "*Server 2019*") {
    $scaPolicyFile = "cis_win2019.yml"
} elseif ($osCaption -like "*Server 2016*") {
    $scaPolicyFile = "cis_win2016.yml"
} elseif ($osCaption -like "*Server 2022*") {
    $scaPolicyFile = "cis_win2022.yml"
}

if ($scaPolicyFile) {
    Write-Host "  - fetching CIS Baseline SCA policy ($scaPolicyFile)"
    try {
        # REAL FIX 2026-08-22, found during a real portability audit: same
        # hardcoded-URL issue as above, fixed the same way.
        $scaContent = (Invoke-WebRequest -Uri "$($env:OBAITS_PORTAL_URL)/enroll/sca-policy/$scaPolicyFile" -UseBasicParsing).Content
        $scaDest = "C:\Program Files (x86)\ossec-agent\ruleset\sca\$scaPolicyFile"
        Set-Content -Path $scaDest -Value $scaContent -Force
        Write-Host "    CIS Baseline policy installed"
    } catch {
        Write-Host "    WARNING: could not fetch CIS Baseline policy -- SCA will run without a matched policy on this device: $($_.Exception.Message)"
    }
} else {
    Write-Host "  - CIS Baseline: no matched Windows Server CIS policy for this OS version ($osCaption) yet."
}




Start-Sleep -Seconds 10
$fleetOk = (Get-Service -Name "Fleet osquery" -ErrorAction SilentlyContinue).Status -eq "Running"
$wazuhOk = (Get-Service -Name "WazuhSvc" -ErrorAction SilentlyContinue).Status -eq "Running"

Write-Host ""
Write-Host "==> Done.  Fleet: $fleetOk   Wazuh: $wazuhOk"
Write-Host "    This computer should appear in your Obaits portal within a few minutes."