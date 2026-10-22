<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Support\Health\OperatorAlert;

/**
 * Report background jobs that failed since the last run.
 *
 * A scheduled scan failed at 02:00 because the scanner host was unreachable.
 * It landed in failed_jobs and nothing said so. In production that silence
 * means a tenant believes they are being scanned when they are not — a scan
 * that did not happen looks exactly like a scan that found nothing.
 *
 * The health monitor watches services; this watches the work.
 */
#[AsCommand(name: 'jobs:alert-failures', description: 'Alert on background jobs that have failed')]
class AlertFailedJobs extends Command
{
    protected $signature = 'jobs:alert-failures {--dry-run}';
    protected $description = 'Alert on background jobs that have failed';

    private const WATERMARK = 'jobs.alert-failures.watermark';

    public function handle(): int
    {
        $dry  = (bool) $this->option('dry-run');
        $mark = (int) Cache::get(self::WATERMARK, -1);
        $maxId = (int) (DB::table('failed_jobs')->max('id') ?? 0);

        // First run: adopt the current position without alerting. Otherwise every
        // historical failure — all of them already understood — arrives at once,
        // and the first thing this feature ever does is cry wolf.
        if ($mark === -1) {
            if (!$dry) Cache::forever(self::WATERMARK, $maxId);
            $this->info("first run — watermark set to {$maxId}, no alerts sent");
            return 0;
        }

        // The table was cleared or shrank. Re-adopt rather than replaying.
        if ($maxId < $mark) {
            if (!$dry) Cache::forever(self::WATERMARK, $maxId);
            $this->info('failed_jobs was cleared — watermark reset');
            return 0;
        }

        $new = DB::table('failed_jobs')->where('id', '>', $mark)->orderBy('id')->get();

        if ($new->isEmpty()) {
            $this->info('no new failures');
            return 0;
        }

        // Group by job class. Ten failures of one job is one problem, not ten.
        $byClass = [];
        foreach ($new as $row) {
            $payload = json_decode($row->payload, true) ?: [];
            $class   = $payload['displayName'] ?? 'unknown job';
            $byClass[$class][] = $row;
        }

        $problems = [];

        foreach ($byClass as $class => $rows) {
            // How often has this class failed lately? A job failing every night
            // is a different problem from one that failed once, and an operator
            // needs to tell them apart from the alert alone.
            $recent = DB::table('failed_jobs')
                ->where('payload', 'like', '%"displayName":"' . str_replace('\\', '\\\\', $class) . '"%')
                ->where('failed_at', '>', now()->subDays(7))
                ->count();

            $first  = $rows[0];
            $reason = trim(explode("\n", (string) $first->exception)[0]);
            $reason = mb_strimwidth($reason, 0, 200, '…');

            $shortClass = class_basename(str_replace('\\', '/', $class));

            $problems[] = [
                'level'   => $recent >= 3 ? 'critical' : 'warning',
                'area'    => 'background jobs',
                'message' => $shortClass . ': ' . count($rows) . ' new failure(s)'
                           . ($recent >= 3 ? ' — ' . $recent . ' in the last 7 days, this is recurring' : '')
                           . '. ' . $reason,
            ];

            $this->warn("[{$shortClass}] " . count($rows) . " new, {$recent} in 7 days");
        }

        if (!$dry) {
            OperatorAlert::send(
                count($new) . ' background job failure(s)',
                $problems
            );
            Cache::forever(self::WATERMARK, $maxId);
        }

        $this->info(($dry ? '[dry-run] ' : '') . 'reported ' . count($new) . ' failure(s) across ' . count($byClass) . ' job type(s)');
        return 0;
    }
}