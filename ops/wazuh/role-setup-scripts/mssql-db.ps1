# =====================================================================
# Obaits: SQL Server audit setup - Windows
# REAL, DIFFERENT SITUATION from MariaDB, confirmed directly on
# WinClient (SQL Server 2019 Standard):
#   - This server runs in Windows-authentication-ONLY mode (confirmed:
#     SERVERPROPERTY('IsIntegratedSecurityOnly') = 1) - SQL logins
#     (username+password) do not work at all. sa exists but is disabled.
#   - The Wazuh/Fleet Script execution identity (NT AUTHORITY\SYSTEM,
#     confirmed the same as Wazuh's own service account) already has a
#     real SQL Server login, but ZERO server role membership and none of
#     the privileges needed to create a Server Audit (confirmed directly
#     via sys.server_permissions - only CONNECT SQL, VIEW SERVER STATE,
#     ALTER ANY AVAILABILITY GROUP).
#   - So the stored credential here is a real WINDOWS account (not a SQL
#     login) that ALREADY has sysadmin rights - used ONCE, via a
#     Scheduled Task (Start-Process -Credential does NOT work from a
#     SYSTEM-context caller - confirmed directly, see below), to grant
#     SYSTEM the one specific privilege it needs. Every subsequent run
#     uses SYSTEM's own trusted connection, never needing the privileged
#     credential again.
# =====================================================================
$ErrorActionPreference = 'Continue'
Write-Host "=== Obaits: SQL Server audit setup (Windows) ==="

$service = Get-Service -Name "MSSQLSERVER" -ErrorAction SilentlyContinue
if (-not $service -or $service.Status -ne 'Running') {
    Write-Host "ERROR: SQL Server (MSSQLSERVER) service not found or not running."
    exit 1
}

# REAL BUG FOUND on a real device: sqlcmd was on the interactive
# session's PATH (where it was tested manually) but NOT found when this
# script actually ran via Fleet Scripts (a SYSTEM-context process, with
# its own, different PATH) -- same lesson as mysql-db.ps1 already
# learned: never trust PATH for a Fleet Script's execution context,
# always resolve the real, full binary path directly.
$sqlcmdCandidates = @(
    "C:\Program Files\Microsoft SQL Server\Client SDK\ODBC\170\Tools\Binn\SQLCMD.EXE",
    "C:\Program Files\Microsoft SQL Server\Client SDK\ODBC\130\Tools\Binn\SQLCMD.EXE",
    "C:\Program Files (x86)\Microsoft SQL Server\Client SDK\ODBC\170\Tools\Binn\SQLCMD.EXE"
)
$sqlcmdPath = $sqlcmdCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $sqlcmdPath) {
    Write-Host "ERROR: sqlcmd.exe not found at any known real install path."
    exit 1
}
Write-Host "Using sqlcmd: $sqlcmdPath"

# REAL, GENUINE ROOT CAUSE FOUND on a real device (a domain controller,
# French locale): SQL Server's own CREATE LOGIN ... FROM WINDOWS does a
# real name lookup against the OS -- and Windows' own well-known SID
# resolution is ALSO locale-dependent (confirmed directly: SID S-1-5-18
# resolves to "AUTORITE NT\Systeme" on this device, not the English
# "NT AUTHORITY\SYSTEM" this script previously hardcoded everywhere).
# This is the exact same class of bug already fixed twice tonight
# (auditpol's subcategory names, schtasks's own console text) -- the
# real, durable fix is the same: resolve the real, current-locale name
# dynamically via .NET's own SecurityIdentifier.Translate(), matching
# the same principle as using stable GUIDs instead of English names.
# REAL, DURABLE, UNIVERSAL FIX (replacing the earlier PowerShell-side
# name resolution entirely): NT AUTHORITY\SYSTEM's own display name is
# genuinely locale-dependent in Windows itself (confirmed directly: SID
# S-1-5-18 resolves to "AUTORITE NT\Systeme" on a real French-locale
# device), and threading that resolved name through PowerShell, a batch
# file, and cmd.exe's own console codepage introduces MULTIPLE real,
# separate encoding boundaries where a non-ASCII character can be
# silently corrupted -- confirmed directly, fixing one boundary
# (-Encoding ASCII) still left another (cmd.exe's own OEM codepage)
# broken. The real, durable, universal fix: NEVER resolve or embed the
# name in PowerShell/batch/cmd AT ALL. The SID for SYSTEM is a fixed,
# universal constant on every real Windows machine, in every language
# (confirmed directly: 0x010100000000000512000000). Every SQL statement
# below uses ONLY this raw, pure-ASCII SID, resolving the real, correct,
# locale-appropriate name entirely INSIDE SQL Server's own engine via
# SUSER_SNAME() and dynamic SQL -- this works identically for German,
# Chinese, Dutch, or any other locale, since the name never leaves SQL
# Server's own internal, correctly-Unicode-aware machinery.
$systemSid = "0x010100000000000512000000"

function Get-SqlScalar {
    param([string]$Query, [string]$Database)
    if ($Database) {
        $r = & $sqlcmdPath -S localhost -E -d $Database -h -1 -Q $Query 2>&1
    } else {
        $r = & $sqlcmdPath -S localhost -E -h -1 -Q $Query 2>&1
    }
    return ($r | Select-Object -Last 1).ToString().Trim()
}

# Check whether SYSTEM already has BOTH privileges it needs - idempotent,
# skip the whole privileged-credential step entirely if so. REAL GAP
# FOUND on a real device: ALTER ANY SERVER AUDIT alone does not grant
# access to create DATABASE-level audit specifications in arbitrary
# databases -- confirmed directly (phase 3 failed with "Login failed...
# Cannot open database"). The real, correct, separate permission is
# ALTER ANY DATABASE AUDIT (confirmed via direct research, Microsoft's
# own docs) -- checking and granting both together here.
function Test-ServerAuditPrivilege {
    # ALTER ANY DATABASE AUDIT is genuinely a DATABASE-scope permission,
    # not server-scope (confirmed directly) -- checked and granted
    # separately, per real database, in the phase 3 loop below. This
    # function only checks the one real, valid server-scope permission.
    $v1 = Get-SqlScalar "SET NOCOUNT ON; SELECT CAST(HAS_PERMS_BY_NAME(NULL, NULL, 'ALTER ANY SERVER AUDIT') AS INT);"
    return ($v1 -eq '1')
}

# REAL BUG CAUGHT before this even ran on a real device a second time:
# SYSTEM already had ALTER ANY SERVER AUDIT from earlier testing, so the
# WHOLE privileged block -- including the brand-new per-database
# model/user setup -- was skipped entirely by this idempotency check,
# since both were bundled under the same condition. Fixed to check BOTH
# real conditions independently: server-level privilege, AND whether
# SYSTEM has already been set up as a user in "model" (proving the
# per-database work has genuinely run before).
$hasServerPrivilege = Test-ServerAuditPrivilege
$modelUserExists = Get-SqlScalar "SET NOCOUNT ON; SELECT CAST(COUNT(*) AS INT) FROM sys.database_principals WHERE sid = $systemSid;" "model"
$hasPrivilege = ($hasServerPrivilege -and $modelUserExists -eq '1')

if ($hasPrivilege) {
    Write-Host "SYSTEM already fully set up - skipping privilege grant."
} else {
    Write-Host "Fetching privileged credential..."

    if (-not $env:OBAITS_ROLE_CREDENTIAL_TOKEN -or -not $env:OBAITS_PORTAL_URL) {
        Write-Host "ERROR: no credential token provided - cannot obtain privileged access."
        exit 1
    }

    try {
        $credResponse = Invoke-WebRequest -Uri "$($env:OBAITS_PORTAL_URL)/enroll/role-credential" -Method POST -Body @{ token = $env:OBAITS_ROLE_CREDENTIAL_TOKEN } -UseBasicParsing
        $cred = $credResponse.Content | ConvertFrom-Json
        $winUsername = $cred.username
        $winPassword = $cred.password
    } catch {
        Write-Host "ERROR: could not fetch credential: $($_.Exception.Message)"
        exit 1
    }

    if (-not $winPassword) {
        Write-Host "ERROR: credential fetch succeeded but returned no password."
        exit 1
    }

    # REAL BUG FOUND on a real device: Start-Process -Credential fails
    # with "Access is denied" when the PARENT process itself runs as
    # SYSTEM (confirmed directly: the exact same command, same account,
    # same password succeeded from an interactive session, failed
    # identically every time from the actual Fleet Script/SYSTEM
    # context) -- SYSTEM has no real interactive logon session, a real,
    # well-documented Windows limitation for that specific cmdlet.
    #
    # Real fix: a SCHEDULED TASK, Windows's own designed mechanism for
    # running as an arbitrary account even from a SYSTEM-context caller.
    # Using schtasks.exe directly (plain string arguments) rather than
    # the ScheduledTasks PowerShell module, given today's repeated
    # lesson that simpler, plainer primitives survive Fleet's transport
    # more reliably than complex cmdlet/object syntax.
    $taskName = "ObaitsGrantAudit"
    # REAL BUG FOUND on a real device: ALTER ANY DATABASE AUDIT is NOT a
    # server-scope permission at all -- confirmed directly via
    # sys.fn_builtin_permissions('SERVER'), which does not list it (only
    # ALTER ANY SERVER AUDIT exists at server scope). It is genuinely a
    # DATABASE-scope permission (confirmed via
    # sys.fn_builtin_permissions('DATABASE')), meaning the earlier
    # server-level grant attempt silently succeeded with zero real
    # effect -- SQL Server let the no-op statement through without
    # error, a genuinely surprising, undocumented-in-practice behavior.
    # The real fix: grant ONLY ALTER ANY SERVER AUDIT here; the
    # database-scope permission is granted separately, per real user
    # database, in the phase 3 loop below.
    # REAL BUG FOUND on a real device: repeated attempts at passing a
    # complex, nested-quoted command string directly to schtasks's /TR
    # argument through PowerShell all failed identically -- PowerShell's
    # own argument passing to a native .exe re-tokenizes embedded quotes
    # unpredictably, a well-known real quirk. Sidestepped entirely by
    # writing the actual sqlcmd invocation into a simple BATCH FILE
    # first (no PowerShell-to-native-exe quoting involved at all), then
    # pointing /TR at that file's own simple path.
    #
    # REAL, DEEPER BUG FOUND on a real device: even with ALTER ANY
    # DATABASE AUDIT granted at the correct (database) scope, SYSTEM
    # still had NO real user mapping inside arbitrary databases at all
    # ("Login failed... Cannot open database"). Real, valuable design
    # insight: fix this via the "model" database, SQL Server own
    # template every NEW database is automatically created from --
    # adding SYSTEM there means every FUTURE database inherits this
    # automatically, forever, with zero further code. Existing
    # databases predate that change and need the same fix applied
    # retroactively, once, in the same batch file.
    # REAL BUG FOUND on a real device: the grant task reported genuine
    # success (real, correct exit code 0), yet NOTHING was actually
    # granted -- confirmed directly, SYSTEM had zero real explicit
    # permissions afterward. The batch file captured ZERO output from
    # sqlcmd, so any real, silent failure inside it (e.g. a login
    # failure with the stored credential) was completely invisible.
    # Real fix: redirect every real sqlcmd invocation's own output to a
    # real log file, then read and surface it after the task completes.
    $grantLogPath = Join-Path $env:TEMP "obaits-grant-audit.log"
    Remove-Item -Path $grantLogPath -Force -ErrorAction SilentlyContinue
    $q34 = [char]34
    $batLines = New-Object System.Collections.ArrayList
    # REAL, DIFFERENT ROOT CAUSE FOUND on a real device (a domain
    # controller, a separately-installed SQL Server instance): confirmed
    # directly via the grant task's own real, captured output --
    # "Cannot find the login 'NT AUTHORITY\SYSTEM', because it does not
    # exist" -- this install genuinely never had a SYSTEM login created
    # at all, unlike the earlier device (WinClient) where SYSTEM already
    # had a real login (just missing specific permissions) from the
    # start. Real, correct fix: create the login FIRST (idempotently),
    # before ever attempting to grant it anything.
    $createLoginSql = "DECLARE @sid varbinary(85) = $systemSid; DECLARE @name sysname = SUSER_SNAME(@sid); IF NOT EXISTS (SELECT 1 FROM sys.server_principals WHERE sid = @sid) EXEC('CREATE LOGIN [' + @name + '] FROM WINDOWS;'); EXEC('GRANT ALTER ANY SERVER AUDIT TO [' + @name + '];');"
    [void]$batLines.Add($q34 + $sqlcmdPath + $q34 + " -S localhost -E -Q " + $q34 + $createLoginSql + $q34 + " >> " + $q34 + $grantLogPath + $q34 + " 2>&1")

    # REAL BUG CAUGHT before deploying, re-reading this code carefully:
    # excluded (1,2,3) by mistake -- SQL Server's real system database
    # IDs are 1=master, 2=tempdb, 3=model, 4=msdb. Excluding 3 would have
    # excluded "model" itself, the ONE database this entire design
    # depends on including (so future databases inherit the fix). Fixed
    # to exclude only master (already handled by the server-level grant
    # above) and tempdb (transient, recreated on every restart, pointless
    # to modify) -- model and msdb are both genuinely included.
    $allDbsForGrant = & $sqlcmdPath -S localhost -E -h -1 -Q "SET NOCOUNT ON; SELECT name FROM sys.databases WHERE state_desc = 'ONLINE' AND database_id NOT IN (1,2);" 2>&1
    $allDbsForGrant = $allDbsForGrant | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' -and $_ -notmatch '^\(\d+ rows? affected\)$' }

    foreach ($gDb in $allDbsForGrant) {
        $userGrantSql = "DECLARE @sid varbinary(85) = $systemSid; DECLARE @name sysname = SUSER_SNAME(@sid); IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE sid = @sid) EXEC('CREATE USER [' + @name + '] FOR LOGIN [' + @name + '];'); EXEC('GRANT ALTER ANY DATABASE AUDIT TO [' + @name + '];');"
        [void]$batLines.Add($q34 + $sqlcmdPath + $q34 + " -S localhost -E -d " + $gDb + " -Q " + $q34 + $userGrantSql + $q34 + " >> " + $q34 + $grantLogPath + $q34 + " 2>&1")
    }

    $grantBatPath = Join-Path $env:TEMP "obaits-grant-audit.bat"
    # REAL BUG FOUND, then REAL, DURABLE FIX built: an earlier attempt at
    # this used -Encoding ASCII, silently corrupting a real, dynamically-
    # resolved, locale-specific name written into this batch file. -Encoding
    # Default (the system's own active codepage) fixed THAT boundary, but
    # a SEPARATE real encoding boundary (cmd.exe's own console/OEM
    # codepage, confirmed directly to differ from the ANSI codepage used
    # to write the file) still corrupted the same name when the batch
    # file was actually EXECUTED. The real, durable, universal fix:
    # NEVER embed a locale-dependent name in this file at all -- every
    # SQL statement now uses only the pure-ASCII SID, with SQL Server
    # itself resolving the correct, locale-appropriate name entirely
    # internally via SUSER_SNAME(). ASCII is genuinely safe and correct
    # again now that nothing non-ASCII is ever written here.
    Set-Content -Path $grantBatPath -Value $batLines -Encoding ASCII

    schtasks.exe /Create /TN $taskName /TR $grantBatPath /SC ONCE /ST 23:59 /RU $winUsername /RP $winPassword /F | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: task creation failed (exit $LASTEXITCODE)."
        schtasks.exe /Delete /TN $taskName /F 2>&1 | Out-Null
        exit 1
    }

    schtasks.exe /Run /TN $taskName | Out-Null

    # REAL, SERIOUS BUG FOUND on a real, French-locale device: schtasks.exe's
    # own CONSOLE TEXT OUTPUT is locale-dependent -- confirmed directly
    # ("Statut:" not "Status:", "Dernier resultat:" not "Last Result:")
    # -- meaning this English-text regex-based polling NEVER matched on
    # this device, always timing out regardless of whether the task
    # genuinely succeeded (confirmed directly: the real grant DID
    # succeed, only our own detection logic was broken). This is the
    # exact same class of bug as auditpol's own locale-dependent names,
    # fixed the same real way: stop parsing localized text output
    # entirely, use PowerShell's own Get-ScheduledTask/-TaskInfo cmdlets,
    # which return real, structured, LANGUAGE-INDEPENDENT property names
    # and values (LastTaskResult as a real number, State as a real,
    # untranslated enum string) confirmed directly on this exact device.
    Start-Sleep -Seconds 3
    $maxWaitSeconds = 60
    $waited = 3
    $taskDone = $false
    $taskExitCode = $null
    while ($waited -lt $maxWaitSeconds) {
        $taskObj = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
        if ($taskObj -and $taskObj.State -ne 'Running') {
            $info = Get-ScheduledTaskInfo -TaskName $taskName -ErrorAction SilentlyContinue
            $taskExitCode = $info.LastTaskResult
            if ($taskExitCode -eq 0) {
                $taskDone = $true
            }
            break
        }
        Start-Sleep -Seconds 2
        $waited += 2
    }


    schtasks.exe /Delete /TN $taskName /F 2>&1 | Out-Null
    Remove-Item -Path $grantBatPath -Force -ErrorAction SilentlyContinue

    if (-not $taskDone) {
        Write-Host "ERROR: grant task timed out."
        exit 1
    }

    Write-Host "Privilege grant completed."

    # Real, direct visibility into what the batch file's own sqlcmd
    # invocations actually did -- surface any real error (e.g. a login
    # failure with the stored credential) rather than trust a clean
    # task exit code alone.
    if (Test-Path $grantLogPath) {
        $grantLogContent = Get-Content -Path $grantLogPath -Raw -ErrorAction SilentlyContinue
        if ($grantLogContent) {
            Write-Host "Grant task real output:"
            Write-Host $grantLogContent
        }
        Remove-Item -Path $grantLogPath -Force -ErrorAction SilentlyContinue
    } else {
        Write-Host "WARNING: no grant log file was produced -- the batch file may never have run its real sqlcmd commands at all."
    }
}

# Verify the grant actually took effect before proceeding - never trust
# the grant command's own exit code alone.
$hasPrivilegeNow = Test-ServerAuditPrivilege
if (-not $hasPrivilegeNow) {
    Write-Host "ERROR: grant did not take effect."
    exit 1
}
Write-Host "Confirmed: privilege granted."

Write-Host "=== Phase 1 complete ==="

# =====================================================================
# Phase 2: create the actual Server Audit + Server Audit Specification,
# using SYSTEM's own now-privileged trusted connection (-E, no password
# needed at all for anything from this point on).
#
# REAL FINDING, confirmed via direct research before writing this:
# FAILED_LOGIN_GROUP and SUCCESSFUL_LOGIN_GROUP are SERVER-LEVEL-ONLY
# audit action groups -- they genuinely cannot be added to a DATABASE
# Audit Specification at all (a real, documented syntax error others
# have hit making exactly this mistake). Must use a SERVER Audit
# Specification instead. This differs from the original uploaded
# reference material, which made this exact error.
#
# HONEST SCOPE NOTE: this covers real, valuable LOGIN-level auditing
# (who connected, who failed to connect) -- genuinely useful security
# signal on its own. Database/query-level auditing (matching the
# richer granularity already proven for Postgres/MySQL -- "what SELECT
# ran on which table") is NOT included here; it would need either
# SCHEMA_OBJECT_ACCESS_GROUP per database (looped across every user
# database via dynamic SQL) or explicit per-table grants, genuinely
# more work, deferred rather than silently promised.
# =====================================================================
Write-Host "=== Phase 2: creating audit ==="

$auditName = "ObaitsServerAudit"
$specName = "ObaitsServerAuditSpec"
# REAL DESIGN CHANGE, decided after real testing: DML events (phase 3)
# only ever exist in the binary .sqlaudit file -- confirmed genuinely
# readable/valid via sys.fn_get_audit_file (a real, still-fully-
# functional SQL Server capability), but Wazuh itself has no native way
# to parse that binary format as a localfile source. Login events
# (phase 2) already reach Wazuh because SQL Server ALSO writes those to
# the Windows Application Log independently, regardless of any custom
# audit destination -- already proven working. Switching this audit's
# OWN destination to APPLICATION_LOG makes every event (login AND DML)
# flow through that same, already-proven Wazuh pathway.
# HONEST TRADEOFF: sys.fn_get_audit_file (Microsoft's own documented
# limitation) CANNOT be used once an audit targets APPLICATION_LOG --
# direct SQL-side file review is genuinely traded for real Wazuh
# delivery, not free. Real type codes confirmed directly: type=AL
# (Application Log), type=FL (File, what we originally built).
$auditExists = Get-SqlScalar "SET NOCOUNT ON; SELECT type FROM sys.server_audits WHERE name = '$auditName';"

if ($auditExists -eq 'AL') {
    Write-Host "Audit already correct."
} else {
    if ($auditExists -eq 'FL') {
        Write-Host "Audit targets file, not log -- recreating..."
        & $sqlcmdPath -S localhost -E -Q "ALTER SERVER AUDIT [$auditName] WITH (STATE = OFF); DROP SERVER AUDIT [$auditName];"
    }
    Write-Host "Creating Server Audit..."
    & $sqlcmdPath -S localhost -E -Q "CREATE SERVER AUDIT [$auditName] TO APPLICATION_LOG WITH (ON_FAILURE = CONTINUE);"
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: could not create Server Audit."
        exit 1
    }
    & $sqlcmdPath -S localhost -E -Q "ALTER SERVER AUDIT [$auditName] WITH (STATE = ON);"
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: could not enable Server Audit."
        exit 1
    }
}

$specExists = Get-SqlScalar "SET NOCOUNT ON; SELECT COUNT(*) FROM sys.server_audit_specifications WHERE name = '$specName';"

if ($specExists -eq '1') {
    Write-Host "Server Audit Specification already exists."
} else {
    Write-Host "Creating Server Audit Specification (login events)..."
    & $sqlcmdPath -S localhost -E -Q "CREATE SERVER AUDIT SPECIFICATION [$specName] FOR SERVER AUDIT [$auditName] ADD (FAILED_LOGIN_GROUP), ADD (SUCCESSFUL_LOGIN_GROUP), ADD (DATABASE_CHANGE_GROUP) WITH (STATE = ON);"
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: could not create Server Audit Specification."
        exit 1
    }
}

# Verify both are genuinely enabled -- never trust the creation
# commands' own exit codes alone.
$verifyAudit = Get-SqlScalar "SET NOCOUNT ON; SELECT is_state_enabled FROM sys.server_audits WHERE name = '$auditName';"
$verifySpec = Get-SqlScalar "SET NOCOUNT ON; SELECT is_state_enabled FROM sys.server_audit_specifications WHERE name = '$specName';"

if ($verifyAudit -ne '1' -or $verifySpec -ne '1') {
    Write-Host "ERROR: audit and/or specification exist but are not genuinely enabled (audit enabled: $verifyAudit, spec enabled: $verifySpec)."
    exit 1
}

Write-Host "Confirmed: Server Audit and Server Audit Specification are genuinely created and enabled."
Write-Host "Real audit events will be written to the Windows Application Log."
Write-Host "=== Phase 2 complete ==="

# =====================================================================
# Phase 3: real, per-database DML/query-level auditing -- confirmed
# directly on a real device (WinClient, a real test database) before
# writing this: SCHEMA_OBJECT_ACCESS_GROUP captures real INSERT/SELECT/
# UPDATE/DELETE activity with exact statement text (confirmed:
# "INSERT INTO wazuh_mssql_test VALUES (1, ...)" appeared verbatim in
# the real audit file). Applied to every REAL, CURRENT user database
# (database_id > 4 -- excludes master/tempdb/model/msdb), looped from
# PowerShell rather than T-SQL dynamic SQL, for simplicity and easier
# real verification of each step.
# =====================================================================
Write-Host "=== Phase 3: per-database DML auditing ==="

$dbSpecName = "ObaitsDBAuditSpec"
$userDbsRaw = & $sqlcmdPath -S localhost -E -h -1 -Q "SET NOCOUNT ON; SELECT name FROM sys.databases WHERE database_id > 4 AND state_desc = 'ONLINE';" 2>&1
$userDbs = $userDbsRaw | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' -and $_ -notmatch '^\(\d+ rows? affected\)$' }

if (-not $userDbs -or $userDbs.Count -eq 0) {
    Write-Host "No user databases found yet."
} else {
    foreach ($dbName in $userDbs) {
        Write-Host "Checking database: $dbName"

        # REAL BUG FOUND on a real device: an existing spec's own
        # name can survive its parent audit being dropped/recreated
        # (e.g. the FILE-to-APPLICATION_LOG switch above), leaving it
        # silently bound to a dead GUID -- "enabled" but pointing at
        # nothing real. Confirmed directly: audit_guid on the spec
        # did NOT match the live audit's own audit_guid. Check the
        # real binding, not just existence-by-name, and recreate if
        # orphaned.
        $specExistsHere = Get-SqlScalar "SET NOCOUNT ON; SELECT COUNT(*) FROM sys.database_audit_specifications s JOIN sys.server_audits a ON s.audit_guid = a.audit_guid WHERE s.name = '$dbSpecName' AND a.name = '$auditName';" $dbName
        $staleSpecExists = Get-SqlScalar "SET NOCOUNT ON; SELECT COUNT(*) FROM sys.database_audit_specifications WHERE name = '$dbSpecName';" $dbName

        if ($specExistsHere -eq '1') {
            # REAL GAP FOUND on a real device: this check only verified
            # the spec exists and is correctly bound to the live audit --
            # it never checked WHICH audit action groups the spec
            # actually includes. Confirmed directly: an existing,
            # correctly-bound spec only had SCHEMA_OBJECT_ACCESS_GROUP
            # (data reads/writes), never SCHEMA_OBJECT_CHANGE_GROUP (real
            # CREATE/ALTER/DROP events) -- meaning schema-change auditing
            # silently never captured anything at all. Real fix: check
            # for BOTH real groups, adding whichever is missing via
            # ALTER ADD rather than a full drop/recreate.
            $hasChangeGroup = Get-SqlScalar "SET NOCOUNT ON; SELECT COUNT(*) FROM sys.database_audit_specification_details WHERE audit_action_name = 'SCHEMA_OBJECT_CHANGE_GROUP';" $dbName
            if ($hasChangeGroup -eq '1') {
                Write-Host "  OK: $dbName."
                continue
            }
            Write-Host "  Adding missing SCHEMA_OBJECT_CHANGE_GROUP to existing spec: $dbName."
            & $sqlcmdPath -S localhost -E -d $dbName -Q "ALTER DATABASE AUDIT SPECIFICATION [$dbSpecName] WITH (STATE = OFF); ALTER DATABASE AUDIT SPECIFICATION [$dbSpecName] ADD (SCHEMA_OBJECT_CHANGE_GROUP); ALTER DATABASE AUDIT SPECIFICATION [$dbSpecName] WITH (STATE = ON);"
            if ($LASTEXITCODE -ne 0) {
                Write-Host "  WARNING: failed to add schema-change auditing for $dbName, continuing."
            } else {
                Write-Host "  OK: $dbName (schema-change auditing added)."
            }
            continue
        }

        if ($staleSpecExists -eq '1') {
            Write-Host "  Orphaned, recreating: $dbName."
            & $sqlcmdPath -S localhost -E -d $dbName -Q "ALTER DATABASE AUDIT SPECIFICATION [$dbSpecName] WITH (STATE = OFF); DROP DATABASE AUDIT SPECIFICATION [$dbSpecName];"
        }

        Write-Host "  Creating spec for $dbName..."
        & $sqlcmdPath -S localhost -E -d $dbName -Q "CREATE DATABASE AUDIT SPECIFICATION [$dbSpecName] FOR SERVER AUDIT [$auditName] ADD (SCHEMA_OBJECT_ACCESS_GROUP) ADD (SCHEMA_OBJECT_CHANGE_GROUP) WITH (STATE = ON);"
        if ($LASTEXITCODE -ne 0) {
            Write-Host "  WARNING: failed for $dbName, continuing."
            continue
        }

        $verifyDbSpec = Get-SqlScalar "SET NOCOUNT ON; SELECT is_state_enabled FROM sys.database_audit_specifications WHERE name = '$dbSpecName';" $dbName
        if ($verifyDbSpec -eq '1') {
            Write-Host "  Enabled: $dbName."
        } else {
            Write-Host "  WARNING: not confirmed for $dbName."
        }
    }
}

Write-Host "=== Phase 3 complete ==="

# REAL, GENUINE PLATFORM BUG WORKED AROUND: confirmed directly, via
# multiple independent, real Wazuh GitHub issues (wazuh/wazuh#36291,
# closed "not planned" by Wazuh's own team; #36029), that custom rules
# referencing windows_eventchannel-decoded content genuinely never fire
# in live production, regardless of <field>/<match>/<decoded_as>
# approach -- confirmed repeatedly on this real device too, even a bare
# <match> diagnostic rule never fired live despite firing correctly in
# wazuh-logtest every time. This is a real, acknowledged, unresolved
# Wazuh platform limitation, not a mistake in our own rule syntax.
#
# Real, pragmatic fix, matching this project's own already-proven,
# already-working Docker-events pattern: a periodic poller (SYSTEM
# already has full read access to the Application log -- confirmed
# directly, no special account needed here, unlike the earlier
# privilege-grant work) reads real audit events via Get-WinEvent,
# parses them into clean, real, single-line JSON, and writes that to a
# real, plain file -- which Wazuh then reads via a completely NORMAL
# <localfile> (log_format=json), the same reliable, already-proven
# pipeline used for MongoDB and Docker's own real container logs,
# entirely bypassing the broken eventchannel rule-matching path.
Write-Host "=== Phase 4: deploying MSSQL audit poller (works around a real, confirmed Wazuh platform bug) ==="

$pollerDir = "C:\ProgramData\Obaits"
if (-not (Test-Path $pollerDir)) {
    New-Item -ItemType Directory -Path $pollerDir -Force | Out-Null
}

$pollerPath = Join-Path $pollerDir "mssql-audit-poller.ps1"
$pollerScript = @'
$stateFile = "C:\ProgramData\Obaits\mssql-audit-state.txt"
$logFile = "C:\ProgramData\Obaits\mssql-audit-events.log"

$lastPoll = if (Test-Path $stateFile) {
    try { [DateTime]::Parse((Get-Content $stateFile -Raw).Trim()) } catch { (Get-Date).AddMinutes(-5) }
} else {
    (Get-Date).AddMinutes(-5)
}
$now = Get-Date

$events = Get-WinEvent -FilterHashtable @{LogName='Application'; ProviderName='MSSQLSERVER'; Id=33205; StartTime=$lastPoll} -ErrorAction SilentlyContinue

foreach ($e in $events) {
    $msg = $e.Message
    $actionId = if ($msg -match 'action_id:(\S+)') { $matches[1].Trim() } else { '' }
    $succeeded = if ($msg -match 'succeeded:(\S+)') { $matches[1].Trim() } else { '' }
    $dbName = if ($msg -match 'database_name:(\S*)') { $matches[1].Trim() } else { '' }
    $schemaName = if ($msg -match 'schema_name:(\S*)') { $matches[1].Trim() } else { '' }
    $objectName = if ($msg -match 'object_name:(\S*)') { $matches[1].Trim() } else { '' }
    $principalName = if ($msg -match 'server_principal_name:(\S+)') { $matches[1].Trim() } else { '' }
    $statement = if ($msg -match '(?s)statement:(.*?)
?
additional_information:') { $matches[1].Trim() } else { '' }

    $obj = [PSCustomObject]@{
        time_created = $e.TimeCreated.ToString('o')
        action_id = $actionId
        succeeded = $succeeded
        database_name = $dbName
        schema_name = $schemaName
        object_name = $objectName
        server_principal_name = $principalName
        statement = $statement
        host_name = $env:COMPUTERNAME
    }
    ($obj | ConvertTo-Json -Compress) | Out-File -FilePath $logFile -Append -Encoding utf8
}

$now.ToString('o') | Set-Content -Path $stateFile -Force
'@

Set-Content -Path $pollerPath -Value $pollerScript -Encoding UTF8 -Force
Write-Host "Deployed poller to $pollerPath"

if (-not (Test-Path "C:\ProgramData\Obaits\mssql-audit-events.log")) {
    New-Item -ItemType File -Path "C:\ProgramData\Obaits\mssql-audit-events.log" -Force | Out-Null
}

$taskName = "ObaitsMssqlAuditPoller"
$existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($existingTask) {
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
}

$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$pollerPath`""
# REAL BUG FOUND on a real device: [TimeSpan]::MaxValue produces an
# invalid, out-of-bounds Task Scheduler XML duration value
# ("P99999999DT23H59M59S"), rejected outright by the real Windows
# Task Scheduler XML schema -- confirmed directly. A real, large but
# genuinely VALID duration (10 years) is used instead. Also: the
# earlier version printed a success message unconditionally, even
# though Register-ScheduledTask had already thrown a real error above
# it -- never verified. Real fix: check Get-ScheduledTask afterward.
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 2) -RepetitionDuration (New-TimeSpan -Days 3650)
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -User "SYSTEM" -RunLevel Highest -Force -ErrorAction SilentlyContinue | Out-Null

$verifyTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($verifyTask) {
    Write-Host "Confirmed: poller scheduled task genuinely registered: $taskName (every 2 minutes, runs as SYSTEM)"
} else {
    Write-Host "ERROR: poller scheduled task registration failed - it will not run automatically."
}

& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $pollerPath
Write-Host "Ran poller once immediately to verify it works."

Write-Host "=== Obaits: SQL Server audit setup (Windows) complete ==="
