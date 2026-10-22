<?php

namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use App\Support\Countries;

/**
 * Browse the Marketplace by PROVIDER rather than by service.
 *
 * The services catalogue answers "who can help me with Wazuh". This answers
 * "who is available to me at all", which is the question a customer asks when
 * they do not yet know what they need -- and it is where the trust hierarchy
 * becomes legible: official upstream, authorized partner, verified, community.
 *
 * Shows only ACTIVE providers with at least one PUBLISHED service. A provider
 * with nothing to offer is not useful to a customer, and listing them would
 * make the catalogue look larger than it is.
 *
 * Regions are filterable because a provider who cannot work in the customer's
 * country is noise. The default is the tenant's own country, widened by nothing
 * -- unlike Trainings, where language has a natural fallback, a provider either
 * covers your country or does not.
 */
#[Layout('components.layouts.portal')]
class MarketplaceProviders extends Component
{
    public string $tenantId;
    public string $search = '';
    public string $technology = '';
    public string $statusFilter = '';
    public bool $myCountryOnly = true;
    public ?string $tenantCountry = null;

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { return redirect('/login'); }

        $this->tenantCountry = DB::table('tenant_profiles')
            ->where('tenant_id', $this->tenantId)->value('country');

        // Nothing to filter on if we do not know where they are.
        if (!$this->tenantCountry) {
            $this->myCountryOnly = false;
        }
    }

    public function render()
    {
        $providers = DB::table('marketplace_providers as p')
            ->join('marketplace_services as s', function ($j) {
                $j->on('s.provider_id', '=', 'p.id')->where('s.status', '=', 'published');
            })
            ->where('p.lifecycle', 'active')
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $w->where('p.display_name', 'ilike', '%' . $this->search . '%')
                  ->orWhere('p.description', 'ilike', '%' . $this->search . '%');
            }))
            ->when($this->technology !== '', fn ($q) =>
                $q->whereRaw('p.technologies::jsonb @> ?', [json_encode([$this->technology])]))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('p.status', $this->statusFilter))
            ->when($this->myCountryOnly && $this->tenantCountry, fn ($q) =>
                $q->whereRaw('p.regions::jsonb @> ?', [json_encode([$this->tenantCountry])]))
            // GROUP BY THE PRIMARY KEY ALONE.
            //
            // REAL BUG FOUND 2026-08-29: listing every selected column here
            // included technologies, regions and languages, which are json --
            // and Postgres cannot group by json, because the type has no
            // equality operator. The page returned a 500.
            //
            // Grouping by p.id is both correct and sufficient: Postgres allows
            // selecting any column of a table grouped by its primary key, since
            // the key functionally determines the rest.
            ->groupBy('p.id')
            ->orderByRaw("CASE p.status
                WHEN 'official_upstream' THEN 1
                WHEN 'authorized_partner' THEN 2
                WHEN 'verified' THEN 3
                ELSE 4 END")
            ->orderBy('p.display_name')
            ->select('p.id', 'p.display_name', 'p.slug', 'p.status', 'p.description',
                     'p.website', 'p.response_time', 'p.technologies', 'p.regions', 'p.languages',
                     DB::raw('count(s.id) as service_count'))
            ->get();

        return view('livewire.portal.marketplace-providers', [
            'providers' => $providers,
            'technologies' => MarketplaceServices::TECHNOLOGIES,
            'countryName' => Countries::name($this->tenantCountry),
        ]);
    }
}
