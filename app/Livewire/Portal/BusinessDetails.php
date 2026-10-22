<?php

namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Audit;

/**
 * The one-time "tell us about your business" step, shown at first login.
 *
 * WHY IT EXISTS SEPARATELY FROM REGISTRATION: signup already asks for six
 * things, and every additional required field costs conversions on a form
 * someone fills in before they have seen the product. This is asked once they
 * are committed, which gets better answers and fewer abandoned signups.
 *
 * ASKED EXACTLY ONCE, tracked by business_details_completed_at. A prompt that
 * reappears is a prompt people learn to dismiss.
 *
 * NOT SKIPPABLE, deliberately. The empty tenant_profiles table -- one blank row
 * across four tenants -- is what optional collection looks like in practice.
 * The step is short and the fields are genuinely used: sector and headcount let
 * a provider filter a referral, and the description is what a human reads.
 */
#[Layout('components.layouts.portal')]
class BusinessDetails extends Component
{
    public string $tenantId;

    public string $sector = '';
    public string $sectorOther = '';
    public string $headcount = '';
    public string $description = '';

    public const SECTORS = [
        'financial_services' => 'Financial services',
        'insurance'          => 'Insurance',
        'healthcare'         => 'Healthcare',
        'education'          => 'Education',
        'government'         => 'Government and public sector',
        'telecoms'           => 'Telecommunications',
        'energy'             => 'Energy and utilities',
        'manufacturing'      => 'Manufacturing',
        'retail'             => 'Retail and e-commerce',
        'logistics'          => 'Transport and logistics',
        'construction'       => 'Construction and real estate',
        'agriculture'        => 'Agriculture and agribusiness',
        'mining'             => 'Mining and extraction',
        'professional'       => 'Professional services',
        'technology'         => 'Technology and software',
        'ngo'                => 'NGO and non-profit',
        'hospitality'        => 'Hospitality and tourism',
        'media'              => 'Media and publishing',
        'other'              => 'Something else',
    ];

    public const HEADCOUNTS = [
        '1-10'    => '1 to 10 people',
        '11-50'   => '11 to 50 people',
        '51-200'  => '51 to 200 people',
        '201-500' => '201 to 500 people',
        '500+'    => 'More than 500 people',
    ];

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { return redirect('/login'); }

        // Already done -- never show this twice.
        $done = DB::table('tenant_profiles')
            ->where('tenant_id', $this->tenantId)
            ->value('business_details_completed_at');

        if ($done) { return redirect('/dashboard'); }
    }

    public function save()
    {
        $this->validate([
            'sector'      => 'required|string|in:' . implode(',', array_keys(self::SECTORS)),
            'sectorOther' => 'required_if:sector,other|nullable|string|max:80',
            'headcount'   => 'required|string|in:' . implode(',', array_keys(self::HEADCOUNTS)),
            // Long enough to actually say something. "IT" helps nobody.
            'description' => 'required|string|min:40|max:1000',
        ], [], [
            'sectorOther' => __('sector'),
        ]);

        $headcountValue = (int) match ($this->headcount) {
            '1-10' => 10, '11-50' => 50, '51-200' => 200, '201-500' => 500, default => 1000,
        };

        $exists = DB::table('tenant_profiles')->where('tenant_id', $this->tenantId)->exists();

        $values = [
            'sector' => $this->sector === 'other' ? $this->sectorOther : self::SECTORS[$this->sector],
            'headcount' => $headcountValue,
            'description' => $this->description,
            'business_details_completed_at' => now(),
            'updated_at' => now(),
        ];

        if ($exists) {
            DB::table('tenant_profiles')->where('tenant_id', $this->tenantId)->update($values);
        } else {
            // Registrations predating profile seeding have no row at all.
            DB::table('tenant_profiles')->insert($values + [
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantId,
                'created_at' => now(),
            ]);
        }

        Audit::log($this->tenantId, 'portal', 'business_details.completed', 'success', null,
            ['sector' => $values['sector'], 'headcount' => $this->headcount]);

        return redirect('/dashboard');
    }

    public function render()
    {
        return view('livewire.portal.business-details', [
            'sectors' => self::SECTORS,
            'headcounts' => self::HEADCOUNTS,
        ]);
    }
}
