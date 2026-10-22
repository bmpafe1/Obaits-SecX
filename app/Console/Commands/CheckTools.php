<?php
namespace App\Console\Commands;
 
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;
use App\Clients\KeycloakClient;
use App\Clients\FleetClient;
use App\Clients\WazuhClient;
use Illuminate\Support\Facades\Http;
 
class CheckTools extends Command
{
    protected $signature = 'tools:check';
    protected $description = 'Verify all provisioning tools are reachable and authenticated';
 
    public function handle(): int
    {
        $ok = true;
 
        // Keycloak
        try {
            $t = app(KeycloakClient::class)->token();
            $this->line($t ? '<info>Keycloak: OK</info>' : '<error>Keycloak: no token</error>');
            $ok = $ok && (bool)$t;
        } catch (\Throwable $e) { $this->error('Keycloak: ' . $e->getMessage()); $ok = false; }
 
        // Wazuh
        try {
            $t = app(WazuhClient::class)->token();
            $this->line($t ? '<info>Wazuh: OK</info>' : '<error>Wazuh: no token</error>');
            $ok = $ok && (bool)$t;
        } catch (\Throwable $e) { $this->error('Wazuh: ' . $e->getMessage()); $ok = false; }
 
        // Fleet
        try {
            $r = Http::withToken(config('services.fleet.api_token'))
                ->get(rtrim(config('services.fleet.base_url'), '/') . '/api/v1/fleet/hosts');
            $this->line($r->successful() ? '<info>Fleet: OK</info>' : '<error>Fleet: HTTP ' . $r->status() . '</error>');
            $ok = $ok && $r->successful();
        } catch (\Throwable $e) { $this->error('Fleet: ' . $e->getMessage()); $ok = false; }
 
        // CISO
        try {
            $r = Http::withHeaders(['Authorization' => 'Token ' . config('services.ciso.api_token')])
                ->get(rtrim(config('services.ciso.base_url'), '/') . '/api/folders/');
            $this->line($r->successful() ? '<info>CISO: OK</info>' : '<error>CISO: HTTP ' . $r->status() . '</error>');
            $ok = $ok && $r->successful();
        } catch (\Throwable $e) { $this->error('CISO: ' . $e->getMessage()); $ok = false; }
 
        $this->newLine();
        $this->line($ok ? '<info>All tools healthy.</info>' : '<error>One or more tools need attention.</error>');
        return $ok ? self::SUCCESS : self::FAILURE;

        $orphans = \Illuminate\Support\Facades\DB::table('tenants')
            ->whereNotIn('id', function ($q) {
                $q->select('tenant_id')->from('tenant_users');
            })
            ->where('status', 'active')
            ->count();
 
        if ($orphans === 0) {
            $this->info('Tenants: no orphans.');
        } else {
            $this->error("Tenants: {$orphans} active tenant(s) with NO users.");
        }

    }
}
