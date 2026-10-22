<?php
namespace App\Livewire\Portal\Dash;
 
use Livewire\Component;
use App\Clients\WazuhClient;
 
class SecurityPanel extends Component
{
    public bool $loaded = false;
    public array $data = [];
    public ?string $error = null;
 
    // Called by wire:init — runs AFTER first paint, so the page never waits for Wazuh.
    public function load()
    {
        try {
            $tenantId = session('tenant_id');
            $group = 'tenant-' . ($this->tenantSlug($tenantId));
 
            $c = app(WazuhClient::class);
            $token = $c->token();                              // may throw if Wazuh down
            $agentIds = $c->agentIdsInGroup($token, $group);
            $agents   = $c->agentsInGroup($group);
 
            // Severity counts from recent alerts, bucketed by Wazuh rule level.
            $alerts = $c->alertsForAgents($agentIds, 1, 500);  // last 1 day
            $sev = ['critical'=>0,'high'=>0,'medium'=>0,'low'=>0];
            foreach ($alerts as $a) {
                $lvl = (int) ($a['rule']['level'] ?? 0);
                if ($lvl >= 15) $sev['critical']++;
                elseif ($lvl >= 12) $sev['high']++;
                elseif ($lvl >= 7) $sev['medium']++;
                else $sev['low']++;
            }
 
            $active = collect($agents)->where('status','active')->count();
            $disconnected = collect($agents)->where('status','!=','active')->count();
 
            $this->data = [
                'severity' => $sev,
                'active' => $active,
                'disconnected' => $disconnected,
                'total' => count($agents),
            ];
        } catch (\Throwable $e) {
            $this->error = 'Could not reach monitoring right now.';
        }
        $this->loaded = true;
    }
 
    private function tenantSlug(string $tenantId): string
    {
        return \Illuminate\Support\Facades\DB::table('tenants')->where('id',$tenantId)->value('slug') ?? '';
    }
 
    public function render()
    {
        return view('livewire.portal.dash.security-panel');
    }
}
