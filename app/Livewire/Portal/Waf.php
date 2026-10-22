<?php
namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\Perm;
use App\Support\Audit;
use App\Support\Waf\Installer;

#[Layout('components.layouts.portal')]
class Waf extends Component
{
    public string $tenantId;

    // Add-a-server form
    public string $label = '';
    public string $hostname = '';
    public string $server_type = 'nginx';

    // The installer script currently being shown
    public ?string $showingScriptFor = null;
    public string $script = '';

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); }
    }

    public function addServer()
    {
        Perm::require('manage_team');

        $this->validate([
            'label' => 'required|string|max:100',
            'hostname' => 'required|string|max:200',
            'server_type' => 'in:nginx,apache,iis',
        ]);

        DB::table('waf_deployments')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'label' => $this->label,
            'hostname' => $this->hostname,
            'server_type' => $this->server_type,
            'deploy_path' => match ($this->server_type) {
                'nginx'  => 'modsec3_nginx',
                'apache' => 'modsec2_apache',
                'iis'    => 'nginx_proxy',      // the recommended path for IIS
            },
            // A server we have merely been TOLD about is NOT protected.
            // Never default to 'detection' — that would imply protection that does not exist.
            'mode' => 'pending',
            'from_fleet' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->reset(['label', 'hostname']);
        session()->flash('w_ok', __('Web server added. Now install the firewall on it.'));
    }

    public function showScript(string $id)
    {
        $d = DB::table('waf_deployments')
            ->where('id', $id)
            ->where('tenant_id', $this->tenantId)      // ISOLATION
            ->first();

        if (!$d) return;

        $this->showingScriptFor = $id;
        $this->script = Installer::scriptFor($d->deploy_path, ['domain' => $d->hostname]);
    }

    // The tenant confirms they ran the installer on their own server.
    public function markInstalled(string $id)
    {
        Perm::require('manage_team');

        DB::table('waf_deployments')
            ->where('id', $id)
            ->where('tenant_id', $this->tenantId)
            ->update([
                'mode' => 'detection',          // ALWAYS starts watching, never blocking
                'crs_version' => '4.16.0',
                'installed_at' => now(),
                'updated_at' => now(),
            ]);

        Audit::log($this->tenantId, session('auth_email'), 'waf.installed', 'success', null,
            ['deployment' => $id]);

        $this->showingScriptFor = null;
        $this->script = '';

        session()->flash('w_ok', __('Firewall recorded as installed, in watching mode. It is not blocking anything yet.'));
    }

    public function enableBlocking(string $id)
    {
        Perm::require('manage_team');

        DB::table('waf_deployments')
            ->where('id', $id)
            ->where('tenant_id', $this->tenantId)
            ->update(['mode' => 'blocking', 'updated_at' => now()]);

        Audit::log($this->tenantId, session('auth_email'), 'waf.blocking_enabled', 'success', null,
            ['deployment' => $id]);

        session()->flash('w_ok', __('Blocking enabled. Watch closely over the next few days — if legitimate users are turned away, switch back to watching.'));
    }

    public function backToDetection(string $id)
    {
        Perm::require('manage_team');

        DB::table('waf_deployments')
            ->where('id', $id)
            ->where('tenant_id', $this->tenantId)
            ->update(['mode' => 'detection', 'updated_at' => now()]);

        Audit::log($this->tenantId, session('auth_email'), 'waf.blocking_disabled', 'success', null,
            ['deployment' => $id]);

        session()->flash('w_ok', __('Back to watching mode. Nothing is being blocked.'));
    }

    public function removeServer(string $id)
    {
        Perm::require('manage_team');

        DB::table('waf_deployments')
            ->where('id', $id)
            ->where('tenant_id', $this->tenantId)      // ISOLATION
            ->delete();

        session()->flash('w_ok', __('Removed.'));
    }

    public function render()
    {
        return view('livewire.portal.waf', [
            'deployments' => DB::table('waf_deployments')
                ->where('tenant_id', $this->tenantId)  // ISOLATION
                ->orderBy('created_at')
                ->get(),
        ]);
    }
}