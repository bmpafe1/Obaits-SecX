<?php
namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use App\Support\Perm;
use App\Support\Helpdesk\Tickets;

#[Layout('components.layouts.portal')]
class ScanFindings extends Component
{
    public string $scanId;
    public string $tenantId;
    public string $search = '';
    public string $severityFilter = '';
    public bool $showInfo = false;
    public bool $showOther = false;
    public ?array $patch = null;

    public function toggleInfo()
    {
        $this->showInfo = !$this->showInfo;
    }

    public function toggleOther()
    {
        $this->showOther = !$this->showOther;
    }

    public function clearFilters()
    {
        $this->reset(['search', 'severityFilter']);
    }

    public function mount(string $scanId)
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); }

        // Ownership: the scan must belong to THIS tenant, or refuse.
        $scan = DB::table('scans')->where('id', $scanId)
            ->where('tenant_id', $this->tenantId)->first();
        if (!$scan) { abort(404); }
        $this->scanId = $scanId;
    }

    public function render()
    {
        $scan = DB::table('scans')->where('id', $this->scanId)->first();

        $all = DB::table('findings')
            ->where('scan_id', $this->scanId)
            ->where('tenant_id', $this->tenantId)      // isolation, defence in depth
            ->when($this->search !== '', function ($q) {
                $q->where(function ($sub) {
                    $sub->where('name', 'ilike', '%' . $this->search . '%')
                        ->orWhere('cve', 'ilike', '%' . $this->search . '%')
                        ->orWhere('host', 'ilike', '%' . $this->search . '%');
                });
            })
            ->when($this->severityFilter !== '', function ($q) {
                $q->where('criticality', (int) $this->severityFilter);
            })
            ->orderByDesc('criticality')
            ->orderByDesc('severity')
            ->get();

        // Decode the risk_factors JSON once, so the view can just read it.
        $all = $all->map(function ($f) {
            $f->factors = json_decode($f->risk_factors ?? '[]', true) ?: [];
            return $f;
        });

        // NEEDS ATTENTION: criticality 3 and above — the things worth a person's time.
        $priority = $all->where('criticality', '>=', 3)->values();

        // WORTH KNOWING: criticality 2 — real, but not urgent.
        $other = $all->where('criticality', '=', 2)->values();

        // INFORMATIONAL: criticality 1 (or unscored) — noise, hidden by default.
        $info = $all->filter(fn ($f) => ($f->criticality ?? 1) <= 1)->values();

        // The unfiltered total, so we can say "showing 3 of 47" honestly.
        $grandTotal = DB::table('findings')
            ->where('scan_id', $this->scanId)
            ->where('tenant_id', $this->tenantId)
            ->count();

        $counts = [
            'priority'   => $priority->count(),
            'other'      => $other->count(),
            'info'       => $info->count(),
            'total'      => $all->count(),
            'grandTotal' => $grandTotal,
            'filtered'   => ($this->search !== '' || $this->severityFilter !== ''),
        ];

        return view('livewire.portal.scan-findings', compact('scan', 'priority', 'other', 'info', 'counts'));
    }

     public function protectFromFinding(string $findingId)
    {
        \App\Support\Perm::require('manage_team');
        $this->patch = \App\Support\Waf\VirtualPatch::forFinding(session('tenant_id'), $findingId);
    }
 
    public function dismissPatch()
    {
        $this->patch = null;
    }

    public function incidentFromFinding(string $findingId)
    {
        Perm::require('manage_team');

        $f = DB::table('findings')->where('id', $findingId)->where('tenant_id', $this->tenantId)->first();
        if (!$f) abort(404);

        $id = Tickets::raise($this->tenantId, [
            'subject' => 'Scan finding: ' . ($f->name ?? 'finding'),
            'body' => "Recorded from scanner.\n\nHost: " . ($f->host ?? '?') . "\nWhen: " . ($f->created_at ?? now()),
            'priority' => (($f->criticality ?? 3) >= 4) ? 'urgent' : 'high',
        ], session('user_id'));

        DB::table('tickets')->where('id', $id)->update([
            'is_incident' => true,
            'incident_severity' => (($f->criticality ?? 3) >= 4) ? 'high' : 'medium',
            'detected_at' => $f->created_at ?? now(),
            'linked_finding_id' => $f->id ?? null,
            'updated_at' => now(),
        ]);

        return redirect("/portal/ticket/{$id}");
    }

}
