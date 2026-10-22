<?php

namespace App\Provisioning;

use App\Clients\FleetClient;
use App\Support\Audit;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class FleetStep
{
    public function __construct(private FleetClient $fleet) {}

    public function run(object $tenant): void
    {
        // A unique, tenant-identifying enroll secret.
        $secret = 'tenant-' . $tenant->slug . '-' . Str::random(16);

        // If this tenant already has a Fleet secret artifact, reuse it (idempotent).
        $existing = DB::table('tenant_artifacts')
            ->where('tenant_id', $tenant->id)->where('tool', 'fleet')
            ->where('artifact_type', 'enroll_secret')->first();
        if ($existing) {
            $secret = $existing->external_id;
        }

        $this->fleet->addEnrollSecret($secret);

        // VERIFY the secret actually reached Fleet.
        //
        // Storing the secret in our database is not the same as Fleet knowing it.
        // These drifted apart in dev: the row existed, Fleet had zero secrets, and
        // every agent enrollment was silently refused with no error anywhere.
        // enrollSecretExists() returns null when Fleet could not be reached — we do
        // NOT treat that as drift, because a brief outage must not look like failure.
        $present = $this->fleet->enrollSecretExists($secret);
        if ($present === false) {
            Audit::log($tenant->id, 'system', 'tenant.provision.fleet', 'failed', 'fleet',
                ['reason' => 'enroll_secret_not_in_fleet', 'enroll_secret' => $secret]);
        }

        if (!$existing) {
            DB::table('tenant_artifacts')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'tool' => 'fleet',
                'artifact_type' => 'enroll_secret',
                'external_id' => $secret,
                'metadata' => json_encode(['slug' => $tenant->slug]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // --- Per-tenant label: how hosts are attributed to this tenant ---
        //
        // Fleet Free has no Teams, and its /hosts response does not include
        // enroll_secret_name, so Fleet itself cannot tell us which tenant a host
        // belongs to. Attribution is therefore ours to enforce:
        //   1. the install script writes /etc/obaits/tenant-{uuid} on the machine
        //   2. this label's osquery query matches that exact path
        //   3. hostsForTenant() lists hosts carrying the label
        // Without this label the tenant's hosts enrol successfully and then belong
        // to nobody — visible in Fleet, invisible in the portal.
        $labelId = $this->fleet->ensureTenantLabel($tenant->id, $tenant->slug);

        if ($labelId) {
            $hasLabel = DB::table('tenant_artifacts')
                ->where('tenant_id', $tenant->id)->where('tool', 'fleet')
                ->where('artifact_type', 'label')->exists();

            if (!$hasLabel) {
                DB::table('tenant_artifacts')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenant->id,
                    'tool' => 'fleet',
                    'artifact_type' => 'label',
                    'external_id' => (string) $labelId,
                    'metadata' => json_encode([
                        'name'        => 'tenant-' . $tenant->slug,
                        'marker_path' => '/etc/obaits/tenant-' . $tenant->id,
                    ]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        Audit::log($tenant->id, 'system', 'tenant.provision.fleet', 'success', 'fleet',
            [
                'enroll_secret'   => $secret,
                'secret_in_fleet' => $present,          // true / false / null (unknown)
                'label_id'        => $labelId,          // null if label creation failed
                'marker_path'     => '/etc/obaits/tenant-' . $tenant->id,
            ]);
    }
}