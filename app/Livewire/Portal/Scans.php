<?php
namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use App\Support\Perm;
use Livewire\WithPagination;

#[Layout('components.layouts.portal')]
class Scans extends Component
{
    use WithPagination;

    public string $tenantId;
    public string $selectedDomain = '';
    public string $selectedAsset = '';
    public string $webUrl = '';
    public bool $webActive = false;

    // Filters
    public string $search = '';
    public string $statusFilter = '';
    public string $dateFrom = '';   // was: dateFilter (single date)
    public string $dateTo = '';

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); }
    }

    public function runScan()
    {
        // Backend role gate (the real lock).
        Perm::require('run_scans');

        if ($busy = $this->scanInProgress()) {
            $this->addError('selectedDomain', $busy);
            return;
        }

        // Only allow scanning a VERIFIED domain of THIS tenant (ownership).
        $domain = DB::table('tenant_domains')
            ->where('tenant_id', $this->tenantId)
            ->where('domain', $this->selectedDomain)
            ->where('verified', true)
            ->first();

        if (!$domain) {
            $this->addError('selectedDomain', __('Please choose a verified domain.'));
            return;
        }

        app(\App\Services\Scanner::class)->launch($this->tenantId, $domain->domain, 'on_demand');
        session()->flash('scan_ok', __('Scan started. This can take several minutes.'));
        $this->selectedDomain = '';
    }

    /**
     * REAL BUG FOUND 2026-08-24: the "scan one of your enrolled devices"
     * dropdown was bound to $selectedDomain and its button wired to runScan(),
     * which validates the target against tenant_domains. A device target is an
     * IP address and can never match a verified domain row, so every device
     * scan hit the early return, set an error on a field in the OTHER card, and
     * appeared to do nothing at all. Livewire returned 200 throughout.
     *
     * Confirmed by the data: ZERO scans existed platform-wide. This path had
     * never run once since it was built -- the front end was complete, the
     * dispatch was simply never wired to it.
     *
     * OWNERSHIP IS STILL ENFORCED, deliberately. The domain path proves
     * ownership via tenant_domains; without an equivalent here a user could
     * post an arbitrary IP and have our scanner attack a third party. The
     * target is validated against assetTargets(), which is derived from THIS
     * tenant's own Fleet enrolment secret, so only a device the tenant actually
     * owns can be submitted -- and only if it is genuinely reachable.
     */
    public function runAssetScan()
    {
        Perm::require('run_scans');

        if ($busy = $this->scanInProgress()) {
            $this->addError('selectedAsset', $busy);
            return;
        }

        $match = collect($this->assetTargets())
            ->first(fn ($a) => $a['value'] === $this->selectedAsset);

        if (!$match) {
            $this->addError('selectedAsset', __('Please choose one of your enrolled devices.'));
            return;
        }

        if (!$match['scannable']) {
            $this->addError('selectedAsset', __('That device is not reachable for scanning.'));
            return;
        }

        app(\App\Services\Scanner::class)->launch($this->tenantId, $match['value'], 'on_demand');
        session()->flash('scan_ok', __('Scan started. This can take several minutes.'));
        $this->selectedAsset = '';
    }

    /**
     * One scan at a time per tenant.
     *
     * REQUESTED 2026-08-25. Two concurrent scans of the same estate is rarely
     * what anyone intends, and the scanner pool is built on the same assumption:
     * a pool member joins ONE tenant network and runs ONE scan before being
     * released. Enforcing it here aligns the UI with the architecture instead of
     * letting users queue work the scanner cannot take.
     *
     * Returns a human explanation rather than a bare refusal -- the useful thing
     * to know is whether to wait or to investigate, and that depends on how long
     * the running scan has been going.
     */
    private function scanInProgress(): ?string
    {
        $running = DB::table('scans')
            ->where('tenant_id', $this->tenantId)
            ->whereIn('status', ['queued', 'running'])
            ->orderBy('created_at')
            ->first();

        if (!$running) {
            return null;
        }

        $since = \Carbon\Carbon::parse($running->created_at)->diffForHumans(null, true);

        return __('A scan of :target is already running for your organisation (started :since ago). Scans run one at a time, so please wait for it to finish before starting another.', [
            'target' => $running->target,
            'since'  => $since,
        ]);
    }

    public function render()
    {
        $scans = DB::table('scans')
            ->where('tenant_id', $this->tenantId)
            ->when($this->search !== '', function ($q) {
                $q->where('target', 'ilike', '%' . $this->search . '%');
            })
            ->when($this->statusFilter !== '', function ($q) {
                $q->where('status', $this->statusFilter);
            })
            // Date RANGE (replaces the old single-date filter)
            ->when($this->dateFrom !== '', function ($q) {
                $q->whereDate('created_at', '>=', $this->dateFrom);
            })
            ->when($this->dateTo !== '', function ($q) {
                $q->whereDate('created_at', '<=', $this->dateTo);
            })
            ->orderByDesc('created_at')
            ->paginate(15);

        foreach ($scans as $s) {
            $s->risk_count = DB::table('findings')
                ->where('scan_id', $s->id)
                ->whereIn('threat', ['High', 'Medium', 'Low'])
                ->count();
        }

        $verifiedDomains = DB::table('tenant_domains')
            ->where('tenant_id', $this->tenantId)
            ->where('verified', true)->get();

        return view('livewire.portal.scans', compact('scans', 'verifiedDomains'));
    }

    // Quick-range buttons: Today / Last 7 days / Last 30 days / All
    public function quickRange(string $range)
    {
        $this->dateTo = now()->toDateString();
        $this->dateFrom = match ($range) {
            'today' => now()->toDateString(),
            '7'     => now()->subDays(7)->toDateString(),
            '30'    => now()->subDays(30)->toDateString(),
            default => '',
        };
        if ($range === 'all') { $this->dateTo = ''; }
        $this->resetPage();
    }

    public function assetTargets(): array
    {
        $secret = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)
            ->where('tool', 'fleet')
            ->where('artifact_type', 'enroll_secret')
            ->value('external_id');
        if (!$secret) return [];

        try {
            $hosts = app(\App\Clients\FleetClient::class)->hostsForTenant($secret);
        } catch (\Throwable $e) {
            return [];
        }

        $targets = [];
        foreach ($hosts as $h) {
            $lanIp = $h['primary_ip'] ?? ($h['public_ip'] ?? null);
            $name = $h['hostname'] ?? $lanIp;
            if (!$lanIp) continue;

            // Phase 4: Fleet's own primary_ip is ALWAYS the device's real
            // LAN address, never its Tailscale one (confirmed empirically
            // 2026-07-26 — Fleet reported a 10.x LAN IP for a device
            // simultaneously, verifiably online at 100.64.x.x). A private
            // device's actual scannable target is its resolved VPN IP, if
            // it has one; the LAN IP itself is never directly scannable.
            $vpnIp = \App\Support\Reachability::isPrivate($lanIp)
                ? \App\Support\Reachability::vpnIpForHost($this->tenantId, $name)
                : null;

            $target = $vpnIp ?? $lanIp;
            $scannable = \App\Support\Reachability::isScannable($target, $this->tenantId);

            $targets[] = [
                'label'     => $vpnIp
                    ? $name . ' (' . $vpnIp . ' via tenant VPN)'
                    : $name . ' (' . $lanIp . ')',
                'value'     => $target,
                'scannable' => $scannable,
            ];
        }
        return $targets;
    }

    public function cancel(string $scanId)
    {
        \App\Support\Perm::require('run_scans');

        $scan = \Illuminate\Support\Facades\DB::table('scans')
            ->where('id', $scanId)
            ->where('tenant_id', $this->tenantId)
            ->first();
        if (!$scan) { abort(404); }

        // Mark cancelled immediately (fast) so the UI updates and the polling loop stops.
        \Illuminate\Support\Facades\DB::table('scans')->where('id', $scanId)
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        // Hand the slow GVM stop to a background job (don't block the web request).
        if (in_array($scan->status, ['queued','running']) && $scan->gvm_task_id) {
            \App\Jobs\StopGvmTask::dispatch($scan->gvm_task_id);
        }

        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'scan.cancelled', 'success', 'gvm',
            ['scan' => $scanId]);

        session()->flash('scan_ok', __('Scan cancelled.'));
    }

    public function deleteScan(string $scanId)
    {
        \App\Support\Perm::require('delete_scans');

        $scan = \Illuminate\Support\Facades\DB::table('scans')
            ->where('id', $scanId)
            ->where('tenant_id', $this->tenantId)
            ->first();
        if (!$scan) { abort(404); }

        // Hand off the slow GVM cleanup to a background job (don't block the web request).
        \App\Jobs\CleanupGvmArtifacts::dispatch($scan->gvm_task_id ?? null, $scan->gvm_target_id ?? null);

        // Fast local delete.
        \Illuminate\Support\Facades\DB::table('findings')->where('scan_id', $scanId)->delete();
        \Illuminate\Support\Facades\DB::table('scans')->where('id', $scanId)->delete();

        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'scan.deleted', 'success', 'gvm',
            ['scan' => $scanId, 'target' => $scan->target]);

        session()->flash('scan_ok', __('Scan deleted.'));
    }

    public function runWebScan()
    {
        \App\Support\Perm::require('run_scans');

        if ($busy = $this->scanInProgress()) {
            $this->addError('webUrl', $busy);
            return;
        }

        if (!filter_var($this->webUrl, FILTER_VALIDATE_URL)) {
            $this->addError('webUrl', __('Please enter a full URL, e.g. https://yoursite.com'));
            return;
        }
        // Ownership: the URL host must be a verified domain (RunWebScan re-checks too).
        $host = parse_url($this->webUrl, PHP_URL_HOST);
        $hostNoWww = preg_replace('#^www\.#', '', $host);
        $ok = \Illuminate\Support\Facades\DB::table('tenant_domains')
            ->where('tenant_id', $this->tenantId)->where('verified', true)
            ->where(function ($q) use ($host, $hostNoWww) {
                $q->where('domain', $host)->orWhere('domain', $hostNoWww);
            })->exists();
        $isDev = in_array($host, ['127.0.0.1','localhost']) || str_starts_with($host ?? '', '192.168.') || str_starts_with($host ?? '', '10.');
        if (!$ok && !$isDev) {
            $this->addError('webUrl', __('You can only scan a web address on one of your verified domains.'));
            return;
        }

        app(\App\Services\Scanner::class)->launchWeb($this->tenantId, $this->webUrl, $this->webActive);
        session()->flash('scan_ok', __('Web scan started. This can take several minutes.'));
        $this->reset(['webUrl','webActive']);
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingStatusFilter()
    {
        $this->resetPage();
    }

    public function updatingDateFrom()
    {
        $this->resetPage();
    }

    public function updatingDateTo()
    {
        $this->resetPage();
    }
}