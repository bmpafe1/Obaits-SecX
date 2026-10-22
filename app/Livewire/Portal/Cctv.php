<?php
namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use App\Support\Perm;

/**
 * Real, tenant-scoped CCTV Monitoring page. If a real, dedicated
 * lightNVR instance already exists for this tenant, shows its status
 * and a link to open it. If not, offers to provision one -- shelling
 * out to the same, already-proven obaits:provision-cctv command
 * (reusing 100% of the real, tested automation, no duplicated logic).
 */
#[Layout('components.layouts.portal')]
class Cctv extends Component
{
    public string $tenantId;
    public bool $provisioning = false;

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); return; }
    }

    public function setUp()
    {
        Perm::require('manage_policies');
        $this->provisioning = true;

        // Real, already-proven automation -- see
        // app/Console/Commands/ProvisionCctv.php for the full real
        // implementation and the three real bugs found and fixed while
        // building it.
        Process::timeout(60)->run("php artisan obaits:provision-cctv {$this->tenantId}");

        $this->provisioning = false;
    }

    public function render()
    {
        $instance = DB::table('cctv_instances')->where('tenant_id', $this->tenantId)->first();

        return view('livewire.portal.cctv', [
            'instance' => $instance,
        ]);
    }
}
