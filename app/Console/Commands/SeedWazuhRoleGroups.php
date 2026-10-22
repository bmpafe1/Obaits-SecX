<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Creates (if missing) and configures every Wazuh role group — both Tier 1
 * (base machine-type: linux-servers, windows-client, etc.) and Tier 2
 * (software role add-ons: postgres-db, etc.) — from the real profile files
 * in ops/wazuh/profiles/*.xml.
 *
 * GLOBAL groups, not per-tenant: a role's collection profile (what
 * Postgres logs to watch) isn't tenant-specific, only which devices join
 * it varies. Confirmed with Brian before building. Tenant isolation lives
 * entirely in how the portal queries devices by tenant, same as every
 * other Wazuh group in this project — these role groups are purely a
 * config-distribution mechanism, never a data boundary.
 *
 * Idempotent and safe to re-run: creating an already-existing group is a
 * no-op (WazuhClient::ensureGroup), and pushing config is a full
 * overwrite of that group's config every time — intentional, so editing
 * a profile file and re-running this command is how a profile gets
 * updated going forward.
 */
#[Signature('wazuh:seed-role-groups')]
#[Description('Create/update every Wazuh role group from ops/wazuh/profiles/*.xml')]
class SeedWazuhRoleGroups extends Command
{
    public function handle(): int
    {
        $wazuh = app(\App\Clients\WazuhClient::class);
        $token = $wazuh->token();

        $profileDir = base_path('ops/wazuh/profiles');
        $files = glob($profileDir . '/*.xml');

        if (empty($files)) {
            $this->error("No profile files found in {$profileDir}");
            return self::FAILURE;
        }

        foreach ($files as $file) {
            $groupId = pathinfo($file, PATHINFO_FILENAME); // e.g. "postgres-db"
            $xml = file_get_contents($file);

            try {
                $wazuh->ensureGroup($token, $groupId);
                $wazuh->pushGroupConfig($token, $groupId, $xml);
                $this->info("Seeded group: {$groupId}");
            } catch (\Throwable $e) {
                $this->error("Failed to seed {$groupId}: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
