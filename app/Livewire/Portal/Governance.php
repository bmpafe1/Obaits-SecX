<?php
namespace App\Livewire\Portal;
 
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Perm;
use App\Support\DocumentTypes;
 
#[Layout('components.layouts.portal')]
class Governance extends Component
{
    public string $tenantId;
 
    // Add-person form
    public string $pname = '';
    public string $ptitle = '';
    public string $pemail = '';
 
    // Approval editing
    public string $editingType = '';
    public array $selected = [];     // ['prepared_by' => [id,...], 'reviewed_by' => [...], 'approved_by' => [...]]
 
    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); }
    }
 
    public function addPerson()
    {
        Perm::require('manage_team');
        $this->validate([
            'pname' => 'required|string|max:120',
            'ptitle' => 'required|string|max:120',
            'pemail' => 'nullable|email',
        ]);
 
        DB::table('tenant_people')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'name' => $this->pname,
            'title' => $this->ptitle,
            'email' => $this->pemail ?: null,
            'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
 
        $this->reset(['pname', 'ptitle', 'pemail']);
        session()->flash('gov_ok', __('Person added.'));
    }
 
    public function removePerson(string $id)
    {
        Perm::require('manage_team');
        // Tenant-scoped delete (isolation).
        DB::table('tenant_people')->where('id', $id)->where('tenant_id', $this->tenantId)->delete();
        session()->flash('gov_ok', __('Person removed.'));
    }
 
    // Open the approval editor for a document type.
    public function editApprovals(string $type)
    {
        $this->editingType = $type;
        $this->selected = ['prepared_by' => [], 'reviewed_by' => [], 'approved_by' => []];
 
        $rows = DB::table('document_approvals')
            ->where('tenant_id', $this->tenantId)
            ->where('document_type', $type)
            ->get();
 
        foreach ($rows as $r) {
            $this->selected[$r->role][] = $r->person_id;
        }
    }
 
    public function saveApprovals()
    {
        Perm::require('manage_team');
        if (!$this->editingType) return;
 
        DB::transaction(function () {
            DB::table('document_approvals')
                ->where('tenant_id', $this->tenantId)
                ->where('document_type', $this->editingType)
                ->delete();
 
            foreach (DocumentTypes::ROLES as $role => $_label) {
                foreach (($this->selected[$role] ?? []) as $i => $personId) {
                    // Only accept people who belong to THIS tenant (isolation).
                    $ok = DB::table('tenant_people')->where('id', $personId)
                        ->where('tenant_id', $this->tenantId)->exists();
                    if (!$ok) continue;
 
                    DB::table('document_approvals')->insert([
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $this->tenantId,
                        'document_type' => $this->editingType,
                        'person_id' => $personId,
                        'role' => $role,
                        'sort_order' => $i,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
 
        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'approvals.updated', 'success', null,
            ['document_type' => $this->editingType]);
 
        $this->editingType = '';
        session()->flash('gov_ok', __('Approvals saved.'));
    }
 
    public function render()
    {
        $people = DB::table('tenant_people')
            ->where('tenant_id', $this->tenantId)
            ->orderBy('name')->get();
 
        // Current approvals, grouped for display.
        $approvals = DB::table('document_approvals as da')
            ->join('tenant_people as p', 'p.id', '=', 'da.person_id')
            ->where('da.tenant_id', $this->tenantId)
            ->select('da.document_type', 'da.role', 'p.name', 'p.title')
            ->get()
            ->groupBy('document_type');
 
        return view('livewire.portal.governance', [
            'people' => $people,
            'types' => DocumentTypes::all(),
            'roles' => DocumentTypes::ROLES,
            'approvals' => $approvals,
        ]);
    }
}
