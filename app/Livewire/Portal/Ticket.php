<?php
namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use App\Support\Perm;
use App\Support\Helpdesk\Tickets;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Layout('components.layouts.portal')]
class Ticket extends Component
{
    use WithFileUploads;
    public string $tenantId;
    public string $ticketId;
    public $ticket;
    public $comments;
    public string $commentBody = '';
    public ?string $incidentSeverity = null;
    public ?string $detectedAt = null;
    public ?string $incidentDescription = null;
    public ?string $incidentResponse = null;
    public bool $showEscalationForm = false;
    public ?string $rootCause = null;
    public ?string $lessonsLearned = null;
    public bool $showResolveForm = false;
    public $attachment;      // the file being uploaded right now
    public $attachments;     // the list already attached

    public function mount(string $ticketId)
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); }
        $this->ticketId = $ticketId;
        $this->loadTicket();
        $this->loadAttachments();
    }

    public function loadTicket()
    {
        $t = DB::table('tickets')->where('id', $this->ticketId)->where('tenant_id', $this->tenantId)->first();
        if (!$t) abort(404);
        $this->ticket = $t;
        $this->comments = DB::table('ticket_comments as c')
            ->leftJoin('tenant_users as u', 'u.id', '=', 'c.author_id')
            ->where('c.ticket_id', $this->ticketId)
            ->where('c.tenant_id', $this->tenantId)          // ISOLATION
            ->orderBy('c.created_at')
            ->select('c.*', DB::raw("TRIM(COALESCE(u.first_name,'') || ' ' || COALESCE(u.last_name,'')) as author_name"))
            ->get();
    }

    public function addComment()
    {
        // Tenant users may comment on their own tickets; manage_team can comment on any.
        $canManage = Perm::can('manage_team');
        if (!$canManage && $this->ticket->raised_by !== session('tenant_user_id')) {
            abort(403);
        }
        $this->validate(['commentBody' => 'required|string']);
        Tickets::comment($this->tenantId, $this->ticketId, $this->commentBody, session('tenant_user_id'));
        $this->commentBody = '';
        $this->loadTicket();
    }

    public function escalate()
    {
        // Open the escalation form for manage_team users.
        Perm::require('manage_team');
        $this->showEscalationForm = true;
    }

    public function escalateToIncident()
    {
        Perm::require('manage_team');

        $this->validate([
            'incidentSeverity' => 'required|string',
            'incidentDescription' => 'required|string',
            'incidentResponse' => 'required|string',
            'detectedAt' => 'nullable|string',
        ]);

        DB::table('tickets')->where('id', $this->ticketId)
            ->where('tenant_id', $this->tenantId)
            ->update([
                'is_incident' => true,
                'incident_severity' => $this->incidentSeverity ?: 'medium',
                'detected_at' => $this->detectedAt ?: now(),
                'updated_at' => now(),
            ]);
        
        // Files attached before we recognised this as an incident were evidence all along.
        // The flag just catches up.
        DB::table('ticket_attachments')->where('ticket_id', $this->ticketId)
            ->where('tenant_id', $this->tenantId)
            ->update(['is_evidence' => true]);

        $body = "This was recorded as a security incident.\n\n";
        $body .= "Severity: " . ($this->incidentSeverity ?? 'medium') . "\n";
        $body .= "Detected at: " . ($this->detectedAt ?? now()) . "\n\n";
        $body .= "What happened:\n" . $this->incidentDescription . "\n\n";
        $body .= "What we are doing:\n" . $this->incidentResponse . "\n";

        Tickets::comment(
            $this->tenantId, $this->ticketId,
            $body,
            session('tenant_user_id'), false
        );

        $this->showEscalationForm = false;
        $this->loadTicket();
    }


    public function startResolve()
    {
        Perm::require('manage_team');

        // An ordinary ticket just resolves. An INCIDENT must record its lesson first.
        if ($this->ticket->is_incident) {
            $this->showResolveForm = true;
        } else {
            Tickets::setStatus($this->tenantId, $this->ticketId, 'resolved');
            $this->loadTicket();
        }
    }

    public function resolve()
    {
        Perm::require('manage_team');

        // A.5.27 — the control auditors most often find missing.
        // An incident closed with no lesson is an incident that will happen again,
        // so we do not allow it. This is the one place the platform insists.
        if ($this->ticket->is_incident) {
            $this->validate([
                'rootCause'      => 'required|string|min:10',
                'lessonsLearned' => 'required|string|min:10',
            ], [
                'rootCause.required'      => __('Please record why this happened.'),
                'lessonsLearned.required' => __('Please record what you will change so it does not happen again.'),
            ]);

            DB::table('tickets')->where('id', $this->ticketId)
                ->where('tenant_id', $this->tenantId)          // ISOLATION
                ->update([
                    'root_cause'      => $this->rootCause,
                    'lessons_learned' => $this->lessonsLearned,
                    'contained_at'    => now(),
                    'updated_at'      => now(),
                ]);
        }

        Tickets::setStatus($this->tenantId, $this->ticketId, 'resolved');
        $this->showResolveForm = false;
        $this->loadTicket();
    }

    public function close()
    {
        Perm::require('manage_team');
        Tickets::setStatus($this->tenantId, $this->ticketId, 'closed');
        $this->loadTicket();
    }

    public function render()
    {
        return view('livewire.portal.ticket', ['ticket' => $this->ticket, 'comments' => $this->comments]);
    }

    public function loadAttachments()
    {
        $this->attachments = DB::table('ticket_attachments')
            ->where('ticket_id', $this->ticketId)
            ->where('tenant_id', $this->tenantId)          // ISOLATION
            ->orderBy('created_at')
            ->get();
    }

    public function uploadAttachment()
    {
        $canManage = Perm::can('manage_team');
        if (!$canManage && $this->ticket->raised_by !== session('tenant_user_id')) {
            abort(403);
        }

        $this->validate(['attachment' => 'required|file|max:20480']);   // 20 MB

        $stored = $this->attachment->store("tickets/{$this->tenantId}/{$this->ticketId}", 'local');
        $originalName = $this->attachment->getClientOriginalName();

        DB::table('ticket_attachments')->insert([
            'id' => (string) Str::uuid(),
            'ticket_id' => $this->ticketId,
            'tenant_id' => $this->tenantId,
            'uploaded_by' => session('tenant_user_id'),
            'original_name' => $originalName,
            'path' => $stored,
            'mime' => $this->attachment->getMimeType(),
            'size' => $this->attachment->getSize(),
            'is_evidence' => (bool) $this->ticket->is_incident,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // If this is already an incident, the file is EVIDENCE (A.5.28).
        if ($this->ticket->is_incident) {
            $this->pushToEvidence($stored, $originalName);
        }

        $this->reset('attachment');
        $this->loadAttachments();
    }

    public function download(string $attachmentId)
    {
        $a = DB::table('ticket_attachments')
            ->where('id', $attachmentId)
            ->where('tenant_id', $this->tenantId)          // ISOLATION — cannot fetch another tenant's file
            ->first();
        if (!$a) abort(404);

        return Storage::disk('local')->download($a->path, $a->original_name);
    }

    private function pushToEvidence(string $storedPath, string $originalName): void
    {
        $folderId = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)
            ->where('tool', 'ciso_assistant')->where('artifact_type', 'domain')
            ->value('external_id');
        if (!$folderId) return;

        try {
            app(\App\Clients\CisoClient::class)->uploadEvidence(
                $folderId,
                "Incident #{$this->ticket->number}: {$originalName}",
                Storage::disk('local')->path($storedPath),
                $originalName
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Incident evidence push failed: {$e->getMessage()}");
        }
    }    

}
