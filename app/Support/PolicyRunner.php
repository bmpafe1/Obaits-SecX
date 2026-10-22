<?php
namespace App\Support;

use App\Support\Backup\FleetScriptRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * check/remediate/revert, all sharing one internal launcher. Enforce mode
 * is now real: remediateDevice() can change a device (Firewall Enable
 * Policy only, so far -- turn the firewall on if it was off, no-op if
 * already on). revertDevice() only ever undoes what OUR OWN remediation
 * did, using the run's own captured prior_state -- never a blind inverse
 * script, since a device that already had its firewall on before we
 * touched it must never be turned off by a revert.
 *
 * Same trigger-fetch-execute pattern throughout (Fleet's script_contents
 * corrupts backticks/non-ASCII and caps at 10,000 chars -- confirmed
 * directly elsewhere in this project), same host_busy-is-a-deferral
 * handling, same warn-on-empty-fetch error checking.
 */
class PolicyRunner
{
    /**
     * REAL BUG FOUND 2026-08-07: Fleet's own 'platform' field reports the
     * real OS/distro name -- 'ubuntu', 'linuxmint', etc -- NOT a clean
     * windows/linux binary. Confirmed directly against this tenant's real
     * hosts: 'ubuntu' x3, 'linuxmint' x1. Every earlier manual/tinker test
     * of this feature typed the literal string 'linux' by hand and so
     * never actually exercised this path -- the UI's real platformMap()
     * calls were silently no-op'ing on check_script_ubuntu (a column that
     * doesn't exist) while looking completely successful (200 OK, no
     * exception, mode flipped, nothing else changed). This is the ONE
     * place platform strings should be normalised -- callers must use
     * this rather than reading Fleet's raw field directly.
     */
    public static function normalizePlatform(string $rawPlatform): string
    {
        $p = strtolower(trim($rawPlatform));
        if ($p === 'windows' || str_starts_with($p, 'windows')) {
            return 'windows';
        }
        // Everything else Fleet reports for a *nix host is a real distro
        // name (ubuntu, linuxmint, debian, rhel, darwin/macos, etc) --
        // this policy engine has only ever built Linux scripts so far,
        // so anything non-Windows maps to 'linux' for now. A genuine
        // macOS-specific script would need its own branch when it exists;
        // deliberately not guessed at here.
        return 'linux';
    }

    public static function checkDevice(string $assignmentId, int $fleetHostId, string $hostname, string $platform, ?string $actor = null): array
    {
        return self::run($assignmentId, $fleetHostId, $hostname, $platform, 'check', $actor);
    }

    public static function remediateDevice(string $assignmentId, int $fleetHostId, string $hostname, string $platform, ?string $actor = null): array
    {
        return self::run($assignmentId, $fleetHostId, $hostname, $platform, 'remediate', $actor);
    }

    public static function revertDevice(string $assignmentId, int $fleetHostId, string $hostname, string $platform, ?string $actor = null): array
    {
        return self::run($assignmentId, $fleetHostId, $hostname, $platform, 'revert', $actor);
    }

    private static function run(string $assignmentId, int $fleetHostId, string $hostname, string $platform, string $phase, ?string $actor): array
    {
        $assignment = DB::table('policy_assignments')->where('id', $assignmentId)->first();
        if (!$assignment) return ['ok' => false, 'error' => 'assignment_not_found'];

        // REAL FEATURE 2026-08-13: the kill switch. Checked here, in the
        // ONE shared internal launcher every public method funnels
        // through -- guaranteed to apply no matter which caller
        // triggers a device-changing action (UI, the reconciler, tinker,
        // or anything built later), unlike the four separate call-site
        // guards exclusions needed earlier today. CHECK is deliberately
        // exempt -- it's read-only, zero risk, and a paused tenant still
        // benefits from seeing accurate compliance data while enforcement
        // itself is suspended.
        if ($phase !== 'check') {
            $tenant = DB::table('tenants')->where('id', $assignment->tenant_id)->first();
            if ($tenant && $tenant->policy_enforcement_paused_at) {
                return ['ok' => false, 'error' => 'tenant_enforcement_paused'];
            }
        }


        $policy = DB::table('policies')->where('id', $assignment->policy_id)->first();
        if (!$policy) return ['ok' => false, 'error' => 'policy_not_found'];

        $scriptCol = $phase . '_script_' . $platform; // e.g. remediate_script_linux
        $script = $policy->{$scriptCol} ?? null;
        if (!$script) return ['ok' => false, 'error' => 'no_script_for_phase_platform'];

        // Same run row is reused across phases for a (assignment, host)
        // pair -- check writes it first, remediate/revert update it in
        // place, so history (attempts, created_at) survives across the
        // whole lifecycle of one device under one assignment.
        $existing = DB::table('policy_runs')
            ->where('policy_assignment_id', $assignmentId)
            ->where('fleet_host_id', $fleetHostId)
            ->first();

        $runId = $existing->id ?? (string) Str::uuid();

        // Prior state is captured ONCE, on the first ever remediate for
        // this pair, from that run's own most recent check result --
        // never overwritten again, so a later re-remediate cycle can't
        // lose the real "what was this before we ever touched it" answer.
        $priorState = null;
        if ($phase === 'remediate') {
            $priorState = $existing->prior_state ?? $existing->result ?? null;
        } elseif ($phase === 'revert') {
            $priorState = $existing->prior_state ?? null;
        }

        if (!$existing) {
            DB::table('policy_runs')->insert([
                'id' => $runId,
                'tenant_id' => $assignment->tenant_id,
                'policy_assignment_id' => $assignmentId,
                'fleet_host_id' => $fleetHostId,
                'hostname' => $hostname,
                'platform' => $platform,
                'phase' => $phase,
                'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            // REAL FIX 2026-08-23, found during a real, live
            // investigation: reusing this row for a fresh check/
            // remediate/revert never cleared the PREVIOUS run's own
            // real finished_at/result/output -- meaning stale data from
            // an old, completed run stayed visible in the UI
            // throughout the new run's real, in-progress window,
            // making an ordinary, working re-check look confusingly
            // "stuck" even when nothing was actually wrong.
            DB::table('policy_runs')->where('id', $runId)->update([
                'phase' => $phase,
                'status' => 'pending',
                'platform' => $platform,
                'prior_state' => $phase === 'remediate' && !$existing->prior_state ? $priorState : $existing->prior_state,
                'result' => null,
                'output' => null,
                'finished_at' => null,
                'updated_at' => now(),
            ]);
        }

        $scriptToken = bin2hex(random_bytes(32));
        DB::table('policy_script_tokens')->insert([
            'id' => (string) Str::uuid(),
            'token' => $scriptToken,
            'tenant_id' => $assignment->tenant_id,
            'policy_run_id' => $runId,
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $portalUrl = config('app.url');
        $priorStateArg = $priorState ?? '';

        if ($platform === 'windows') {
            $trigger = "\$t = '{$scriptToken}'; \$u = '{$portalUrl}'; \$runId = '{$runId}'; \$priorState = '{$priorStateArg}'\n"
                . "try {\n"
                . "  \$s = (Invoke-WebRequest -Uri \"\$u/enroll/policy-script\" -Method POST -Body @{ token = \$t } -UseBasicParsing).Content\n"
                . "} catch {\n"
                . "  Write-Host \"ERROR: could not fetch the policy script: \$(\$_.Exception.Message)\"\n"
                . "  exit 1\n"
                . "}\n"
                . "if (-not \$s) {\n"
                . "  Write-Host \"ERROR: policy script fetch returned empty content.\"\n"
                . "  exit 1\n"
                . "}\n"
                . "Invoke-Expression \$s\n";
        } else {
            $trigger = "#!/bin/bash\n"
                . "T='{$scriptToken}'\n"
                . "U='{$portalUrl}'\n"
                . "RUN_ID='{$runId}'\n"
                . "PRIOR_STATE='{$priorStateArg}'\n"
                // REAL BUG FOUND 2026-08-17, confirmed live: plain "curl -sS"
                // does NOT treat an HTTP error status (403/404/410, all
                // real cases this route already returns for an invalid,
                // used, or expired token) as a failure -- it happily
                // returns Laravel's own real HTML error page as a
                // "successful" response, non-empty, curl exit 0. This
                // trigger then evaled that raw HTML as shell script,
                // confirmed live: "eval: line 16: syntax error near
                // unexpected token 'newline'" / "`<!DOCTYPE html>'".
                // Genuinely reproducible any time a token's real 15-
                // minute window expires before the device gets around
                // to running it -- confirmed this can happen for real,
                // not just hypothetically, during a period of real
                // device contention. -f makes curl itself fail (real,
                // non-zero exit) on any HTTP error status, so the
                // EXISTING exit-code check below now genuinely catches
                // this class of failure instead of silently eval-ing
                // garbage.
                . "S=\$(curl -sSf -X POST \"\$U/enroll/policy-script\" -d \"token=\$T\")\n"
                . "CURL_EXIT=\$?\n"
                . "if [ \$CURL_EXIT -ne 0 ]; then\n"
                . "  echo \"ERROR: could not fetch the policy script (curl exit code \$CURL_EXIT -- likely an expired/invalid token, or a real HTTP error).\"\n"
                . "  exit 1\n"
                . "fi\n"
                . "if [ -z \"\$S\" ]; then\n"
                . "  echo \"ERROR: policy script fetch returned empty content.\"\n"
                . "  exit 1\n"
                . "fi\n"
                . "eval \"\$S\"\n";
        }

        $launched = FleetScriptRunner::launch($fleetHostId, $trigger, $actor, $assignment->tenant_id);

        $isBusy = !$launched['ok'] && ($launched['error'] ?? '') === 'host_busy';

        DB::table('policy_runs')->where('id', $runId)->update([
            'status' => $launched['ok'] ? 'running' : ($isBusy ? 'pending' : 'failed'),
            'fleet_execution_id' => $launched['execution_id'] ?? null,
            'attempts' => DB::raw($isBusy ? 'attempts' : 'attempts + 1'),
            'output' => $launched['ok'] ? null : ($isBusy ? 'Deferred: host busy, will retry.' : 'Launch failed: ' . $launched['error']),
            'updated_at' => now(),
        ]);

        return $launched;
    }
}
