<?php
namespace App\Livewire\Portal;
 
use Livewire\Component;
use Illuminate\Support\Facades\DB;
 
class LiveQuery extends Component
{
    public string $tenantId;
    public string $sql = '';
    public ?array $result = null;
    public ?string $error = null;
    public bool $running = false;
    public string $ranLabel = '';
 
    // Curated, tested, plain-language questions.
    public array $presets = [
        'encryption' => ['label' => 'Is disk encryption turned on?',      'sql' => 'SELECT * FROM disk_encryption;'],
        'uptime'     => ['label' => 'Which machines need a restart?',      'sql' => 'SELECT days, hours FROM uptime;'],
        'os'         => ['label' => 'What operating system and version?',  'sql' => 'SELECT name, version FROM os_version;'],
        'loggedin'   => ['label' => 'Who is logged in right now?',         'sql' => 'SELECT user, host, time FROM logged_in_users;'],
    ];
 
    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { return redirect('/login'); }
    }
 
    public function runPreset(string $key)
    {
        if (!isset($this->presets[$key])) return;
        $this->sql = $this->presets[$key]['sql'];
        $this->ranLabel = $this->presets[$key]['label'];
        $this->run();
    }
 
    public function runCustom()
    {
        $this->ranLabel = __('Custom question');
        $this->run();
    }
 
    private function run()
    {
        $this->error = null;
        $this->result = null;
 
        $sql = trim($this->sql);
        if ($sql === '') { $this->error = __('Please choose or enter a question.'); return; }
 
        // Guard: osquery is read-only, but refuse anything that is not a SELECT as defence-in-depth.
        if (!preg_match('/^\s*SELECT\b/i', $sql) || preg_match('/;\s*\S/', rtrim($sql, "; \t\n"))) {
            $this->error = __('Only single read-only questions are allowed.');
            return;
        }
 
        // --- Resolve THIS tenant's hosts. The query targets only these. ---
        $secret = DB::table('tenant_artifacts')
            ->where('tenant_id', $this->tenantId)      // ISOLATION
            ->where('tool', 'fleet')
            ->where('artifact_type', 'enroll_secret')
            ->value('external_id');
 
        if (!$secret) {
            $this->error = __('No devices are enrolled for your organisation yet.');
            return;
        }
 
        $fleet = app(\App\Clients\FleetClient::class);
        $hosts = $fleet->hostsForTenant($secret);      // ONLY this tenant's hosts
        $hostIds = array_values(array_filter(array_map(fn ($h) => $h['id'] ?? null, $hosts)));
 
        if (empty($hostIds)) {
            $this->error = __('No devices are currently reporting for your organisation.');
            return;
        }
 
        // Audit BEFORE running — who asked what, of how many machines.
        \App\Support\Audit::log($this->tenantId, session('auth_email'),
            'fleet.live_query', 'run', 'fleet',
            ['question' => $this->ranLabel, 'sql' => $sql, 'host_count' => count($hostIds)]);
 
        $r = $fleet->runLiveQuery($hostIds, $sql);   // TARGETED at this tenant's IDs only
 
        if (!$r['ok']) {
            $this->error = match ($r['error']) {
                'no_hosts'    => __('No devices to ask.'),
                'unreachable' => __('We could not reach the device service just now. Please try again shortly.'),
                default       => __('The question could not be answered right now.'),
            };
            return;
        }
 
        $this->result = $r['rows'];   // [ ['host'=>.., 'columns'=>[ {col=>val}, ... ] ], ... ]
    }
 
    public function render()
    {
        return view('livewire.portal.live-query')->layout('components.layouts.portal');
    }
}
