<?php

namespace App\Livewire\Operator;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Countries;
use App\Support\OperatorAudit;

/**
 * Operator management of Marketplace providers.
 *
 * Providers never log in -- there is no provider portal, by design. Everything
 * about a provider is entered and maintained HERE, by the platform operator.
 * That is what keeps the catalogue trustworthy: an open portal means providers
 * writing their own listings under our brand, and one misleading entry damages
 * trust in every other entry on the page.
 *
 * TRUST STATUS CARRIES LEGAL WEIGHT. Presenting a provider as 'official
 * upstream' or an 'authorized partner' when the manufacturer has granted no
 * such authorisation is a misrepresentation, and it is made under our name.
 * Selecting either therefore requires confirming that written authorisation
 * exists and is on file -- two seconds of friction at precisely the moment a
 * mistake would be costly.
 */
#[Layout('components.layouts.operator')]
class MarketplaceProviders extends Component
{
    public string $search = '';
    public ?string $editingId = null;
    public bool $creating = false;

    // Form
    public string $legal_name = '';
    public string $display_name = '';
    public string $slug = '';
    public string $status = 'community';
    public string $lifecycle = 'pending';
    public string $description = '';
    public string $website = '';
    public string $contact_email = '';
    public string $contact_phone = '';
    public string $response_time = '';
    public array  $technologies = [];
    public array  $regions = [];
    public array  $languages = [];
    public array  $delivery_models = [];
    public bool   $authorisation_confirmed = false;

    public const STATUSES = [
        'official_upstream'  => ['Official upstream', 'The upstream project or vendor itself. Requires their written agreement.'],
        'authorized_partner' => ['Authorized partner', 'Formally authorised by the manufacturer. Requires written authorisation on file.'],
        'verified'           => ['Verified', 'We have checked their identity and capability. Not a manufacturer endorsement.'],
        'community'          => ['Community', 'Listed, not vetted beyond basic identity checks.'],
    ];

    public const LIFECYCLES = ['pending' => 'Pending', 'active' => 'Active', 'suspended' => 'Suspended'];

    public const LANGUAGES = ['en' => 'English', 'fr' => 'French', 'es' => 'Spanish', 'pt' => 'Portuguese', 'ar' => 'Arabic'];
    public const DELIVERY  = ['remote' => 'Remote', 'onsite' => 'On site', 'hybrid' => 'Hybrid'];

    /** Requires written authorisation on file before it may be selected. */
    public static function needsAuthorisation(string $status): bool
    {
        return in_array($status, ['official_upstream', 'authorized_partner'], true);
    }

    public function create(): void
    {
        $this->resetForm();
        $this->creating = true;
        $this->editingId = null;
    }

    public function edit(string $id): void
    {
        $p = DB::table('marketplace_providers')->where('id', $id)->first();
        if (!$p) { return; }

        $this->editingId = $id;
        $this->creating = false;

        foreach (['legal_name','display_name','slug','status','lifecycle','description',
                  'website','contact_email','contact_phone','response_time'] as $f) {
            $this->$f = (string) ($p->$f ?? '');
        }
        foreach (['technologies','regions','languages','delivery_models'] as $f) {
            $this->$f = json_decode($p->$f ?? '[]', true) ?: [];
        }

        // Already-authorised statuses stay confirmed unless the operator changes
        // them -- editing a phone number should not re-ask for paperwork.
        $this->authorisation_confirmed = self::needsAuthorisation($this->status);
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->creating = false;
        $this->editingId = null;
    }

    public function save(): void
    {
        $this->validate([
            'legal_name'    => 'required|string|max:200',
            'display_name'  => 'required|string|max:200',
            'slug'          => ['required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            'status'        => 'required|in:' . implode(',', array_keys(self::STATUSES)),
            'lifecycle'     => 'required|in:' . implode(',', array_keys(self::LIFECYCLES)),
            'description'   => 'required|string|min:20|max:2000',
            'contact_email' => 'required|email|max:255',
            'contact_phone' => ['nullable', 'string', 'max:40', 'regex:/^\+[0-9][0-9 ()\-]{7,20}$/'],
            'website'       => 'nullable|string|max:255',
            'response_time' => 'nullable|string|max:100',
        ], [
            'slug.regex' => __('Lowercase letters, numbers and hyphens only.'),
            'contact_phone.regex' => __('Include the country code, starting with +.'),
        ]);

        // The check that matters.
        if (self::needsAuthorisation($this->status) && !$this->authorisation_confirmed) {
            $this->addError('status', __('Confirm that written authorisation is on file before using this status.'));
            return;
        }

        $dupe = DB::table('marketplace_providers')->where('slug', $this->slug)
            ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId))
            ->exists();
        if ($dupe) {
            $this->addError('slug', __('That slug is already taken.'));
            return;
        }

        $values = [
            'legal_name' => $this->legal_name,
            'display_name' => $this->display_name,
            'slug' => $this->slug,
            'status' => $this->status,
            'lifecycle' => $this->lifecycle,
            'description' => $this->description,
            'website' => $this->website ?: null,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone ? preg_replace('/[^0-9+]/', '', $this->contact_phone) : null,
            'response_time' => $this->response_time ?: null,
            'technologies' => json_encode(array_values($this->technologies)),
            'regions' => json_encode(array_values($this->regions)),
            'languages' => json_encode(array_values($this->languages)),
            'delivery_models' => json_encode(array_values($this->delivery_models)),
            'updated_at' => now(),
        ];

        if ($this->editingId) {
            DB::table('marketplace_providers')->where('id', $this->editingId)->update($values);
            OperatorAudit::log('marketplace.provider_updated', ['slug' => $this->slug, 'status' => $this->status]);
        } else {
            DB::table('marketplace_providers')->insert($values + [
                'id' => (string) Str::uuid(), 'created_at' => now(),
            ]);
            OperatorAudit::log('marketplace.provider_created', ['slug' => $this->slug, 'status' => $this->status]);
        }

        $this->cancel();
    }

    private function resetForm(): void
    {
        $this->reset(['legal_name','display_name','slug','description','website','contact_email',
                      'contact_phone','response_time','technologies','regions','languages',
                      'delivery_models','authorisation_confirmed']);
        $this->status = 'community';
        $this->lifecycle = 'pending';
    }

    public function render()
    {
        $providers = DB::table('marketplace_providers as p')
            ->leftJoin('marketplace_services as s', 's.provider_id', '=', 'p.id')
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $w->where('p.display_name', 'ilike', '%' . $this->search . '%')
                  ->orWhere('p.legal_name', 'ilike', '%' . $this->search . '%');
            }))
            ->groupBy('p.id', 'p.display_name', 'p.legal_name', 'p.slug', 'p.status', 'p.lifecycle', 'p.contact_email')
            ->orderBy('p.display_name')
            ->select('p.id', 'p.display_name', 'p.legal_name', 'p.slug', 'p.status',
                     'p.lifecycle', 'p.contact_email', DB::raw('count(s.id) as service_count'))
            ->get();

        return view('livewire.operator.marketplace-providers', [
            'providers' => $providers,
            'statuses' => self::STATUSES,
            'lifecycles' => self::LIFECYCLES,
            'countries' => Countries::list(),
            'languages' => self::LANGUAGES,
            'deliveryModels' => self::DELIVERY,
            'technologies' => \App\Livewire\Portal\MarketplaceServices::TECHNOLOGIES,
        ]);
    }
}
