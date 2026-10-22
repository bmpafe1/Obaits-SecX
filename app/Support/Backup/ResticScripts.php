<?php

namespace App\Support\Backup;

/**
 * Shell command bodies executed on a tenant's device via FleetScriptRunner.
 *
 * DESIGN CONSTRAINT (confirmed empirically 2026-07-25 against Fleet 4.88.0):
 * script_contents is capped at 10,000 characters, script OUTPUT is silently
 * truncated to the last 10,000 characters, the default script timeout is
 * 300s (raised to 1800s globally, but still a real ceiling), and Fleet
 * allows only ONE script in flight per host at a time — a second script
 * against a busy host is refused outright (409), not queued in parallel.
 *
 * A real restic backup can run far longer than any of these limits allow if
 * run in the foreground. So every script here starts restic DETACHED
 * (setsid + nohup, output to a local log file) and returns within seconds,
 * releasing Fleet's per-host lock immediately. A SEPARATE poll script checks
 * whether the detached process has finished and reads the tail of the log —
 * never the live stdout of a long-running restic invocation.
 */
class ResticScripts
{
    private const CREDS_DIR = '/etc/obaits-backup';
    private const RUN_DIR   = '/opt/obaits-backup';   // NOT /var/run — often mounted noexec (confirmed 2026-07-25)

    /**
     * Fetch (or refresh) this device's restic credentials file. Idempotent —
     * safe to run before every backup/restore in case the file was never
     * written or the device was re-imaged.
     */
    private static function fetchCredentials(string $portalUrl): string
    {
        $portalUrl = rtrim($portalUrl, '/');
        $credsDir  = self::CREDS_DIR;

        return <<<SH
mkdir -p {$credsDir}
ENROLL_SECRET="\$(sed -n 's/^ORBIT_ENROLL_SECRET=//p' /etc/default/orbit | head -1)"
if [ -z "\$ENROLL_SECRET" ]; then echo "OBAITS_ERROR: no enroll secret found"; exit 90; fi
HTTP_CODE="\$(curl -sS -o {$credsDir}/restic.env -w '%{http_code}' \\
  -H "X-Obaits-Enroll-Secret: \$ENROLL_SECRET" \\
  "{$portalUrl}/enroll/backup-credentials")"
if [ "\$HTTP_CODE" != "200" ]; then echo "OBAITS_ERROR: credentials fetch failed (HTTP \$HTTP_CODE)"; exit 91; fi
chmod 600 {$credsDir}/restic.env
SH;
    }

    /**
     * Launch a backup run in the background. Returns almost immediately —
     * the caller polls separately for completion (see poll()).
     *
     * $folderPaths: absolute paths already validated as belonging to this
     * tenant/host (see FleetFolderBrowser's isolation discipline — this
     * class trusts its caller completely and does no sanitisation itself).
     */
    public static function launchBackup(string $portalUrl, array $folderPaths, string $runId): string
    {
        $paths    = implode(' ', array_map(fn ($p) => escapeshellarg($p), $folderPaths));
        $fetch    = self::fetchCredentials($portalUrl);
        $credsDir = self::CREDS_DIR;
        $runDir   = self::RUN_DIR;

        return <<<SH
set -e
{$fetch}
mkdir -p {$runDir}
. {$credsDir}/restic.env
export RESTIC_REPOSITORY RESTIC_PASSWORD AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY

restic snapshots >/dev/null 2>&1 || restic init >/tmp/obaits-restic-init-{$runId}.log 2>&1 || true

cat > {$runDir}/{$runId}.sh <<'INNEREOF'
restic backup {$paths} --tag obaits-run-{$runId} --json > {$runDir}/{$runId}.log 2>&1
echo \$? > {$runDir}/{$runId}.exitcode
INNEREOF
chmod +x {$runDir}/{$runId}.sh
setsid nohup {$runDir}/{$runId}.sh > /dev/null 2>&1 < /dev/null &
echo \$! > {$runDir}/{$runId}.pid
echo "OBAITS_LAUNCHED pid=\$(cat {$runDir}/{$runId}.pid)"
SH;
    }

    /**
     * Check whether a previously-launched run has finished. Fast and safe
     * to call repeatedly — reads a PID and a log tail, does not touch the
     * network beyond what restic itself already did in the background.
     *
     * Output convention (parsed by BackupJobRunner):
     *   OBAITS_STILL_RUNNING           — process alive, come back later
     *   OBAITS_DONE exit_code=N        — process finished, N is restic's exit code
     *   followed by the last 4000 chars of the log
     */
    public static function poll(string $runId): string
    {
        $runDir = self::RUN_DIR;

        return <<<SH
PIDFILE="{$runDir}/{$runId}.pid"
LOGFILE="{$runDir}/{$runId}.log"
if [ ! -f "\$PIDFILE" ]; then echo "OBAITS_ERROR: no such run"; exit 92; fi
PID="\$(cat "\$PIDFILE")"
EXITFILE="{$runDir}/{$runId}.exitcode"
if kill -0 "\$PID" 2>/dev/null; then
  echo "OBAITS_STILL_RUNNING"
elif [ -f "\$EXITFILE" ]; then
  EXIT_CODE="\$(cat "\$EXITFILE")"
  echo "OBAITS_DONE exit_code=\$EXIT_CODE"
  tail -c 4000 "\$LOGFILE"
else
  echo "OBAITS_DONE exit_code=unknown"
  tail -c 4000 "\$LOGFILE"
fi
SH;
    }

    /**
     * Launch a restore in the background, mirroring launchBackup() exactly —
     * same daemonization pattern, same file-based exit-code capture, same
     * poll() reads its result. Added 2026-07-25 after restoreForeground()
     * was confirmed correct in isolation but proved dangerous in the web
     * path: a real Livewire request blocked on it and was killed by the web
     * server's own timeout (well under restic's potential runtime) before
     * ever reaching the code that reports success or failure to the tenant.
     */
    public static function launchRestore(string $portalUrl, string $snapshotId, string $destinationPath, string $runId): string
    {
        $fetch    = self::fetchCredentials($portalUrl);
        $credsDir = self::CREDS_DIR;
        $runDir   = self::RUN_DIR;
        $dest     = escapeshellarg($destinationPath);
        $snap     = escapeshellarg($snapshotId);

        return <<<SH
set -e
{$fetch}
mkdir -p {$runDir}
. {$credsDir}/restic.env
export RESTIC_REPOSITORY RESTIC_PASSWORD AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
mkdir -p {$dest}
cat > {$runDir}/{$runId}.sh <<'INNEREOF'
restic restore {$snap} --target {$dest} --json > {$runDir}/{$runId}.log 2>&1
echo \$? > {$runDir}/{$runId}.exitcode
INNEREOF
chmod +x {$runDir}/{$runId}.sh
setsid nohup {$runDir}/{$runId}.sh > /dev/null 2>&1 < /dev/null &
echo \$! > {$runDir}/{$runId}.pid
echo "OBAITS_LAUNCHED pid=\$(cat {$runDir}/{$runId}.pid)"
SH;
    }

    /**
     * Restore one snapshot into a destination path. Foreground is
     * acceptable here ONLY if restores are expected to be short relative to
     * the script timeout — for v1 this is a known simplification, revisit
     * with the same launch/poll pattern if restores of meaningful size prove
     * this wrong in practice.
     */
    public static function restoreForeground(string $portalUrl, string $snapshotId, string $destinationPath): string
    {
        $fetch    = self::fetchCredentials($portalUrl);
        $credsDir = self::CREDS_DIR;
        $dest     = escapeshellarg($destinationPath);
        $snap     = escapeshellarg($snapshotId);

        return <<<SH
set -e
{$fetch}
. {$credsDir}/restic.env
export RESTIC_REPOSITORY RESTIC_PASSWORD AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
mkdir -p {$dest}
restic restore {$snap} --target {$dest} --json
SH;
    }
}
