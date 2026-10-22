<?php
namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use App\Clients\WazuhClient;
use App\Support\Perm;
use App\Support\Helpdesk\Tickets;

#[Layout('components.layouts.portal')]
class SecurityAlerts extends Component
{
    public string $tenantId;
    public int $days = 7;

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); }
    }

    public function setDays(int $d)
    {
        $this->days = $d;
    }

    public function render()
    {
        $group = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)          // ISOLATION
            ->where('tool', 'wazuh')
            ->where('artifact_type', 'agent_group')
            ->value('external_id');

        // Four DISTINCT states. Never conflate them — each means something different,
        // and only one of them means "you are protected and nothing is wrong."
        $state = 'ok';
        $alerts = [];
        $agentCount = 0;

        if (!$group) {
            $state = 'not_configured';
        } else {
            try {
                $c = app(WazuhClient::class);

                if (!$c->indexerHealthy()) {
                    // The alert store is down or read-only (e.g. disk full).
                    // Monitoring may have SILENTLY STOPPED. Never show green.
                    $state = 'store_unavailable';
                } else {
                    $token = $c->token();

                    if (!$c->groupExists($token, $group)) {
                        // Our records claim a group that Wazuh does not have.
                        $state = 'drift';
                    } else {
                        $ids = $c->agentIdsInGroup($token, $group);
                        $agentCount = count($ids);

                        if ($agentCount === 0) {
                            // Configured, but nothing is being watched.
                            $state = 'no_agents';
                        } else {
                            $alerts = $this->summarise(
                                $c->alertsForAgents($ids, $this->days, 200)
                            );
                        }
                    }
                }
            } catch (\Throwable $e) {
                $state = 'unreachable';
            }
        }

        return view('livewire.portal.security-alerts', [
            'state' => $state,
            'alerts' => $alerts,
            'agentCount' => $agentCount,
        ]);
    }

    private function summarise(array $raw): array
    {
        $rows = [];
        foreach ($raw as $a) {
            $level = (int) ($a['rule']['level'] ?? 0);
            $rows[] = [
                'when' => substr((string) ($a['@timestamp'] ?? ''), 0, 19),
                'level' => $level,
                'severity' => $level >= 12 ? 'high' : ($level >= 7 ? 'medium' : 'low'),
                'description' => $a['rule']['description'] ?? 'Security event',
                'agent' => $a['agent']['name'] ?? '?',
            ];
        }
        return $rows;
    }

    public function incidentFromAlert(array $alert)
    {
        Perm::require('manage_team');

        $id = Tickets::raise($this->tenantId, [
            'subject' => 'Security alert: ' . ($alert['description'] ?? 'event'),
            'body' => "Recorded from monitoring.\n\nDevice: " . ($alert['agent'] ?? '?')
                    . "\nWhen: " . ($alert['when'] ?? now()),
            'priority' => ($alert['severity'] ?? '') === 'high' ? 'urgent' : 'high',
        ], session('user_id'));

        DB::table('tickets')->where('id', $id)->update([
            'is_incident' => true,
            'incident_severity' => ($alert['severity'] ?? 'medium'),
            'detected_at' => $alert['when'] ?? now(),
            'linked_alert_id' => $alert['id'] ?? null,
            'updated_at' => now(),
        ]);

        return redirect("/portal/ticket/{$id}");
    }
}