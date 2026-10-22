<?php
namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Perm;
use App\Support\TenantProfile;
use App\Support\Compliance\DocumentRegister;
use App\Support\Compliance\ComplianceSummary;


#[Layout('components.layouts.portal')]
class Compliance extends Component
{
    public string $tenantId;
    public array $justAdded = [];      // documents already covered by a newly added framework
    public bool $showImport = false;
    public string $importSearch = '';
    public bool $generating = false;
    public string $docFilter = '';
    
    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); }
    }

    public function addFramework(string $id, string $name)
    {
        Perm::require('manage_team');
 
        // What the tenant ALREADY has that satisfies this new standard (the reassuring bit).
        $this->justAdded = ComplianceSummary::alreadyCovered($this->tenantId, $id);
 
        $exists = DB::table('tenant_frameworks')
            ->where('tenant_id', $this->tenantId)
            ->where('framework_id', $id)->exists();
 
        if (!$exists) {
            DB::table('tenant_frameworks')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantId,
                'framework_id' => $id,
                'framework_name' => $name,
                'created_at' => now(), 'updated_at' => now(),
            ]);
 
            // How many documents this obligation entails — so it is not a surprise.
            $required = DB::table('document_control_map')
                ->where('framework_id', $id)->distinct()->count('doc_key');
 
            \App\Support\Audit::log($this->tenantId, session('auth_email'),
                'compliance.framework.added', 'success', 'ciso_assistant',
                ['framework' => $name, 'required_documents' => $required]);
 
            session()->flash('c_ok', $required > 0
                ? __(':name added. It requires :n documents; some you may already have.', ['name' => $name, 'n' => $required])
                : __(':name added.', ['name' => $name]));
        } else {
            session()->flash('c_ok', __(':name is already in your list.', ['name' => $name]));
        }
    }


     public function removeFramework(string $id)
    {
        Perm::require('manage_team');
 
        $fw = DB::table('tenant_frameworks')
            ->where('tenant_id', $this->tenantId)->where('framework_id', $id)->first();
        if (!$fw) { return; }
 
        // How much finalised work is mapped to this framework? Abandoning it loses that coverage.
        $docKeys = DB::table('document_control_map')->where('framework_id', $id)
            ->distinct()->pluck('doc_key');
        $finalised = DB::table('tenant_documents')
            ->where('tenant_id', $this->tenantId)
            ->whereIn('doc_key', $docKeys)
            ->where('status', 'final')->distinct()->count('doc_key');
 
        DB::table('tenant_frameworks')
            ->where('tenant_id', $this->tenantId)->where('framework_id', $id)->delete();
 
        $this->justAdded = [];
 
        \App\Support\Audit::log($this->tenantId, session('auth_email'),
            'compliance.framework.removed', 'success', 'ciso_assistant',
            ['framework' => $fw->framework_name, 'finalised_docs_affected' => $finalised]);
 
        session()->flash('c_ok', $finalised > 0
            ? __('Standard removed. :n finalised document(s) no longer count toward any standard unless re-added.', ['n' => $finalised])
            : __('Standard removed.'));
    }


    public function render()
    {
        $available = [];
        try {
            $available = app(\App\Clients\CisoClient::class)->frameworks();
        } catch (\Throwable $e) {
            // CISO unreachable — degrade gracefully.
        }

        $chosen = DB::table('tenant_frameworks')
            ->where('tenant_id', $this->tenantId)->get();

        // The importable catalogue — only when the panel is open, and always filtered.
        $importable = [];
        if ($this->showImport) {
            try {
                $loadedUrns = collect(app(\App\Clients\CisoClient::class)->frameworks())
                    ->pluck('urn')->filter()->all();
 
                $all = app(\App\Clients\CisoClient::class)->storedLibraries();
 
                $importable = collect($all)
                    // Hide ones already loaded — they belong in "Add", not "Import".
                    ->reject(fn ($lib) => in_array($lib['urn'] ?? '', $loadedUrns, true))
                    ->when($this->importSearch !== '', function ($c) {
                        $needle = strtolower($this->importSearch);
                        return $c->filter(fn ($lib) =>
                            str_contains(strtolower($lib['name'] ?? ''), $needle)
                            || str_contains(strtolower($lib['ref_id'] ?? ''), $needle)
                            || str_contains(strtolower($lib['provider'] ?? ''), $needle)
                        );
                    })
                    ->take(40)               // never render 268 rows at once
                    ->values()->all();
            } catch (\Throwable $e) {
                $importable = [];   // CISO unreachable — the view shows an honest message
            }
        }

        return view('livewire.portal.compliance', [
            'available' => $available,
            'chosen' => $chosen,
            'summaries' => ComplianceSummary::perFramework($this->tenantId),
            'register' => DocumentRegister::forTenant($this->tenantId),
            'docStatus' => DB::table('tenant_documents')
                ->where('tenant_id', $this->tenantId)      // ISOLATION
                ->orderByDesc('created_at')
                ->get()->groupBy('doc_key'),
            'profileReady' => TenantProfile::readyForDocuments($this->tenantId),
            'missing' => TenantProfile::missing($this->tenantId),
            'unverified' => ComplianceSummary::unverifiedCount(),
            'importable' => $importable,
        ]);
    }

    public function importFramework(string $storedLibraryId, string $name)
    {
        Perm::require('manage_team');
 
        $ok = app(\App\Clients\CisoClient::class)->importLibrary($storedLibraryId);
 
        \App\Support\Audit::log($this->tenantId, session('auth_email'),
            'compliance.framework.imported', $ok ? 'success' : 'failure', 'ciso_assistant',
            ['framework' => $name, 'stored_library' => $storedLibraryId]);
 
        if ($ok) {
            session()->flash('c_ok', __(':name is now available. You can add it below.', ['name' => $name]));
        } else {
            session()->flash('c_err', __('That standard could not be imported right now. Please try again.'));
        }
    }

    public function toggleImport()
    {
        $this->showImport = !$this->showImport;
        $this->importSearch = '';
    }
 
    public function updatingImportSearch()
    {
        // nothing to reset (no pagination here), but keep the hook for clarity
    }

    public function generateDocs(string $frameworkId, string $frameworkName)
    {
        Perm::require('manage_team');
 
        // Guard: only when this framework has no set yet.
        $has = DB::table('document_control_map')->where('framework_id', $frameworkId)->exists();
        if ($has) {
            session()->flash('c_err', __('This standard already has a document set.'));
            return;
        }
 
        \App\Jobs\GenerateFrameworkDocs::dispatch(
            $this->tenantId, $frameworkId, $frameworkName, session('auth_email')
        );
 
        session()->flash('c_ok', __('Drafting a document set for :f. This takes a minute; we will notify you when it is ready.', ['f' => $frameworkName]));
    }


}