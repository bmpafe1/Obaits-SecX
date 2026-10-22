<?php

namespace App\Livewire\Portal;

use App\Support\Backup\BackupConnections;
use App\Support\Backup\BackupJobRunner;
use App\Support\Backup\BackupProvisioner;
use App\Support\Backup\FleetFolderBrowser;
use App\Support\Backup\FleetScriptRunner;
use App\Support\Backup\Providers\IDriveE2Provider;
use App\Support\Backup\ResticScripts;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.portal')]
class Backups extends Component
{
    public string $tenantId = '';
    public ?string $connectionId = null;

    public array $hosts = [];
    public ?int $selectedHostId = null;

    // path => [ ['path','name'], ... ] — one level at a time, per
    // FleetFolderBrowser's design (osquery's file table does not recurse).
    public array $folderCache = [];
    public array $expandedPaths = [];

    public array $selections = [];   // rows from backup_selections for this connection
    public ?array $status = null;    // IDrive status() snapshot — quota/usage, "as of" this call
    public array $runs = [];         // recent backup_runs, newest first
    public array $restores = [];    // recent backup_restores, newest first
    public array $plans = [];       // backup_plans, for the upgrade section
    public string $currentSchedule = 'daily';

    public ?string $notice = null;
    public bool $noticeOk = true;

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); return; }
        $this->refreshState();
    }

    public function refreshState(): void
    {
        $conn = BackupConnections::activeFor($this->tenantId, BackupProvisioner::PROVIDER);
        $this->connectionId = $conn->id ?? null;

        if ($this->connectionId) {
            $this->hosts = FleetFolderBrowser::hosts($this->tenantId);

            $this->selections = DB::table('backup_selections')
                ->where('connection_id', $this->connectionId)
                ->orderBy('folder_path')
                ->get()->toArray();

            try {
                $creds = BackupConnections::credentials($this->tenantId, $this->connectionId);
                // Storage figures lag IDrive's own reporting by up to 24h —
                // the view labels this explicitly, never as live.
                $this->status = (new IDriveE2Provider())->status($creds);
            } catch (\Throwable $e) {
                $this->status = null;
            }

            $jobId = DB::table('backup_jobs')->where('connection_id', $this->connectionId)->value('id');
            $this->currentSchedule = DB::table('backup_jobs')
                ->where('connection_id', $this->connectionId)->value('schedule') ?? 'daily';
            if ($jobId) {
                $this->runs = DB::table('backup_runs')
                    ->where('job_id', $jobId)
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->get()->toArray();
            } else {
                $this->runs = [];
            }

            $this->restores = DB::table('backup_restores')
                ->where('tenant_id', $this->tenantId)
                ->orderByDesc('created_at')->limit(5)->get()->toArray();
        } else {
            $this->hosts = [];
            $this->selections = [];
            $this->status = null;
            $this->runs = [];
            $this->restores = [];
        }

        $this->plans = DB::table('backup_plans')
            ->where('active', true)->orderBy('gb_size')->get()->toArray();
    }

    /**
     * No payment integration exists yet (deliberately deferred — see
     * memory log). This is a real, working bridge: it records the intent
     * and alerts an operator to invoice and provision manually, rather than
     * showing a purchase button that silently does nothing.
     */
    /**
     * How often this connection's backup job runs automatically. Manual
     * "Back up now" is unaffected by this setting.
     */
    public function setSchedule(string $schedule)
    {
        if (!in_array($schedule, \App\Console\Commands\DispatchDueBackups::SCHEDULES, true)) return;
        if (!$this->connectionId) return;

        $updated = DB::table('backup_jobs')
            ->where('connection_id', $this->connectionId)
            ->update(['schedule' => $schedule, 'updated_at' => now()]);

        if ($updated) {
            $this->flash(true, __('Backup schedule updated.'));
        }
        $this->refreshState();
    }

    /**
     * Records a PENDING payment row for this tenant/plan BEFORE any charge
     * exists, with a reference we generate and control. This is the
     * attribution source of truth: the Flutterwave webhook looks up this
     * row by reference to learn the real tenant_id/plan_id — it never
     * trusts Flutterwave's own charge.meta for that, which depends on
     * every future call site remembering to set it correctly.
     *
     * NOTE: no real checkout flow exists yet (2026-07-25) — this still ends
     * with the manual operator-alert bridge. But the pending row now
     * exists regardless, so once real checkout initiation is built, it only
     * has to reuse $reference rather than invent the attribution problem
     * from scratch.
     */
    public function requestUpgrade(string $planId)
    {
        $plan = DB::table('backup_plans')->where('id', $planId)->first();
        if (!$plan) { $this->flash(false, __('That plan is not available.')); return; }

        $reference = 'obaits-' . substr($this->tenantId, 0, 8) . '-' . substr((string) \Illuminate\Support\Str::uuid(), 0, 8);

        DB::table('backup_payments')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenantId,
            'plan_id' => $plan->id,
            'provider' => 'flutterwave',
            'provider_reference' => $reference,
            'amount_usd_cents' => $plan->price_usd_cents,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \App\Support\Health\OperatorAlert::send(
            'Backup storage upgrade requested',
            [['level' => 'info', 'area' => 'backup',
              'message' => "Tenant {$this->tenantId} requested {$plan->gb_size}GB "
                         . '(' . number_format($plan->price_usd_cents / 100, 2) . ' USD/yr). '
                         . "Pending payment row created: {$reference}. "
                         . 'No automated checkout yet — invoice and provision manually.']]
        );

        $this->flash(true, __('Request sent — our team will follow up to arrange payment and activate your new storage.'));
    }

    private function flash(bool $ok, string $message): void
    {
        $this->noticeOk = $ok;
        $this->notice = $message;
    }

    /**
     * Provision the tenant's IDrive sub-account at the 10GB free tier. This
     * is the ONLY self-service action here that talks to IDrive directly —
     * everything else operates on already-provisioned local state.
     */
    public function connect()
    {
        try {
            BackupProvisioner::provision($this->tenantId);
            $this->flash(true, __('Backup storage connected. You can now choose folders to protect.'));
        } catch (\Throwable $e) {
            \Log::error('Backups: provision failed', ['tenant' => $this->tenantId, 'error' => $e->getMessage()]);
            $this->flash(false, __('We could not set up backup storage right now. Please try again shortly.'));
        }
        $this->refreshState();
    }

    public function selectHost(int $hostId)
    {
        $this->selectedHostId = $hostId;
        $this->folderCache = [];
        $this->expandedPaths = [];
        $this->loadFolders($this->rootForSelectedHost());
    }

    private function rootForSelectedHost(): string
    {
        $host = collect($this->hosts)->firstWhere('id', $this->selectedHostId);
        $roots = FleetFolderBrowser::rootsFor($host['platform'] ?? '');
        return $roots[0] ?? '/';
    }

    /**
     * Fetch one directory level. A live query against an offline device can
     * take real time and can come back empty for reasons other than "this
     * folder has no subfolders" — the view is written to say so plainly
     * rather than implying certainty it doesn't have.
     */
    public function loadFolders(string $path)
    {
        if (!$this->selectedHostId) return;

        $r = FleetFolderBrowser::listFolders($this->tenantId, $this->selectedHostId, $path, session('auth_email'));

        if (!$r['ok']) {
            $this->flash(false, match ($r['error']) {
                'not_your_host' => __('That device is not available.'),
                default         => __('Could not reach that device right now. It may be offline — try again shortly.'),
            });
            return;
        }

        $this->folderCache[$path] = $r['folders'];
        $this->expandedPaths[$path] = true;
    }

    public function toggleExpand(string $path)
    {
        if (!empty($this->expandedPaths[$path])) {
            unset($this->expandedPaths[$path]);
            return;
        }
        $this->loadFolders($path);
    }

    /**
     * Add or remove a folder from this tenant's backup selection. Toggling
     * off does not delete history — it only stops FUTURE runs from
     * including it (enabled=false), matching backup_selections' own design.
     */
    public function toggleSelection(string $path)
    {
        if (!$this->connectionId || !$this->selectedHostId) return;

        $existing = DB::table('backup_selections')
            ->where('connection_id', $this->connectionId)
            ->where('fleet_host_id', $this->selectedHostId)
            ->where('folder_path', $path)
            ->first();

        if ($existing) {
            DB::table('backup_selections')->where('id', $existing->id)
                ->update(['enabled' => !$existing->enabled, 'updated_at' => now()]);
        } else {
            DB::table('backup_selections')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id' => $this->tenantId,
                'connection_id' => $this->connectionId,
                'fleet_host_id' => $this->selectedHostId,
                'folder_path' => $path,
                'enabled' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Ensure a backup_jobs row exists for this connection — created on
        // first selection, not at connect() time, since a connection with
        // nothing selected has nothing to schedule.
        $jobExists = DB::table('backup_jobs')->where('connection_id', $this->connectionId)->exists();
        if (!$jobExists) {
            DB::table('backup_jobs')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id' => $this->tenantId,
                'connection_id' => $this->connectionId,
                'schedule' => 'daily',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->refreshState();
    }

    public function isSelected(int $hostId, string $path): bool
    {
        foreach ($this->selections as $s) {
            if ($s->fleet_host_id === $hostId && $s->folder_path === $path && $s->enabled) return true;
        }
        return false;
    }

    /**
     * Manual "back up now" — same path the daily scheduler uses
     * (BackupJobRunner::start), just triggered on demand.
     */
    public function runNow()
    {
        $jobId = DB::table('backup_jobs')->where('connection_id', $this->connectionId)->value('id');
        if (!$jobId) {
            $this->flash(false, __('Choose at least one folder before running a backup.'));
            return;
        }

        $r = BackupJobRunner::start($jobId);

        $this->flash($r['ok'] ?? false, match ($r['error'] ?? null) {
            null            => __('Backup started. This page will update as it progresses.'),
            'host_busy'     => __('This device is already busy with another backup task. Try again shortly.'),
            'run_already_active' => __('A backup is already running.'),
            'no_folders_selected' => __('Choose at least one folder before running a backup.'),
            default         => __('Could not start the backup right now.'),
        });

        $this->refreshState();
    }

    /**
     * Restore one run's snapshot back onto the SAME device, into a
     * clearly-separate path — never overwriting live files directly.
     *
     * ASYNC by design (fixed 2026-07-25): a synchronous restore blocked a
     * real Livewire request past the web server's own timeout, killing the
     * request before it could ever report success or failure — confirmed
     * during testing. This now only LAUNCHES via BackupRestoreRunner and
     * returns immediately; completion is polled on a schedule and the
     * tenant is notified (portal + email) when it finishes, regardless of
     * whether this page is still open.
     */
    public function restoreRun(string $runId)
    {
        $run = DB::table('backup_runs')->where('id', $runId)->first();
        if (!$run || !$run->snapshot_id) {
            $this->flash(false, __('That backup has no restorable snapshot.'));
            return;
        }

        $job = DB::table('backup_jobs')->where('id', $run->job_id)->first();
        $hostId = DB::table('backup_selections')
            ->where('connection_id', $job->connection_id)->value('fleet_host_id');

        if (!$hostId) {
            $this->flash(false, __('Could not determine which device to restore to.'));
            return;
        }

        $r = \App\Support\Backup\BackupRestoreRunner::start(
            $this->tenantId, (int) $hostId, $run->snapshot_id, $run->id, session('auth_email')
        );

        if ($r['ok']) {
            $this->flash(true, __('Restore started. Files will be placed in a safe temporary location on the device (not overwriting anything currently there) — you will be notified here and by email when it finishes, with the exact path to look in.'));
        } else {
            $this->flash(false, $r['error'] === 'host_busy'
                ? __('This device is busy right now. Please try the restore again shortly.')
                : __('Could not start the restore right now.'));
        }

        $this->refreshState();
    }

    public function render()
    {
        return view('livewire.portal.backups');
    }
}
