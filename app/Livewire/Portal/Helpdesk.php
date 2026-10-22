<?php
namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use App\Support\Perm;
use App\Support\Helpdesk\Tickets;
use Livewire\WithPagination;

#[Layout('components.layouts.portal')]
class Helpdesk extends Component
{
    public string $tenantId;
    public string $subject = '';
    public string $body = '';
    public string $priority = 'normal';
    public string $category = 'other';

    // Filters
    public string $search = '';
    public string $statusFilter = '';
    public bool $incidentsOnly = false;
    public string $dateFrom = '';
    public string $dateTo = '';

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); }
    }

    public function raise()
    {
        // Any tenant user may raise a ticket.
        $this->validate([
            'subject' => 'required|string|max:200',
            'body' => 'required|string',
            'priority' => 'required|in:low,normal,high',
        ]);

        $id = Tickets::raise($this->tenantId, [
            'subject' => $this->subject,
            'body' => $this->body,
            'priority' => $this->priority,
        ], session('tenant_user_id'));

        $this->reset(['subject','body','priority']);
        session()->flash('helpdesk_ok', __('Ticket raised.'));
        return redirect('/portal/helpdesk/' . $id);

        $this->validate([
            'subject'  => 'required|string|max:200',
            'body'     => 'required|string',
            'category' => 'required|in:hardware,software,access,network,security,other',
            'priority' => 'required|in:low,normal,high,urgent',
        ]);

        $id = Tickets::raise($this->tenantId, [
            'subject'  => $this->subject,
            'body'     => $this->body,
            'category' => $this->category,
            'priority' => $this->priority,
        ], session('tenant_user_id'));

        $this->reset(['subject', 'body', 'category', 'priority']);
    
    }

    public function render()
    {
        $tickets = DB::table('tickets')
            ->where('tenant_id', $this->tenantId)
            ->when($this->search !== '', function ($q) {
                $q->where(function ($sub) {
                    $sub->where('subject', 'ilike', '%' . $this->search . '%')
                        ->orWhere('number', 'ilike', '%' . $this->search . '%');
                });
            })
            ->when($this->statusFilter !== '', function ($q) {
                $q->where('status', $this->statusFilter);
            })
            // The incident register on demand — this is what an auditor asks to see.
            ->when($this->incidentsOnly, function ($q) {
                $q->where('is_incident', true);
            })
            // An incident is filtered by when it was DETECTED (the audit-relevant date);
            // an ordinary ticket falls back to when it was created.
            ->when($this->dateFrom !== '', function ($q) {
                $q->whereDate(DB::raw('COALESCE(detected_at, created_at)'), '>=', $this->dateFrom);
            })
            ->when($this->dateTo !== '', function ($q) {
                $q->whereDate(DB::raw('COALESCE(detected_at, created_at)'), '<=', $this->dateTo);
            })
            ->orderByRaw("
                CASE status WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2
                            WHEN 'resolved' THEN 3 ELSE 4 END,
                is_incident DESC,
                created_at DESC
            ")
            ->paginate(15);

        return view('livewire.portal.helpdesk', compact('tickets'));
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingIncidentsOnly()
    {
        $this->resetPage();
    }

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
}
