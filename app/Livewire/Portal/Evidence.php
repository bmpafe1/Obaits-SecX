<?php
namespace App\Livewire\Portal;
 
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use App\Support\Perm;
 
#[Layout('components.layouts.portal')]
class Evidence extends Component
{
    use WithFileUploads;
 
    public string $tenantId;
    public ?string $folderId = null;   // the tenant's CISO domain id
    public $file;
    public string $name = '';
 
    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); }
 
        // Resolve this tenant's CISO domain/folder id (per-tenant scoping).
        $this->folderId = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)
            ->where('tool', 'ciso_assistant')
            ->where('artifact_type', 'domain')   // adjust to the real artifact_type from D2
            ->value('external_id');
    }
 
    public function upload()
    {
        Perm::require('manage_documents');
        $this->validate([
            'file' => 'required|file|max:20480',
            'name' => 'required|string|max:200',
        ]);
        if (!$this->folderId) {
            session()->flash('ev_ok', __('Compliance workspace is not set up yet.'));
            return;
        }
 
        $path = $this->file->getRealPath();
        try {
            app(\App\Clients\CisoClient::class)->uploadEvidence(
                $this->folderId, $this->name, $path, $this->file->getClientOriginalName()
            );
            \App\Support\Audit::log($this->tenantId, session('auth_email'), 'evidence.uploaded', 'success', 'ciso', ['name' => $this->name]);
            session()->flash('ev_ok', __('Evidence uploaded.'));
        } catch (\Throwable $e) {
            session()->flash('ev_ok', __('Upload failed. Please try again.'));
        }
        $this->reset(['file','name']);
    }
 
    public function render()
    {
        $evidences = [];
        if ($this->folderId) {
            try {
                $evidences = app(\App\Clients\CisoClient::class)->evidencesForFolder($this->folderId);
            } catch (\Throwable $e) {
                $evidences = [];
            }
        }
        return view('livewire.portal.evidence', compact('evidences'));
    }
}
