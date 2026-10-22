<?php

namespace App\Livewire\Portal;

use Livewire\Component;
use Illuminate\Support\Facades\DB;

class MonitoringEvidence extends Component
{
    public string $tenantId;
    public int $days = 90;
    public ?string $openRef = null;       // "pci_dss|10.2.5"
    public ?array $detail = null;

    // Wazuh tags rules against these framework versions. Stating the version is
    // not decoration: PCI DSS renumbered between 3.2.1 and 4.0, so a reference
    // here is NOT necessarily the same requirement in a tenant's own framework.
    public array $frameworkMeta = [
        'pci_dss'     => ['label' => 'PCI DSS',      'version' => '3.2.1', 'caveat' => true],
        'hipaa'       => ['label' => 'HIPAA',        'version' => '45 CFR 164', 'caveat' => false],
        'gdpr'        => ['label' => 'GDPR',         'version' => 'Regulation (EU) 2016/679', 'caveat' => false],
        'nist_800_53' => ['label' => 'NIST 800-53',  'version' => 'Rev. 4/5 control families', 'caveat' => false],
        'tsc'         => ['label' => 'SOC 2 (TSC)',  'version' => 'Trust Services Criteria', 'caveat' => false],
    ];

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { return redirect('/login'); }
    }

    public function setDays(int $d) { $this->days = $d; $this->openRef = null; $this->detail = null; }

    public function openControl(string $framework, string $ref)
    {
        $key = $framework . '|' . $ref;
        if ($this->openRef === $key) { $this->openRef = null; $this->detail = null; return; }

        $this->openRef = $key;
        $this->detail  = null;

        $ids = $this->agentIds();
        if ($ids) {
            $this->detail = app(\App\Clients\WazuhClient::class)
                ->evidenceForControl($ids, $framework, $ref, $this->days);
        }
    }

    private function agentIds(): array
    {
        // ISOLATION: only agents in this tenant's Wazuh group.
        $group = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)
            ->where('tool', 'wazuh')
            ->where('artifact_type', 'agent_group')
            ->value('external_id');

        if (!$group) return [];

        try {
            $w = app(\App\Clients\WazuhClient::class);
            return $w->agentIdsInGroup($w->token(), $group);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function render()
    {
        $ids = $this->agentIds();
        $evidence = [];
        $reachable = true;

        if ($ids) {
            try {
                $evidence = app(\App\Clients\WazuhClient::class)
                    ->complianceEvidenceForAgents($ids, $this->days);
            } catch (\Throwable $e) {
                $reachable = false;
            }
        }

        foreach ($evidence as $fw => $refs) {
            arsort($refs);
            $evidence[$fw] = $refs;
        }

        return view('livewire.portal.monitoring-evidence', [
            'evidence'   => $evidence,
            'hasAgents'  => !empty($ids),
            'agentCount' => count($ids),
            'reachable'  => $reachable,
        ])->layout('components.layouts.portal');
    }
}