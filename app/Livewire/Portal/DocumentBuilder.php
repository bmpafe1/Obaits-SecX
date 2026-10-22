<?php
namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Perm;
use App\Support\TenantProfile;
use App\Support\DocumentApprovals;
use App\Support\Compliance\DocumentRegister;
use App\Support\Compliance\AutoGenerator;
use App\Support\Compliance\InterviewGenerator;
use App\Support\Compliance\DelegatePack;
use App\Support\Compliance\DocxRenderer;

#[Layout('components.layouts.portal')]
class DocumentBuilder extends Component
{
    public string $tenantId;
    public string $docKey;
    public array $doc = [];
    public array $answers = [];
    public string $body = '';

    // Delegate mode
    public ?array $owner = null;          // array, not object — Livewire serialises this
    public string $delegateNote = '';
    public ?string $delegateFile = null;

    public function mount(string $docKey)
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); }

        $doc = DocumentRegister::get($docKey, $this->tenantId);
        if (!$doc) { abort(404); }

        $this->docKey = $docKey;
        $this->doc = $doc;

        // Reload previous answers so the user can revise rather than start over.
        $prev = DB::table('tenant_documents')
            ->where('tenant_id', $this->tenantId)
            ->where('doc_key', $docKey)
            ->orderByDesc('created_at')->first();

        if ($prev) {
            $this->answers = json_decode($prev->answers ?? '[]', true) ?: [];
            $this->body = $prev->body ?? '';
        }
    }

    public function generate()
    {
        Perm::require('manage_team');

        $profile = TenantProfile::for($this->tenantId);

        $this->body = match ($this->doc['mode']) {
            DocumentRegister::AUTOMATED =>
                AutoGenerator::body($this->tenantId, $this->docKey, $this->doc, $profile),
            DocumentRegister::INTERVIEW =>
                InterviewGenerator::body($this->tenantId, $this->docKey, $this->doc, $this->answers),
            default => '',
        };

        $this->store('draft');
        session()->flash('d_ok', __('Draft created. Read it carefully, edit anything that is wrong, then mark it final.'));
    }

    public function prepareDelegatePack()
    {
        Perm::require('manage_team');

        $pack = DelegatePack::pack($this->tenantId, $this->docKey);

        $this->owner = $pack['owner'] ? (array) $pack['owner'] : null;
        $this->delegateNote = $pack['note'];
        $this->delegateFile = $pack['file'];
        $this->body = $pack['body'];

        $this->store($pack['owner'] ? 'sent_to_owner' : 'draft', $pack['file'], $pack['owner']);
        session()->flash('d_ok', __('Template prepared.'));
    }

    // Save a NEW version. Never overwrite — an auditor may ask what it said last year.
    private function store(string $status, ?string $file = null, $owner = null)
    {
        $profile = TenantProfile::for($this->tenantId);
        $signatories = DocumentApprovals::for($this->tenantId, $this->docKey);

        // Render the .docx unless the delegate pack already produced one.
        if (!$file && $this->body !== '') {
            $file = DocxRenderer::render($this->tenantId, $this->doc, $profile, $signatories, $this->body);
        }

        $count = DB::table('tenant_documents')
            ->where('tenant_id', $this->tenantId)->where('doc_key', $this->docKey)->count();

        DB::table('tenant_documents')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'doc_key' => $this->docKey,
            'title' => $this->doc['title'],
            'mode' => $this->doc['mode'],
            'status' => $status,
            'version' => '1.' . $count,
            'body' => $this->body,
            'file_path' => $file,
            'answers' => json_encode($this->answers),
            'signatories' => json_encode($signatories),
            'header' => json_encode($profile),
            'assigned_to' => $owner->name ?? null,
            'assigned_role' => $this->doc['owner'] ?? null,
            'sent_at' => $owner ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'document.generated', 'success', null,
            ['doc' => $this->docKey, 'status' => $status]);
    }

    public function saveDraft()
    {
        Perm::require('manage_team');
        $this->store('draft');
        session()->flash('d_ok', __('Draft saved.'));
    }

    public function markFinal()
    {
        Perm::require('manage_team');
        $this->store('final');
        session()->flash('d_ok', __('Document marked final.'));
    }

    public function render()
    {
        return view('livewire.portal.document-builder', [
            'versions' => DB::table('tenant_documents')
                ->where('tenant_id', $this->tenantId)
                ->where('doc_key', $this->docKey)
                ->orderByDesc('created_at')->get(),
        ]);
    }
}