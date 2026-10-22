<?php

namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use App\Support\Countries;

/**
 * The training catalogue a tenant browses.
 *
 * CURATED BY THE OPERATOR. Tenants browse; they do not contribute. Same
 * reasoning as the Marketplace: these are third-party courses shown under our
 * brand, and one misleading listing damages trust in the rest.
 *
 * LANGUAGE FILTERING IS THE POINT. A course in a language the tenant cannot
 * read is not merely unhelpful, it makes the whole catalogue look irrelevant.
 * By default they see courses in:
 *
 *   - their own preferred_language, which they chose at registration
 *   - English, which most security training is published in
 *   - any other language of their country, so a Cameroonian sees French AND
 *     English rather than one or the other
 *
 * The country widening matters because preferred_language is a single choice
 * and many of our markets are genuinely bilingual.
 *
 * "All languages" is always one click away -- the default is a helpful starting
 * point, not a restriction, and the country-to-language map is deliberately
 * approximate.
 */
#[Layout('components.layouts.portal')]
class Trainings extends Component
{
    use WithPagination;

    public string $tenantId;
    public string $category;

    public string $search = '';
    public string $level = '';
    public string $costFilter = '';     // '' | free | paid
    public bool   $allLanguages = false;

    public array $defaultLanguages = [];

    public const CATEGORIES = [
        'security_awareness'         => 'Security Awareness',
        'professional_certification' => 'Professional Security Certifications',
        'other'                      => 'Other Trainings',
    ];

    public const LEVELS = [
        'beginner'     => 'Beginner',
        'intermediate' => 'Intermediate',
        'advanced'     => 'Advanced',
    ];

    public function updatingSearch() { $this->resetPage(); }
    public function updatingLevel() { $this->resetPage(); }
    public function updatingCostFilter() { $this->resetPage(); }
    public function updatingAllLanguages() { $this->resetPage(); }

    public function mount(string $category = 'security_awareness')
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { return redirect('/login'); }

        $this->category = array_key_exists($category, self::CATEGORIES)
            ? $category
            : 'security_awareness';

        $this->defaultLanguages = $this->languagesForTenant();
    }

    /**
     * The tenant's own language, plus English, plus their country's languages.
     */
    private function languagesForTenant(): array
    {
        $tenant = DB::table('tenants')->where('id', $this->tenantId)->first(['preferred_language']);
        $country = DB::table('tenant_profiles')->where('tenant_id', $this->tenantId)->value('country');

        $langs = Countries::languages($country);          // already includes 'en'

        $preferred = $tenant->preferred_language ?? null;
        if ($preferred && !in_array($preferred, $langs, true)) {
            $langs[] = $preferred;
        }

        return array_values(array_unique($langs));
    }

    public function render()
    {
        $trainings = DB::table('trainings')
            ->where('status', 'published')
            ->where('category', $this->category)
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $w->where('title', 'ilike', '%' . $this->search . '%')
                  ->orWhere('description', 'ilike', '%' . $this->search . '%')
                  ->orWhere('institution', 'ilike', '%' . $this->search . '%');
            }))
            ->when($this->level !== '', fn ($q) => $q->where('level', $this->level))
            ->when($this->costFilter === 'free', fn ($q) => $q->where('cost', 0))
            ->when($this->costFilter === 'paid', fn ($q) => $q->where('cost', '>', 0))
            ->when(!$this->allLanguages, function ($q) {
                // Any overlap between the training's languages and the
                // tenant's. Postgres JSON containment on an array column.
                $q->where(function ($w) {
                    foreach ($this->defaultLanguages as $l) {
                        $w->orWhereRaw('languages::jsonb @> ?', [json_encode([$l])]);
                    }
                });
            })
            ->orderBy('cost')          // free first -- the most useful default
            ->orderBy('title')
            ->paginate(12);

        // How many are hidden by the language filter, so the tenant knows the
        // "all languages" toggle is worth pressing.
        $hidden = 0;
        if (!$this->allLanguages) {
            $total = DB::table('trainings')
                ->where('status', 'published')->where('category', $this->category)->count();
            $hidden = max(0, $total - DB::table('trainings')
                ->where('status', 'published')->where('category', $this->category)
                ->where(function ($w) {
                    foreach ($this->defaultLanguages as $l) {
                        $w->orWhereRaw('languages::jsonb @> ?', [json_encode([$l])]);
                    }
                })->count());
        }

        return view('livewire.portal.trainings', [
            'trainings' => $trainings,
            'categories' => self::CATEGORIES,
            'levels' => self::LEVELS,
            'hiddenByLanguage' => $hidden,
        ]);
    }
}
