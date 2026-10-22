<?php

namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

/**
 * Marketplace service catalogue -- the default Marketplace landing page.
 *
 * BROWSING IS OPEN to every tenant user. Discovery should not be gated;
 * submitting a request is (raise_marketplace_request, platform-admin only),
 * because that starts a commercial conversation on the organisation's behalf.
 *
 * Shows only PUBLISHED services from ACTIVE providers. A draft or suspended
 * listing must never be visible to a customer.
 *
 * NOTE there is no tenant scoping here, and that is correct: the catalogue is
 * the same for everyone. Tenant scoping applies to REQUESTS, not to listings.
 */
#[Layout('components.layouts.portal')]
class MarketplaceServices extends Component
{
    use WithPagination;

    public string $search = '';
    public string $category = '';
    public string $technology = '';
    public string $providerStatus = '';
    public string $region = '';
    // Set from ?provider=slug when arriving from the Providers page, so that
    // link filters rather than dumping the whole catalogue.
    public string $provider = '';

    public function updatingSearch() { $this->resetPage(); }
    public function updatingCategory() { $this->resetPage(); }
    public function updatingTechnology() { $this->resetPage(); }
    public function updatingProviderStatus() { $this->resetPage(); }
    public function updatingRegion() { $this->resetPage(); }

    public function mount()
    {
        // Arriving from the Providers page with a provider chosen.
        $slug = request()->query('provider');
        if ($slug && DB::table('marketplace_providers')->where('slug', $slug)->exists()) {
            $this->provider = $slug;
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'category', 'technology', 'providerStatus', 'region', 'provider']);
        $this->resetPage();
    }

    public function render()
    {
        $services = DB::table('marketplace_services as s')
            ->join('marketplace_providers as p', 'p.id', '=', 's.provider_id')
            ->where('s.status', 'published')
            ->where('p.lifecycle', 'active')
            ->when($this->search !== '', function ($q) {
                $q->where(function ($w) {
                    $w->where('s.title', 'ilike', '%' . $this->search . '%')
                      ->orWhere('s.description', 'ilike', '%' . $this->search . '%')
                      ->orWhere('p.display_name', 'ilike', '%' . $this->search . '%');
                });
            })
            ->when($this->category !== '', fn ($q) => $q->where('s.category', $this->category))
            ->when($this->technology !== '', fn ($q) => $q->where('s.technology', $this->technology))
            ->when($this->providerStatus !== '', fn ($q) => $q->where('p.status', $this->providerStatus))
            ->when($this->provider !== '', fn ($q) => $q->where('p.slug', $this->provider))
            ->when($this->region !== '', fn ($q) => $q->whereRaw("s.regions::text ilike ?", ['%' . $this->region . '%']))
            ->orderByRaw("CASE p.status
                WHEN 'official_upstream' THEN 1
                WHEN 'authorized_partner' THEN 2
                WHEN 'verified' THEN 3
                ELSE 4 END")
            ->orderBy('s.title')
            ->select(
                's.id', 's.title', 's.description', 's.category', 's.technology',
                's.pricing', 's.response_time', 's.delivery_models', 's.regions',
                'p.display_name as provider_name', 'p.slug as provider_slug', 'p.status as provider_status'
            )
            ->paginate(12);

        return view('livewire.portal.marketplace-services', [
            'services' => $services,
            'categories' => self::CATEGORIES,
            'technologies' => self::TECHNOLOGIES,
        ]);
    }

    public const CATEGORIES = [
        'professional_services' => 'Professional Services',
        'technical_support'     => 'Technical Support',
        'consulting'            => 'Consulting',
        'implementation'        => 'Implementation',
        'managed_services'      => 'Managed Services',
        'training'              => 'Training',
        'certification'         => 'Certification',
    ];

    public const TECHNOLOGIES = [
        'wazuh'     => 'Wazuh',
        'greenbone' => 'Greenbone / OpenVAS',
        'fleet'     => 'Fleet',
        'zabbix'    => 'Zabbix',
        'iris'      => 'DFIR-IRIS',
        'ciso'      => 'CISO Assistant',
        'zap'       => 'OWASP ZAP',
        'lightnvr'  => 'lightNVR',
        'obaits'    => 'Obaits platform',
    ];
}
