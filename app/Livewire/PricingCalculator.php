<?php
namespace App\Livewire;

use Livewire\Component;

class PricingCalculator extends Component
{
    public int $assets = 10;

    // Adjust these to your real pricing.
    public float $perAssetMonthly = 3.0;
    public int $yearlyMonthsCharged = 10; // 2 months free on yearly

    public function render()
    {
        $monthly = $this->assets * $this->perAssetMonthly;
        $yearly = $monthly * $this->yearlyMonthsCharged;
        return view('livewire.pricing-calculator', [
            'monthly' => number_format($monthly, 2),
            'yearly' => number_format($yearly, 2),
        ]);
    }
}