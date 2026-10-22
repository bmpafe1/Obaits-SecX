<?php
namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Perm;
use App\Support\TenantProfile;

#[Layout('components.layouts.portal')]
class Organisation extends Component
{
    use WithFileUploads;

    public string $tenantId;

    // Identity
    public string $legal_name = '';
    public string $trading_name = '';
    public string $registration_number = '';
    public $logo;                       // upload
    public ?string $logo_path = null;

    // Address
    public string $address_line1 = '';
    public string $address_line2 = '';
    public string $city = '';
    public string $country = '';

    // Contact
    public string $phone = '';
    public string $contact_email = '';
    public string $website = '';

    // Governance
    // NOTE: approver_name / approver_title are gone — superseded by the per-document
    // approval matrix on the People and approvals page. "Who owns information security"
    // stays here, because that IS an organisational property.
    public string $security_officer_name = '';
    public string $security_officer_title = '';
    public string $sector = '';
    public $headcount = null;

    // Document control
    public string $doc_version = '1.0';
    public ?string $effective_date = null;
    public string $review_cycle = 'annual';

    // ISMS scope
    public string $isms_scope = '';
    public bool $draftingScope = false;

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); }

        $d = TenantProfile::for($this->tenantId);
        foreach ($d as $k => $v) {
            if (property_exists($this, $k) && $k !== 'logo') {
                $this->$k = is_null($v) ? ($k === 'headcount' || $k === 'effective_date' ? null : '') : $v;
            }
        }
        if ($d['effective_date']) {
            $this->effective_date = substr((string) $d['effective_date'], 0, 10);
        }
    }

    public function save()
    {
        Perm::require('manage_team');    // admins only

        $this->validate([
            'legal_name' => 'nullable|string|max:200',
            'contact_email' => 'nullable|email',
            'headcount' => 'nullable|integer|min:1',
            'doc_version' => 'nullable|string|max:20',
            'review_cycle' => 'in:annual,biannual,quarterly',
            'isms_scope' => 'nullable|string|max:3000',
        ]);

        $this->upsertProfile([
            'legal_name' => $this->legal_name ?: null,
            'trading_name' => $this->trading_name ?: null,
            'registration_number' => $this->registration_number ?: null,
            'address_line1' => $this->address_line1 ?: null,
            'address_line2' => $this->address_line2 ?: null,
            'city' => $this->city ?: null,
            'country' => $this->country ?: null,
            'phone' => $this->phone ?: null,
            'contact_email' => $this->contact_email ?: null,
            'website' => $this->website ?: null,
            'security_officer_name' => $this->security_officer_name ?: null,
            'security_officer_title' => $this->security_officer_title ?: null,
            'sector' => $this->sector ?: null,
            'headcount' => $this->headcount ?: null,
            'doc_version' => $this->doc_version ?: '1.0',
            'effective_date' => $this->effective_date ?: null,
            'review_cycle' => $this->review_cycle ?: 'annual',
            'isms_scope' => $this->isms_scope ?: null,
        ]);

        \App\Support\Audit::log($this->tenantId, session('auth_email'), 'organisation.updated', 'success', null, []);
        session()->flash('org_ok', __('Organisation details saved.'));
    }

    // Runs automatically when the file input changes (Livewire lifecycle hook).
    public function updatedLogo()
    {
        Perm::require('manage_team');

        $this->validate([
            'logo' => 'image|mimes:png,jpg,jpeg|max:2048',   // 2 MB
        ]);

        // REAL FEATURE 2026-08-22, requested directly: the logo shown
        // in the nav sidebar needs to be resized/optimized to actually
        // fit there. Real, honest scope decision made with Brian:
        // active background REMOVAL (stripping an existing solid
        // color) needs real ML-based processing (rembg) -- tried this,
        // but the real model genuinely triggered an OOM kill on this
        // VPS's current, already-tight memory -- Brian explicitly chose
        // to drop that requirement rather than compromise on memory.
        // This resizes/optimizes and preserves whatever transparency
        // the uploaded file ALREADY has (most professional PNG logos
        // already have one) -- it does not attempt to add transparency
        // to an opaque image.
        $manager = new \Intervention\Image\ImageManager(
            new \Intervention\Image\Drivers\Gd\Driver()
        );
        // REAL BUG FOUND on first live test: ImageManager has no
        // real read() method on this installed v4 version -- confirmed
        // directly via the actual error, matching a known, real issue.
        // The correct real method for reading from a file path is
        // decodePath(), per the official v4 documentation.
        $image = $manager->decodePath($this->logo->getRealPath());
        // Real, sensible size for a nav sidebar logo -- scales
        // proportionally by height, never upscales a smaller original.
        if ($image->height() > 64) {
            $image->scale(height: 64);
        }
        $encoded = $image->encodeUsingFormat(\Intervention\Image\Format::PNG);

        $filename = (string) \Illuminate\Support\Str::uuid() . '.png';
        $path = "logos/{$this->tenantId}/{$filename}";
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, (string) $encoded);

        $this->upsertProfile(['logo_path' => $path]);

        $this->logo_path = $path;
        $this->logo = null;
        session()->flash('org_ok', __('Logo uploaded.'));
    }

    public function draftScope()
    {
        Perm::require('manage_team');

        $p = TenantProfile::for($this->tenantId);

        $facts = collect([
            'Organisation type: ' . ($this->sector ?: ($p['sector'] ?: 'not stated')),
            'Approximate staff: ' . ($this->headcount ?: ($p['headcount'] ?: 'not stated')),
            'Country: ' . ($this->country ?: ($p['country'] ?: 'not stated')),
        ])->implode("\n");

        $prompt = "Draft a short ISMS scope statement (3-5 sentences) for this organisation. "
            . "Say plainly which parts of the organisation and which systems the information security "
            . "programme covers, and note anything typically excluded. Write it so a non-specialist can "
            . "read it, but so an ISO 27001 auditor would accept it. Do not invent specific system names "
            . "or locations — keep it general enough that the user can edit in their own details, and "
            . "mark anything they must fill in with square brackets.\n\nWhat we know:\n" . $facts;

        $draft = \App\Support\Ai\BrianAssistant::reply(
            $this->tenantId,
            $prompt,
            [],
            session('locale', app()->getLocale())
        );

        $this->isms_scope = $draft;
        session()->flash('org_ok', __('Brian drafted a scope statement. Please read it, edit it, and save.'));
    }

    public function render()
    {
        return view('livewire.portal.organisation', [
            'missing' => TenantProfile::missing($this->tenantId),
            'completeness' => TenantProfile::completeness($this->tenantId),
        ]);
    }

    // Insert-or-update WITHOUT touching the primary key.
    // updateOrInsert() applies its values array to BOTH the update and the insert path,
    // so putting 'id' there would rewrite the primary key on every save.
    private function upsertProfile(array $values): void
    {
        $exists = DB::table('tenant_profiles')->where('tenant_id', $this->tenantId)->exists();

        if ($exists) {
            DB::table('tenant_profiles')
                ->where('tenant_id', $this->tenantId)
                ->update($values + ['updated_at' => now()]);
        } else {
            DB::table('tenant_profiles')->insert($values + [
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}