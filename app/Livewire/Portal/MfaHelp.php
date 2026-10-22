<?php
namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('components.layouts.portal')]
class MfaHelp extends Component
{
    public function render()
    {
        return view('livewire.portal.mfa-help');
    }
}