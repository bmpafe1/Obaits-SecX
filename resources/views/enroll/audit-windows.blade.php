<#
.SYNOPSIS
    Obaits — Windows Endpoint Audit Enablement (Phase 4.1 Stage 2)
.DESCRIPTION
    Installs Sysmon, configures PowerShell logging, sets event log sizes,
    and applies advanced audit policies. Run automatically and
    transparently at the end of enrollment, not a separate manual step.
.NOTES
    Sysmon binary/config are fetched from Obaits' own storage, NOT live
    from sysinternals.com/GitHub — those were fetched and checksum-
    verified once during setup (2026-07-27), then hosted here, so a real
    enrollment never depends on two third-party endpoints staying
    reachable and untampered forever.
#>

$ErrorActionPreference = 'Continue'  # report problems, never abort the whole script on one

# --- 1. Configuration Variables ---
$SysmonDownloadUrl = "$($env:OBAITS_PORTAL_URL)/enroll/assets/sysmon64.exe"
$SysmonConfigUrl = "$($env:OBAITS_PORTAL_URL)/enroll/assets/sysmonconfig.xml"
$SysmonInstallerPath = "$env:TEMP\Sysmon64.exe"
$SysmonConfigPath = "$env:TEMP\sysmonconfig.xml"

# --- 2. Install Sysmon ---
Write-Host "[*] Checking for Sysmon..."
if (Get-Process -Name "Sysmon64" -ErrorAction SilentlyContinue) {
    Write-Host "[+] Sysmon is already running."
} else {
    try {
        Write-Host "[+] Downloading Sysmon from Obaits..."
        Invoke-WebRequest -Uri $SysmonDownloadUrl -OutFile $SysmonInstallerPath -UseBasicParsing

        Write-Host "[+] Downloading Sysmon config from Obaits..."
        Invoke-WebRequest -Uri $SysmonConfigUrl -OutFile $SysmonConfigPath -UseBasicParsing

        Write-Host "[+] Installing Sysmon..."
        Start-Process -FilePath $SysmonInstallerPath -ArgumentList "-accepteula -i $SysmonConfigPath" -Wait -NoNewWindow

        # REAL BUG, caught on the first fully-successful run: checked the
        # wrong process name (real name is Sysmon64, not Sysmon) AND
        # checked immediately after Start-Process returned, before the
        # service had finished registering as running — Sysmon had
        # genuinely started (confirmed: real PID, real Running service
        # status) but this warned anyway. Fixed: correct name, small
        # retry loop instead of one immediate check.
        $sysmonRunning = $false
        for ($i = 0; $i -lt 5; $i++) {
            if (Get-Process -Name "Sysmon64" -ErrorAction SilentlyContinue) {
                $sysmonRunning = $true
                break
            }
            Start-Sleep -Seconds 2
        }
        if ($sysmonRunning) {
            Write-Host "[+] Sysmon installed successfully."
        } else {
            Write-Host "[-] WARNING: Sysmon install did not result in a running process."
        }
    } catch {
        Write-Host "[-] WARNING: Sysmon setup failed: $($_.Exception.Message)"
    }
}

# --- 3. Enable PowerShell Logging (Critical for Lateral Movement & Script-Based Attacks) ---
try {
    Write-Host "[*] Enabling PowerShell Script Block and Module Logging..."
    $PowerShellLoggingPath = "HKLM:\SOFTWARE\Policies\Microsoft\Windows\PowerShell\ScriptBlockLogging"
    $PowerShellModulePath = "HKLM:\SOFTWARE\Policies\Microsoft\Windows\PowerShell\ModuleLogging"

    if (-not (Test-Path $PowerShellLoggingPath)) { New-Item -Path $PowerShellLoggingPath -Force | Out-Null }
    Set-ItemProperty -Path $PowerShellLoggingPath -Name "EnableScriptBlockLogging" -Value 1 -Type DWord -Force

    if (-not (Test-Path $PowerShellModulePath)) { New-Item -Path $PowerShellModulePath -Force | Out-Null }
    Set-ItemProperty -Path $PowerShellModulePath -Name "EnableModuleLogging" -Value 1 -Type DWord -Force

    $TranscriptPath = "HKLM:\SOFTWARE\Policies\Microsoft\Windows\PowerShell\Transcription"
    if (-not (Test-Path $TranscriptPath)) { New-Item -Path $TranscriptPath -Force | Out-Null }
    Set-ItemProperty -Path $TranscriptPath -Name "EnableTranscripting" -Value 1 -Type DWord -Force
    Set-ItemProperty -Path $TranscriptPath -Name "OutputDirectory" -Value "C:\ProgramData\Obaits\PowerShell" -Type String -Force
    New-Item -Path "C:\ProgramData\Obaits\PowerShell" -ItemType Directory -Force | Out-Null
} catch {
    Write-Host "[-] WARNING: PowerShell logging setup failed: $($_.Exception.Message)"
}

# --- 4. Set Event Log Sizes to prevent overwriting critical security logs ---
# REAL BUG, caught on the first actual Windows test: direct Set-ItemProperty
# against the Security Autologger key threw "Requested registry access is
# not allowed" even running as Administrator (this key is protected beyond
# normal admin rights) — and PowerShell did not treat it as catchable the
# way the surrounding try/catch expected, so it printed raw and moved on.
# wevtutil is the actual supported mechanism for this and does not hit the
# same restriction.
Write-Host "[*] Increasing Event Log sizes to 250MB..."
$eventLogs = @("Security", "System", "Application")
foreach ($log in $eventLogs) {
    try {
        wevtutil sl $log /ms:262144000
    } catch {
        Write-Host "[-] WARNING: could not resize $log log: $($_.Exception.Message)"
    }
}

# --- 5. Enable Advanced Audit Policies via auditpol ---
try {
    Write-Host "[*] Applying Advanced Audit Policies (Account Logon, Object Access, etc.)..."

    # REAL BUG, found on a real French-language Windows Server 2019 (this
    # project's first non-English-locale device): plain SUBCATEGORY NAMES
    # ("Logon", "Credential Validation", etc.) are genuinely English-only,
    # confirmed directly: auditpol on this device only recognizes "Ouvrir
    # la session", "Validation des informations d'identification", etc.,
    # so every name in the original list failed with error 87 here,
    # despite working fine on the original English test device.
    #
    # REAL, DURABLE FIX: subcategories also have stable, documented,
    # LOCALE-INDEPENDENT GUIDs (confirmed via Microsoft's own auditpol
    # documentation: /subcategory:<name>|<{guid}> is a real, first-class
    # alternative). These GUIDs have been unchanged since Windows Vista /
    # Server 2008's introduction of Advanced Audit Policy, per Microsoft's
    # own docs spanning every version since -- but this has only been
    # directly, empirically verified on ONE real device so far (this
    # French Server 2019, via a live auditpol /list /subcategory:* /v),
    # not on every Windows version. A real, live verification loop is
    # included below so any future device that genuinely doesn't
    # recognize one of these GUIDs is reported clearly, never silently
    # ignored.
    #
    # A SEPARATE REAL BUG was found and fixed testing this: an UNQUOTED
    # GUID (auditpol /set /subcategory:{...} ...) fails completely --
    # PowerShell's own curly-brace script-block handling corrupts the
    # token before auditpol.exe ever receives it correctly, confirmed
    # directly (the exact same command, only difference being quotes
    # around the GUID, went from a full command-parse failure to a real,
    # verified success). This is very possibly why an earlier attempt at
    # GUIDs failed on all 17 in the first place. GUIDs below are ALWAYS
    # quoted for this reason.
    $auditCategories = @(
        @{ Name = "Kerberos Service Ticket Operations"; Guid = "{0CCE9240-69AE-11D9-BED3-505054503030}" },
        @{ Name = "Kerberos Authentication Service";    Guid = "{0CCE9242-69AE-11D9-BED3-505054503030}" },
        @{ Name = "Credential Validation";              Guid = "{0CCE923F-69AE-11D9-BED3-505054503030}" },
        @{ Name = "User Account Management";            Guid = "{0CCE9235-69AE-11D9-BED3-505054503030}" },
        @{ Name = "Security Group Management";          Guid = "{0CCE9237-69AE-11D9-BED3-505054503030}" },
        @{ Name = "Distribution Group Management";      Guid = "{0CCE9238-69AE-11D9-BED3-505054503030}" },
        @{ Name = "Process Creation";                   Guid = "{0CCE922B-69AE-11D9-BED3-505054503030}" },
        @{ Name = "Process Termination";                Guid = "{0CCE922C-69AE-11D9-BED3-505054503030}" },
        @{ Name = "DPAPI Activity";                      Guid = "{0CCE922D-69AE-11D9-BED3-505054503030}" },
        @{ Name = "Logon";                               Guid = "{0CCE9215-69AE-11D9-BED3-505054503030}" },
        @{ Name = "Logoff";                              Guid = "{0CCE9216-69AE-11D9-BED3-505054503030}" },
        @{ Name = "Other Logon/Logoff Events";          Guid = "{0CCE921C-69AE-11D9-BED3-505054503030}" },
        @{ Name = "Registry";                            Guid = "{0CCE921E-69AE-11D9-BED3-505054503030}" },
        @{ Name = "File System";                         Guid = "{0CCE921D-69AE-11D9-BED3-505054503030}" },
        @{ Name = "Audit Policy Change";                Guid = "{0CCE922F-69AE-11D9-BED3-505054503030}" },
        @{ Name = "Authentication Policy Change";       Guid = "{0CCE9230-69AE-11D9-BED3-505054503030}" },
        @{ Name = "Authorization Policy Change";        Guid = "{0CCE9231-69AE-11D9-BED3-505054503030}" },
        @{ Name = "Directory Service Access";           Guid = "{0CCE923B-69AE-11D9-BED3-505054503030}" },
        @{ Name = "Directory Service Changes";          Guid = "{0CCE923C-69AE-11D9-BED3-505054503030}" },
        # Added deepening real Active Directory coverage, confirmed
        # directly on a real domain controller: Computer Account
        # Management only had Success auditing (missing Failure);
        # Application Group Management and Other Account Management
        # Events had no auditing at all. All genuinely harmless no-ops
        # on any non-DC machine, same reasoning as above.
        @{ Name = "Computer Account Management";        Guid = "{0CCE9236-69AE-11D9-BED3-505054503030}" },
        @{ Name = "Application Group Management";       Guid = "{0CCE9239-69AE-11D9-BED3-505054503030}" },
        @{ Name = "Other Account Management Events";    Guid = "{0CCE923A-69AE-11D9-BED3-505054503030}" }
        # Added for Active Directory role testing, confirmed directly on
        # a real domain controller: Directory Service Access already
        # showed Success-only auditing by default (a genuine Windows
        # Server DC default), but Directory Service Changes -- the
        # subcategory that captures real before/after attribute values,
        # not just "was this object touched" -- showed no auditing at
        # all. Both are genuinely harmless no-ops on any non-DC machine,
        # since Windows simply never generates directory-service events
        # without AD DS installed, matching the same principle already
        # used for our database audit settings.
    )

    foreach ($cat in $auditCategories) {
        auditpol /set /subcategory:"$($cat.Guid)" /success:enable /failure:enable | Out-Null

        # REAL BUG CAUGHT before deploying: an earlier verification
        # attempt checked the /get command's own displayed setting text
        # (expecting "Success and Failure" or a numeric code) -- confirmed
        # directly this text is ALSO locale-dependent ("Succes et echec"
        # in French), which would have made a supposedly "locale-
        # independent" check falsely warn on every single real success on
        # this device. The genuinely locale-independent signal is
        # $LASTEXITCODE itself -- a real, numeric process exit code,
        # never translated -- confirmed directly: 0 on a real, verified
        # success on this exact device.
        if ($LASTEXITCODE -ne 0) {
            Write-Host "[-] WARNING: could not set '$($cat.Name)' ($($cat.Guid)) - auditpol exit code $LASTEXITCODE."
        }
    }
} catch {
    Write-Host "[-] WARNING: Advanced audit policy setup failed: $($_.Exception.Message)"
}

Write-Host "[+] Windows audit enablement complete."
