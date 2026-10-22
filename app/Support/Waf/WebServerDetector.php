<?php
namespace App\Support\Waf;
 
use Illuminate\Support\Facades\DB;
 
class WebServerDetector
{
    // Returns e.g. ['host' => 'srv01', 'os' => 'Windows', 'server' => 'iis', 'path' => 'nginx_proxy']
    public static function forTenant(string $tenantId): array
    {
        $secret = DB::table('tenant_artifacts')->where('tenant_id', $tenantId)
            ->where('tool', 'fleet')->where('artifact_type', 'enroll_secret')->value('external_id');
        if (!$secret) return [];
 
        $out = [];
        try {
            foreach (app(\App\Clients\FleetClient::class)->hostsForTenant($secret) as $h) {
                $os = strtolower($h['platform'] ?? $h['os_version'] ?? '');
                $software = collect($h['software'] ?? [])->pluck('name')->map('strtolower');
 
                $server = null;
                if ($software->contains(fn ($s) => str_contains($s, 'nginx')))      $server = 'nginx';
                elseif ($software->contains(fn ($s) => str_contains($s, 'apache') || str_contains($s, 'httpd'))) $server = 'apache';
                elseif (str_contains($os, 'windows'))                                $server = 'iis';   // presumed; confirm with the user
 
                if (!$server) continue;
 
                $out[] = [
                    'host' => $h['hostname'] ?? $h['computer_name'] ?? 'unknown',
                    'os' => $h['platform'] ?? '',
                    'server' => $server,
                    // What we can actually deploy.
                    'path' => match ($server) {
                        'nginx'  => 'modsec3_nginx',
                        'apache' => 'modsec2_apache',
                        'iis'    => 'nginx_proxy',   // recommended; modsec2_iis is the alternative
                    },
                ];
            }
        } catch (\Throwable $e) {
            // Fleet unreachable — degrade, do not guess.
        }
        return $out;
    }
}
