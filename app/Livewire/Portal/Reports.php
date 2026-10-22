<?php

namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Security posture at a glance.
 *
 * DELIBERATELY A GATHERING PAGE, NOT A REPORT ENGINE. Every figure here already
 * exists somewhere in the platform; what was missing was one page that answers
 * "how are we doing" without opening six others. Each number links to the page
 * that owns it, so this summarises rather than duplicates.
 *
 * Built to be PRINTED. The realistic use is a board pack, an insurer, or an
 * auditor asking for a position on a date -- so the print stylesheet drops the
 * navigation and the links, and the date is stated on the page.
 *
 * Reads only this tenant's own data. Nothing here is comparative or
 * cross-tenant.
 */
#[Layout('components.layouts.portal')]
class Reports extends Component
{
    public string $tenantId;

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { return redirect('/login'); }
    }

    private function count(string $table, ?callable $filter = null): int
    {
        try {
            $q = DB::table($table)->where('tenant_id', $this->tenantId);
            if ($filter) { $filter($q); }
            return $q->count();
        } catch (\Throwable $e) {
            // A table that does not exist in this deployment must not break the
            // whole page -- it simply has nothing to report.
            return 0;
        }
    }

    public function render()
    {
        $devices = $this->count('device_credentials', fn ($q) => $q->whereNull('revoked_at'));

        // GVM's severities are High, Medium, Low, Log and Debug -- 'Log' being
        // informational. Counting only the first three showed 0/0/0 while ten
        // findings existed, which reads as 'nothing found' when the truth was
        // 'nothing serious found, and ten things noted'. Those are different
        // claims and only one of them is honest.
        $bySeverity = DB::table('findings')->where('tenant_id', $this->tenantId)
            ->selectRaw('threat, count(*) as n')->groupBy('threat')
            ->pluck('n', 'threat')->all();

        $findings = [
            'high'   => (int) ($bySeverity['High'] ?? 0),
            'medium' => (int) ($bySeverity['Medium'] ?? 0),
            'low'    => (int) ($bySeverity['Low'] ?? 0),
            'info'   => (int) ($bySeverity['Log'] ?? 0) + (int) ($bySeverity['Debug'] ?? 0),
            'total'  => array_sum($bySeverity),
        ];

        $lastScan = DB::table('scans')->where('tenant_id', $this->tenantId)
            ->orderByDesc('created_at')->value('created_at');

        // Open incidents: helpdesk tickets flagged as such. IRIS cases are an
        // API call and deliberately not made here -- a summary page that hangs
        // on an unreachable service is worse than one that omits a figure.
        $incidents = $this->count('tickets', fn ($q) => $q->where('is_incident', true)->where('status', '!=', 'closed'));

        $sub = DB::table('subscriptions')->where('tenant_id', $this->tenantId)->first();

        $profile = DB::table('tenant_profiles')->where('tenant_id', $this->tenantId)->first();
        $tenant  = DB::table('tenants')->where('id', $this->tenantId)->first();

        return view('livewire.portal.reports', [
            'tenant'    => $tenant,
            'profile'   => $profile,
            'devices'   => $devices,
            'limit'     => $sub->asset_limit ?? null,
            'findings'  => $findings,
            'scans'     => $this->count('scans'),
            'lastScan'  => $lastScan ? Carbon::parse($lastScan) : null,
            'policies'  => $this->count('policy_assignments'),
            'backups'   => $this->count('backup_selections'),
            'incidents' => $incidents,
            'tickets'   => $this->count('tickets'),
            'documents' => $this->count('tenant_documents'),
            'frameworks'=> $this->count('tenant_frameworks'),
            'users'     => $this->count('tenant_users'),
            'generated' => now(),
        ]);
    }
}
