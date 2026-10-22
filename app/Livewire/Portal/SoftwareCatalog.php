<?php
namespace App\Livewire\Portal;
 
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Perm;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Storage;
use App\Support\MalwareScanner;

#[Layout('components.layouts.portal')]
class SoftwareCatalog extends Component
{
    public string $tenantId;
 
    public ?string $editingId = null;
    public string $name = '';
    public string $vendor = '';
    public string $version = '';
    public int $seats_owned = 0;
    public string $license_type = 'subscription';
    public ?string $annual_cost = null;
    public string $currency = 'USD';
    public ?string $renews_at = null;
    public string $owner = '';
    public bool $approved = true;
    public string $match_name = '';
    public bool $reconciled = false;
    public array $unlicensed = [];
    public array $unapproved = [];
    use WithPagination;

    // Filters
    public string $search = '';
    public string $approvedFilter = '';
    public string $dateFrom = '';
    public string $dateTo = '';

    // License key reveal (admin only, audited, never sent to the browser by default)
    public ?string $revealedKeyId = null;
    public ?string $revealedKey = null;
    public string $license_key = '';

    use WithFileUploads;
 
    public $upload;                       // the file being uploaded
    public string $uploadForId = '';      // which software_catalog row
    public string $uploadVersion = '';
    public string $uploadNotes = '';
 
    // Storage guardrails — an unbounded upload feature on a shared disk is an outage waiting to happen.
    private const MAX_FILE_BYTES   = 1000 * 1024 * 1024;   // 1000 MB per file
    private const TENANT_QUOTA_BYTES = 10 * 1024 * 1024 * 1024;  // 10 GB per tenant
    public string $expandedSoftware = '';   // which row's installers are open


    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); }
    }
 
    public function save()
    {
        Perm::require('manage_software');
        $this->validate([
            'name' => 'required|string|max:200',
            'seats_owned' => 'integer|min:0',
        ]);
 
        $data = [
            'tenant_id' => $this->tenantId,
            'name' => $this->name,
            'vendor' => $this->vendor ?: null,
            'version' => $this->version ?: null,
            'seats_owned' => $this->seats_owned,
            'license_type' => $this->license_type,
            'license_key' => $this->license_key ?: null,
            'annual_cost' => $this->annual_cost ?: null,
            'currency' => $this->currency ?: null,
            'renews_at' => $this->renews_at ?: null,
            'owner' => $this->owner ?: null,
            'approved' => $this->approved,
            'match_name' => $this->match_name ?: null,
            'updated_at' => now(),
        ];
 
        if ($this->editingId) {
            DB::table('software_catalog')->where('id', $this->editingId)
                ->where('tenant_id', $this->tenantId)->update($data);
            \App\Support\Audit::log($this->tenantId, session('auth_email'), 'software.updated', 'success', null, ['name' => $this->name]);
        } else {
            $data['id'] = (string) Str::uuid();
            $data['created_at'] = now();
            DB::table('software_catalog')->insert($data);
            \App\Support\Audit::log($this->tenantId, session('auth_email'), 'software.added', 'success', null, ['name' => $this->name]);
        }
 
        $this->resetForm();
        session()->flash('sw_ok', __('Saved.'));
    }
 
    public function edit(string $id)
    {
        Perm::require('manage_software');
        $row = DB::table('software_catalog')->where('id', $id)->where('tenant_id', $this->tenantId)->first();
        if (!$row) return;
        $this->editingId = $row->id;
        $this->name = $row->name;
        $this->vendor = $row->vendor ?? '';
        $this->version = $row->version ?? '';
        $this->seats_owned = $row->seats_owned;
        $this->license_type = $row->license_type ?? 'subscription';
        $this->license_key = $row->license_key ?? '';
        $this->annual_cost = $row->annual_cost;
        $this->currency = $row->currency ?? 'USD';
        $this->renews_at = $row->renews_at;
        $this->owner = $row->owner ?? '';
        $this->approved = (bool) $row->approved;
        $this->match_name = $row->match_name ?? '';
    }
 
    public function delete(string $id)
    {
        Perm::require('manage_software');
        DB::table('software_catalog')->where('id', $id)->where('tenant_id', $this->tenantId)->delete();
        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'software.deleted', 'success', null, ['id' => $id]);
        session()->flash('sw_ok', __('Deleted.'));
    }
 
    public function resetForm()
    {
        $this->reset(['editingId','name','vendor','version','seats_owned','annual_cost','renews_at','owner','match_name','license_key']);
        $this->license_type = 'subscription';
        $this->currency = 'USD';
        $this->approved = true;
    }

    public function reconcile(\App\Clients\FleetClient $fleet)
    {
        Perm::require('manage_software');

        // Resolve this tenant's Fleet enroll secret (real schema).
        $secret = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)
            ->where('tool', 'fleet')
            ->where('artifact_type', 'enroll_secret')
            ->value('external_id');

        if (!$secret) {
            session()->flash('sw_ok', __('Fleet is not set up for this tenant.'));
            return;
        }

        try {
            $installed = $fleet->softwareForTenant($secret);
        } catch (\Throwable $e) {
            session()->flash('sw_ok', __('Could not reach Fleet for reconciliation right now.'));
            return;
        }

        $catalog = DB::table('software_catalog')->where('tenant_id', $this->tenantId)->get();
        $catByName = [];
        foreach ($catalog as $c) {
            $catByName[strtolower($c->match_name ?: $c->name)] = $c;
        }

        $this->unlicensed = [];
        $this->unapproved = [];

        foreach ($installed as $sw) {
            $match = $catByName[strtolower($sw['name'])] ?? null;
            if (!$match) {
                $this->unapproved[] = ['name' => $sw['name'], 'version' => $sw['version'], 'hosts' => $sw['hosts_count']];
                continue;
            }
            if (!$match->approved) {
                $this->unapproved[] = ['name' => $sw['name'], 'version' => $sw['version'], 'hosts' => $sw['hosts_count'], 'note' => __('marked not approved')];
            }
            if ($match->seats_owned > 0 && $sw['hosts_count'] > $match->seats_owned) {
                $this->unlicensed[] = [
                    'name' => $match->name,
                    'owned' => $match->seats_owned,
                    'installed' => $sw['hosts_count'],
                    'over' => $sw['hosts_count'] - $match->seats_owned,
                ];
            }
        }

        $this->reconciled = true;
        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'software.reconciled', 'success', 'fleet',
            ['unlicensed' => count($this->unlicensed), 'unapproved' => count($this->unapproved)]);
    }

    public function render()
    {
        $items = DB::table('software_catalog')
            ->where('tenant_id', $this->tenantId)
            ->when($this->search !== '', function ($q) {
                $q->where(function ($sub) {
                    $sub->where('name', 'ilike', '%' . $this->search . '%')
                        ->orWhere('vendor', 'ilike', '%' . $this->search . '%')
                        ->orWhere('owner', 'ilike', '%' . $this->search . '%');
                });
            })
            ->when($this->approvedFilter !== '', function ($q) {
                $q->where('approved', $this->approvedFilter === 'yes');
            })
            ->when($this->dateFrom !== '', function ($q) {
                $q->whereDate('created_at', '>=', $this->dateFrom);
            })
            ->when($this->dateTo !== '', function ($q) {
                $q->whereDate('created_at', '<=', $this->dateTo);
            })
            ->orderBy('name')
            ->paginate(15);

        $today = now()->toDateString();
        $soon  = now()->addDays(60)->toDateString();
        $files = DB::table('software_files')
            ->where('tenant_id', $this->tenantId)
            ->orderByDesc('created_at')->get()->groupBy('software_id');

        $storageUsed  = (int) DB::table('software_files')
            ->where('tenant_id', $this->tenantId)->sum('size');
        $storageQuota = 10 * 1024 * 1024 * 1024;   // 2 GB

        return view('livewire.portal.software-catalog', compact('items', 'today', 'soon','files','storageUsed','storageQuota'));
    }

    public function updatingSearch()
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

    /**
     * Reveal a licence key — tenant admin only, and audited.
     * The key is NEVER rendered in the page by default; it only crosses the wire
     * when an authorised person deliberately asks for it, and that moment is recorded.
     */
    public function revealKey(string $id)
    {
        \App\Support\Perm::require('manage_team');

        $row = DB::table('software_catalog')
            ->where('id', $id)
            ->where('tenant_id', $this->tenantId)   // isolation
            ->first();

        if (!$row || !$row->license_key) {
            return;
        }

        $this->revealedKeyId = $id;
        $this->revealedKey   = $row->license_key;

        // An auditor WILL ask who accessed licence keys. Now you can answer.
        \App\Support\Audit::log(
            $this->tenantId,
            session('auth_email'),
            'software.license_key.revealed',
            'success',
            'obaits',
            ['software' => $row->name, 'software_id' => $id]
        );
    }

    public function hideKey()
    {
        $this->revealedKeyId = null;
        $this->revealedKey   = null;
    }

    public function startUpload(string $softwareId)
    {
        \App\Support\Perm::require('manage_team');   // ADMIN ONLY — uploading an executable is code distribution
        $this->uploadForId = $softwareId;
        $this->reset(['upload', 'uploadVersion', 'uploadNotes']);
    }
 
    public function uploadFile()
    {
        // 1. Authorisation — administrators only.
        \App\Support\Perm::require('manage_team');
 
        $this->validate([
            'upload' => 'required|file|max:512000',   // 500 MB, in kilobytes
        ]);
 
        // 2. Ownership — the software entry must belong to THIS tenant.
        $software = DB::table('software_catalog')
            ->where('id', $this->expandedSoftware)
            ->where('tenant_id', $this->tenantId)
            ->first();
        if (!$software) { abort(404); }
 
        // 3. Quota — protect the disk. One tenant must not be able to fill it.
        $used = (int) DB::table('software_files')
            ->where('tenant_id', $this->tenantId)->sum('size');
        if ($used + $this->upload->getSize() > self::TENANT_QUOTA_BYTES) {
            $this->addError('upload', __('This would exceed your storage limit. Remove an old file first.'));
            return;
        }
 
        // 4. SCAN BEFORE STORING. The file is still in PHP's temp directory.
        $tmpPath = $this->upload->getRealPath();
        $scan = MalwareScanner::scan($tmpPath);
 
        if ($scan['status'] === 'infected') {
            // Reject, record the attempt, and NEVER store the file.
            \App\Support\Audit::log($this->tenantId, session('auth_email'),
                'software.file.rejected_malware', 'blocked', 'clamav',
                ['software' => $software->name, 'file' => $this->upload->getClientOriginalName(),
                 'detail' => $scan['detail']]);
 
            $this->addError('upload', __('This file was rejected: malware was detected in it. The upload has been recorded.'));
            $this->reset('upload');
            return;
        }
 
        if ($scan['status'] === 'error') {
            // If we cannot scan, we do not store. We do not pretend it was checked.
            $this->addError('upload', __('The file could not be scanned for malware, so it was not stored. Please try again, or contact support.'));
            $this->reset('upload');
            return;
        }
 
        // 5. Clean — compute the checksum and store, tenant-isolated.
        $sha256 = hash_file('sha256', $tmpPath);
          $sha256       = hash_file('sha256', $tmpPath);
        $originalName = $this->upload->getClientOriginalName();
        $mime         = $this->upload->getMimeType();
        $size         = $this->upload->getSize();

        $path = $this->upload->store("software/{$this->tenantId}", 'local');

        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('software_files')->insert([
            'id'            => $id,
            'tenant_id'     => $this->tenantId,
            'software_id'   => $software->id,
            'uploaded_by'   => session('tenant_user_id'),
            'original_name' => $originalName,
            'path'          => $path,
            'mime'          => $mime,
            'size'          => $size,
            'sha256'        => $sha256,
            'scan_status'   => 'clean',
            'scan_detail'   => $scan['detail'],
            'version'       => $this->uploadVersion ?: null,
            'notes'         => $this->uploadNotes ?: null,
            'created_at'    => now(), 'updated_at' => now(),
        ]);
 
        \App\Support\Audit::log($this->tenantId, session('auth_email'),
            'software.file.uploaded', 'success', 'obaits',
            ['software' => $software->name, 'file' => $originalName,
             'sha256' => $sha256, 'size' => $size]);
 
        $this->reset(['upload', 'expandedSoftware', 'uploadVersion', 'uploadNotes']);
        session()->flash('sw_ok', __('File uploaded and scanned. No threats detected.'));
    }

    public function download(string $fileId)
    {
        // Analysts CAN download (they install software); only admins can upload.
        \App\Support\Perm::require('manage_software');
 
        $file = DB::table('software_files')
            ->where('id', $fileId)
            ->where('tenant_id', $this->tenantId)     // isolation
            ->first();
        if (!$file) { abort(404); }
 
        // Every download is recorded. After an incident, "who took this?" is the first question.
        \App\Support\Audit::log($this->tenantId, session('auth_email'),
            'software.file.downloaded', 'success', 'obaits',
            ['file' => $file->original_name, 'sha256' => $file->sha256]);
 
        return Storage::disk('local')->download($file->path, $file->original_name);
    }
 
    public function deleteFile(string $fileId)
    {
        \App\Support\Perm::require('manage_team');
 
        $file = DB::table('software_files')
            ->where('id', $fileId)->where('tenant_id', $this->tenantId)->first();
        if (!$file) { abort(404); }
 
        Storage::disk('local')->delete($file->path);
        DB::table('software_files')->where('id', $fileId)->delete();
 
        \App\Support\Audit::log($this->tenantId, session('auth_email'),
            'software.file.deleted', 'success', 'obaits', ['file' => $file->original_name]);
 
        session()->flash('sw_ok', __('File removed.'));
    }

    public function toggleFiles(string $id)
    {
        $this->expandedSoftware = ($this->expandedSoftware === $id) ? '' : $id;
    }
}
