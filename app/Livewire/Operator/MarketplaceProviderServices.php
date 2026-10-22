<?php

namespace App\Livewire\Operator;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Countries;
use App\Support\OperatorAudit;
use App\Livewire\Portal\MarketplaceServices as Catalogue;

/**
 * Operator management of one provider's service listings.
 *
 * Reached from the service count on the providers screen. Services are always
 * scoped to a provider -- a listing without one has nobody to refer a customer
 * to.
 *
 * STATUS IS WHAT MAKES A LISTING VISIBLE. Only 'published' services from
 * 'active' providers reach customers, so both must line up: publishing a
 * service under a pending provider does nothing, which the screen says plainly
 * rather than leaving the operator to wonder.
 */
#[Layout('components.layouts.operator')]
class MarketplaceProviderServices extends Component
{
    public string $providerId;

    public ?string $editingId = null;
    public bool $creating = false;

    public string $category = '';
    public string $technology = '';
    public string $title = '';
    public string $description = '';
    public string $pricing = '';
    public string $prerequisites = '';
    public string $response_time = '';
    public string $status = 'draft';
    public array  $delivery_models = [];
    public array  $regions = [];

    public const STATUSES = [
        'draft'     => ['Draft', 'Not visible to anyone. Work in progress.'],
        'published' => ['Published', 'Visible to customers, provided the provider is also active.'],
        'suspended' => ['Suspended', 'Hidden from customers. Use when a provider cannot currently deliver.'],
    ];

    public function mount(string $id)
    {
        $this->providerId = $id;
    }

    public function create(): void
    {
        $this->resetForm();
        $this->creating = true;
        $this->editingId = null;
    }

    public function edit(string $id): void
    {
        $s = DB::table('marketplace_services')
            ->where('id', $id)->where('provider_id', $this->providerId)->first();
        if (!$s) { return; }

        $this->editingId = $id;
        $this->creating = false;

        foreach (['category','technology','title','description','pricing',
                  'prerequisites','response_time','status'] as $f) {
            $this->$f = (string) ($s->$f ?? '');
        }
        foreach (['delivery_models','regions'] as $f) {
            $this->$f = json_decode($s->$f ?? '[]', true) ?: [];
        }
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
            'category'      => 'required|in:' . implode(',', array_keys(Catalogue::CATEGORIES)),
            'technology'    => 'nullable|in:' . implode(',', array_keys(Catalogue::TECHNOLOGIES)),
            'title'         => 'required|string|max:200',
            'description'   => 'required|string|min:30|max:4000',
            'status'        => 'required|in:' . implode(',', array_keys(self::STATUSES)),
            'pricing'       => 'nullable|string|max:120',
            'prerequisites' => 'nullable|string|max:1000',
            'response_time' => 'nullable|string|max:100',
        ]);

        $values = [
            'provider_id' => $this->providerId,
            'category' => $this->category,
            'technology' => $this->technology ?: null,
            'title' => $this->title,
            'description' => $this->description,
            'pricing' => $this->pricing ?: null,
            'prerequisites' => $this->prerequisites ?: null,
            'response_time' => $this->response_time ?: null,
            'status' => $this->status,
            'delivery_models' => json_encode(array_values($this->delivery_models)),
            'regions' => json_encode(array_values($this->regions)),
            'updated_at' => now(),
        ];

        if ($this->editingId) {
            DB::table('marketplace_services')
                ->where('id', $this->editingId)->where('provider_id', $this->providerId)
                ->update($values);
            OperatorAudit::log('marketplace.service_updated', ['service' => $this->editingId, 'title' => $this->title]);
        } else {
            DB::table('marketplace_services')->insert($values + [
                'id' => (string) Str::uuid(), 'created_at' => now(),
            ]);
            OperatorAudit::log('marketplace.service_created', ['provider' => $this->providerId, 'title' => $this->title]);
        }

        $this->cancel();
    }

    private function resetForm(): void
    {
        $this->reset(['category','technology','title','description','pricing',
                      'prerequisites','response_time','delivery_models','regions']);
        $this->status = 'draft';
    }

    public function render()
    {
        $provider = DB::table('marketplace_providers')->where('id', $this->providerId)->first();

        $services = DB::table('marketplace_services')
            ->where('provider_id', $this->providerId)
            ->orderBy('title')
            ->get();

        return view('livewire.operator.marketplace-provider-services', [
            'provider' => $provider,
            'services' => $services,
            'statuses' => self::STATUSES,
            'categories' => Catalogue::CATEGORIES,
            'technologies' => Catalogue::TECHNOLOGIES,
            'deliveryModels' => MarketplaceProviders::DELIVERY,
            'countries' => Countries::list(),
        ]);
    }
}
