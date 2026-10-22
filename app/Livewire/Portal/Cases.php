<?php
namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use App\Clients\IrisClient;
use App\Support\Perm;

/**
 * Real, tenant-scoped case-management page. Shows ONLY this tenant's
 * own real IRIS cases (matched by their own IRIS customer_name), and
 * lets them create new ones -- reusing the exact same IrisClient the
 * provisioning step uses.
 */
#[Layout('components.layouts.portal')]
class Cases extends Component
{
    public string $tenantId;
    public string $newCaseName = '';
    public string $newCaseDescription = '';
    public ?int $customerId = null;
    public ?string $customerName = null;

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); return; }

        $artifact = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)
            ->where('tool', 'iris')
            ->where('artifact_type', 'customer')
            ->first();

        if ($artifact) {
            $this->customerId = (int) $artifact->external_id;
            $meta = json_decode($artifact->metadata, true);
            $this->customerName = $meta['customer_name'] ?? null;
        }
    }

    public function createCase()
    {
        Perm::require('manage_policies'); // reusing the closest existing real permission gate
        $this->validate([
            'newCaseName' => 'required|string|min:3',
            'newCaseDescription' => 'nullable|string',
        ]);

        if (!$this->customerId) {
            $this->addError('newCaseName', __('Your organization is not yet linked to the case management system. Please contact support.'));
            return;
        }

        $result = app(IrisClient::class)->createCase($this->customerId, $this->newCaseName, $this->newCaseDescription ?: '');
        if (!$result) {
            $this->addError('newCaseName', __('Could not create the case. Please try again.'));
            return;
        }

        session()->flash('case_ok', __('Case created successfully.'));
        $this->newCaseName = '';
        $this->newCaseDescription = '';
    }

    public function render()
    {
        $cases = [];
        if ($this->customerName) {
            $cases = app(IrisClient::class)->listCasesForCustomerName($this->customerName);
        }

        return view('livewire.portal.cases', [
            'cases' => $cases,
            'linked' => (bool) $this->customerId,
        ]);
    }
}
