<?php

namespace App\Support\Backup;

use App\Clients\FleetClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Runs shell commands on a tenant's enrolled device via Fleet Scripts.
 *
 * ISOLATION: same discipline as FleetFolderBrowser — the caller must resolve
 * the tenant's own hosts before ever passing a host id here. This class does
 * not re-check ownership itself; callers (BackupJobRunner etc.) must.
 *
 * CONFIRMED EMPIRICALLY 2026-07-25 against Fleet 4.88.0:
 * - POST /scripts/run is async: returns 202 + execution_id immediately.
 * - GET /scripts/results/{execution_id} is the correct poll target.
 *   exit_code is null while pending; becomes an int once the host reports.
 * - CORRECTION 2026-08-23, confirmed empirically against this same Fleet
 *   4.88.0: the 409 behaviour described immediately below does NOT reliably
 *   occur. Fleet instead QUEUES a second script for a busy host and returns
 *   202 with an execution id that stays pending until its turn. The
 *   'host_busy' path in launch() therefore provides NO backpressure. A real
 *   240-item backlog built up across 3 hosts this way, the oldest queued five
 *   days, starving genuine Group Policy checks. To check whether a host is
 *   genuinely free, read its own queue:
 *   GET /api/v1/fleet/hosts/{id}/activities/upcoming
 *   Deleting a queued item: DELETE on that same path + /{execution_id}.
 * - Fleet SERIALISES script execution PER HOST — only one script may be
 *   "in flight" on a given host at a time. A second POST /scripts/run against
 *   a host with something already running returns 409 "A script is already
 *   running on this host. Please wait about 5 minutes to let it finish."
 *   The 5-minute figure is Fleet's own stated ceiling, not something we
 *   should treat as a design target — a normal script (including a real
 *   restic backup) may need much longer, and this class does not enforce
 *   any particular runtime; it only polls until done or its own timeout.
 * - A device that goes briefly offline mid-execution does not lose the
 *   script: results land whenever the host next reports back. Confirmed
 *   directly — a script queued during a ~10 minute network outage on the
 *   test host still returned exit_code 0 with correct output once
 *   connectivity returned. Do not treat "no response yet" as failure.
 * - requires the fleetd agent enrolled with --enable-scripts (Fleet returns
 *   422 "deploy the fleetd agent with --enable-scripts" otherwise), AND that
 *   flag must be live on a FRESH orbit enrollment — an in-place package
 *   upgrade over an existing install does not renegotiate it (see
 *   install.sh's unconditional identity-clear, added 2026-07-25 for exactly
 *   this reason).
 */
class FleetScriptRunner
{
    /** How often to poll while waiting for a result. */
    public const POLL_INTERVAL_SECONDS = 3;

    /**
     * Launch a script on one host. Returns the execution id immediately —
     * does not wait for completion. Callers that need synchronous behaviour
     * should use runAndWait() instead.
     *
     * Returns ['ok' => bool, 'execution_id' => ?string, 'error' => ?string]
     */
    public static function launch(int $fleetHostId, string $scriptContents, ?string $actor = null, ?string $tenantId = null): array
    {
        $fleet = app(FleetClient::class);
        $r = new \ReflectionClass($fleet);
        $tokM = $r->getMethod('token'); $tokM->setAccessible(true);
        $urlM = $r->getMethod('baseUrl'); $urlM->setAccessible(true);
        $token = $tokM->invoke($fleet);
        $base  = $urlM->invoke($fleet);

        if ($tenantId) {
            \App\Support\Audit::log($tenantId, $actor, 'backup.script_run', 'launch', 'fleet', [
                'host_id' => $fleetHostId,
            ]);
        }

        $res = Http::withToken($token)->withoutVerifying()->timeout(30)->asJson()
            ->post($base . '/api/v1/fleet/scripts/run', [
                'host_id'         => $fleetHostId,
                'script_contents' => $scriptContents,
            ]);

        if ($res->status() === 409) {
            // Another script is already running on this host. This is a real,
            // expected condition (Fleet serialises per host) — surface it
            // distinctly so the caller can retry later rather than treating
            // it as a hard failure.
            return ['ok' => false, 'execution_id' => null, 'error' => 'host_busy'];
        }

        if ($res->status() !== 202) {
            return ['ok' => false, 'execution_id' => null, 'error' => 'fleet_http_' . $res->status() . ': ' . substr($res->body(), 0, 300)];
        }

        $execId = $res->json('execution_id');
        if (!$execId) {
            return ['ok' => false, 'execution_id' => null, 'error' => 'no_execution_id'];
        }

        return ['ok' => true, 'execution_id' => $execId, 'error' => null];
    }

    /**
     * Poll once for a result. exit_code is null while still pending —
     * this is normal and expected, not a failure.
     *
     * Returns ['pending' => bool, 'exit_code' => ?int, 'output' => string,
     *          'error' => ?string]
     */
    public static function poll(string $executionId): array
    {
        $fleet = app(FleetClient::class);
        $r = new \ReflectionClass($fleet);
        $tokM = $r->getMethod('token'); $tokM->setAccessible(true);
        $urlM = $r->getMethod('baseUrl'); $urlM->setAccessible(true);
        $token = $tokM->invoke($fleet);
        $base  = $urlM->invoke($fleet);

        $res = Http::withToken($token)->withoutVerifying()->timeout(30)
            ->get($base . '/api/v1/fleet/scripts/results/' . $executionId);

        if (!$res->successful()) {
            return ['pending' => false, 'exit_code' => null, 'output' => '', 'error' => 'fleet_http_' . $res->status()];
        }

        $exitCode = $res->json('exit_code');

        return [
            'pending'  => $exitCode === null,
            'exit_code' => $exitCode,
            'output'   => $res->json('output') ?? '',
            'error'    => null,
        ];
    }

    /**
     * Launch and block (this PHP process) until completion or timeout.
     *
     * Intended for short, fast commands (e.g. checking whether restic is
     * installed) called from a queued job that can afford to block — NOT
     * from a web request. A real backup run should use launch() + poll()
     * from a queued job's own loop instead, so a slow host does not tie up
     * a worker for the job's full runtime, and so the job can be resumed
     * across worker restarts by execution_id.
     *
     * Returns ['ok' => bool, 'exit_code' => ?int, 'output' => string, 'error' => ?string]
     */
    public static function runAndWait(int $fleetHostId, string $scriptContents, int $timeoutSeconds = 60, ?string $actor = null, ?string $tenantId = null): array
    {
        $launched = self::launch($fleetHostId, $scriptContents, $actor, $tenantId);
        if (!$launched['ok']) {
            return ['ok' => false, 'exit_code' => null, 'output' => '', 'error' => $launched['error']];
        }

        $execId = $launched['execution_id'];
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            sleep(self::POLL_INTERVAL_SECONDS);
            $result = self::poll($execId);

            if ($result['error']) {
                return ['ok' => false, 'exit_code' => null, 'output' => '', 'error' => $result['error']];
            }
            if (!$result['pending']) {
                return ['ok' => true, 'exit_code' => $result['exit_code'], 'output' => $result['output'], 'error' => null];
            }
        }

        return ['ok' => false, 'exit_code' => null, 'output' => '', 'error' => 'timeout', 'execution_id' => $execId];
    }
}
