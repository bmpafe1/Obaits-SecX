<?php

namespace App\Livewire\Operator;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\OperatorAudit;
use App\Livewire\Portal\Trainings as Catalogue;

/**
 * Operator curation of the training catalogue.
 *
 * Tenants browse; only the operator adds or edits. Same reasoning as the
 * Marketplace: these are third-party courses shown under our brand, and one
 * misleading or dead listing damages trust in every other entry.
 *
 * LANGUAGES ARE REQUIRED and multiple are allowed. The tenant-facing filter
 * defaults to the languages a tenant can actually read, so a listing with no
 * language recorded would be invisible to everyone -- worse than not listing it.
 *
 * COST is numeric so it can be filtered and sorted. Zero means free, which is
 * common here; leaving it blank means "see the website", which is different and
 * also legitimate.
 */
#[Layout('components.layouts.operator')]
class Trainings extends Component
{
    public string $search = '';
    public string $categoryFilter = '';

    public ?string $editingId = null;
    public bool $creating = false;

    public string $category = 'security_awareness';
    public string $title = '';
    public string $description = '';
    public string $institution = '';
    public string $url = '';
    public array  $languages = [];
    public string $cost = '';
    public string $currency = 'USD';
    public string $duration = '';
    public string $level = '';
    public string $status = 'draft';

    public const LANGUAGES = [
        'en' => 'English', 'fr' => 'French', 'es' => 'Spanish',
        'pt' => 'Portuguese', 'ar' => 'Arabic', 'de' => 'German',
        'nl' => 'Dutch', 'it' => 'Italian',
    ];

    public const CURRENCIES = ['USD', 'EUR', 'XAF', 'XOF', 'GBP', 'ZAR', 'NGN', 'GHS', 'KES'];

    public const STATUSES = [
        'draft'     => ['Draft', 'Not visible to anyone.'],
        'published' => ['Published', 'Visible to tenants whose language matches.'],
        'suspended' => ['Suspended', 'Hidden. Use when a course is withdrawn or the link is dead.'],
    ];

    public function create(): void
    {
        $this->resetForm();
        $this->creating = true;
        $this->editingId = null;
    }

    public function edit(string $id): void
    {
        $t = DB::table('trainings')->where('id', $id)->first();
        if (!$t) { return; }

        $this->editingId = $id;
        $this->creating = false;

        foreach (['category','title','description','institution','url','duration','level','status'] as $f) {
            $this->$f = (string) ($t->$f ?? '');
        }
        $this->languages = json_decode($t->languages ?? '[]', true) ?: [];
        $this->cost = $t->cost === null ? '' : (string) (float) $t->cost;
        $this->currency = $t->currency ?: 'USD';
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
            'category'    => 'required|in:' . implode(',', array_keys(Catalogue::CATEGORIES)),
            'title'       => 'required|string|max:200',
            'description' => 'required|string|min:30|max:2000',
            'institution' => 'nullable|string|max:150',
            'url'         => 'required|url|max:500',
            'languages'   => 'required|array|min:1',
            'cost'        => 'nullable|numeric|min:0',
            'currency'    => 'required_unless:cost,|nullable|string|size:3',
            'duration'    => 'nullable|string|max:60',
            'level'       => 'nullable|in:' . implode(',', array_keys(Catalogue::LEVELS)),
            'status'      => 'required|in:' . implode(',', array_keys(self::STATUSES)),
        ], [
            'languages.required' => __('Choose at least one language, or nobody will see this listing.'),
        ]);

        $values = [
            'category' => $this->category,
            'title' => $this->title,
            'description' => $this->description,
            'institution' => $this->institution ?: null,
            'url' => $this->url,
            'languages' => json_encode(array_values($this->languages)),
            // REAL BUG FOUND 2026-08-28: entering 0 stored NULL, so a FREE
            // course displayed as "See website" -- exactly backwards for the
            // most common case here, and it sends people off to check a price
            // that does not exist.
            //
            // The old check was $this->cost === '' with a strict string
            // comparison, but Livewire does not necessarily deliver the value
            // as a string. trim() on a cast string handles every form the input
            // can arrive in, and "0" is then correctly distinguished from "".
            'cost' => trim((string) $this->cost) === '' ? null : (float) $this->cost,
            'currency' => trim((string) $this->cost) === '' ? null : $this->currency,
            'duration' => $this->duration ?: null,
            'level' => $this->level ?: null,
            'status' => $this->status,
            'updated_at' => now(),
        ];

        if ($this->editingId) {
            DB::table('trainings')->where('id', $this->editingId)->update($values);
            OperatorAudit::log('training.updated', ['training' => $this->editingId, 'title' => $this->title]);
        } else {
            DB::table('trainings')->insert($values + ['id' => (string) Str::uuid(), 'created_at' => now()]);
            OperatorAudit::log('training.created', ['title' => $this->title]);
        }

        $this->cancel();
    }

    private function resetForm(): void
    {
        $this->reset(['title','description','institution','url','languages','cost','duration','level']);
        $this->category = 'security_awareness';
        $this->currency = 'USD';
        $this->status = 'draft';
    }

    public function render()
    {
        $trainings = DB::table('trainings')
            ->when($this->search !== '', fn ($q) => $q->where(function ($w) {
                $w->where('title', 'ilike', '%' . $this->search . '%')
                  ->orWhere('institution', 'ilike', '%' . $this->search . '%');
            }))
            ->when($this->categoryFilter !== '', fn ($q) => $q->where('category', $this->categoryFilter))
            ->orderBy('category')->orderBy('title')
            ->get();

        return view('livewire.operator.trainings', [
            'trainings' => $trainings,
            'categories' => Catalogue::CATEGORIES,
            'levels' => Catalogue::LEVELS,
            'languageOptions' => self::LANGUAGES,
            'currencies' => self::CURRENCIES,
            'statuses' => self::STATUSES,
        ]);
    }
}
