<?php
namespace Tests\Feature;
 
use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use App\Clients\FleetClient;
 
class FleetIsolationTest extends TestCase
{
    public function test_hosts_for_tenant_returns_only_that_tenants_hosts(): void
    {
        // Fake Fleet returning hosts belonging to two different tenants.
        Http::fake([
            '*/api/v1/fleet/hosts' => Http::response([
                'hosts' => [
                    ['id' => 1, 'hostname' => 'alpha-laptop', 'enroll_secret_name' => 'tenant-alpha-xxx'],
                    ['id' => 2, 'hostname' => 'beta-laptop',  'enroll_secret_name' => 'tenant-beta-yyy'],
                    ['id' => 3, 'hostname' => 'alpha-server', 'enroll_secret_name' => 'tenant-alpha-xxx'],
                ],
            ], 200),
        ]);
 
        $fleet = new FleetClient();
        $alphaHosts = $fleet->hostsForTenant('tenant-alpha-xxx');
 
        // Alpha sees only its two hosts, never beta's.
        $this->assertCount(2, $alphaHosts);
        foreach ($alphaHosts as $h) {
            $this->assertSame('tenant-alpha-xxx', $h['enroll_secret_name']);
        }
    }
}
