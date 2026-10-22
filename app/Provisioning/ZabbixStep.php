<?php
namespace App\Provisioning;

use App\Clients\ZabbixClient;
use App\Support\Audit;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ZabbixStep
{
    public function __construct(private ZabbixClient $zabbix) {}

    public function run(object $tenant): void
    {
        $groupId = $this->zabbix->ensureTenantHostGroup($tenant->slug);

        if (!$groupId) {
            Audit::log($tenant->id, 'system', 'tenant.provision.zabbix', 'failure', 'zabbix',
                ['reason' => 'host group creation returned no id']);
            return;
        }

        $groupExists = DB::table('tenant_artifacts')
            ->where('tenant_id', $tenant->id)->where('tool', 'zabbix')
            ->where('artifact_type', 'host_group')->exists();
        if (!$groupExists) {
            DB::table('tenant_artifacts')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'tool' => 'zabbix',
                'artifact_type' => 'host_group',
                'external_id' => (string) $groupId,
                'metadata' => json_encode(['slug' => $tenant->slug, 'group_name' => "tenant-{$tenant->slug}"]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Real, random, per-tenant secret -- matching Fleet's own real,
        // established security philosophy (random secrets, never
        // guessable slugs) for exactly this kind of "prove which tenant
        // this device belongs to" purpose. Real, once-generated, reused
        // on every subsequent run rather than regenerated (regenerating
        // would silently break every already-enrolled real device's own
        // agent config, which has the OLD secret baked in).
        $existingRow = DB::table('tenant_artifacts')
            ->where('tenant_id', $tenant->id)->where('tool', 'zabbix')
            ->where('artifact_type', 'registration_secret')->first();

        $secret = $existingRow
            ? (json_decode($existingRow->metadata, true)['secret'] ?? null)
            : null;

        if (!$secret) {
            $secret = bin2hex(random_bytes(24));
            DB::table('tenant_artifacts')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'tool' => 'zabbix',
                'artifact_type' => 'registration_secret',
                'external_id' => 'n/a', // this artifact has no real external id of its own
                'metadata' => json_encode(['secret' => $secret]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $actionId = $this->zabbix->ensureTenantAutoRegistration($tenant->slug, $groupId, $secret);

        if (!$actionId) {
            Audit::log($tenant->id, 'system', 'tenant.provision.zabbix', 'failure', 'zabbix',
                ['reason' => 'auto-registration action creation returned no id']);
            return;
        }

        $actionExists = DB::table('tenant_artifacts')
            ->where('tenant_id', $tenant->id)->where('tool', 'zabbix')
            ->where('artifact_type', 'autoregistration_action')->exists();
        if (!$actionExists) {
            DB::table('tenant_artifacts')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'tool' => 'zabbix',
                'artifact_type' => 'autoregistration_action',
                'external_id' => (string) $actionId,
                'metadata' => json_encode(['slug' => $tenant->slug]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Audit::log($tenant->id, 'system', 'tenant.provision.zabbix', 'success', 'zabbix',
            ['host_group_id' => $groupId, 'autoregistration_action_id' => $actionId]);
    }
}
