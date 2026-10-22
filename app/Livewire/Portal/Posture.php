<?php
namespace App\Livewire\Portal;
 
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
 
#[Layout('components.layouts.portal')]
class Posture extends Component
{
    public string $tenantId;
    public ?string $group = null;
 
    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); }
 
        $this->group = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)
            ->where('tool', 'wazuh')
            ->where('artifact_type', 'agent_group')
            ->value('external_id');
    }
 
    public function render()
    {
        $posture = ['agents' => [], 'avg_score' => null, 'policies' => []];
        if ($this->group) {
            try {
                $posture = app(\App\Clients\WazuhClient::class)->postureForGroup($this->group);
            } catch (\Throwable $e) {
                // leave defaults; the view shows a friendly message
            }
        }
        return view('livewire.portal.posture', compact('posture'));
    }
}
