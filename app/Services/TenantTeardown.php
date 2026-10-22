<?php
namespace App\Services;
 
use App\Clients\KeycloakClient;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use App\Clients\FleetClient;
use App\Clients\WazuhClient;
use App\Clients\CisoClient;
use App\Clients\ZabbixClient;
 
class TenantTeardown
{
    public function __construct(private KeycloakClient $kc, private FleetClient $fleet, private WazuhClient $wazuh, private CisoClient $ciso, private ZabbixClient $zabbix) {}
 
    /**
     * $force = true bypasses the credentialed-user protection below, for use
     * ONLY by a genuine, intentional tenant deprovision (DeprovisionTenant).
     * Rollback-on-failed-provisioning must NEVER pass force=true — that guard
     * exists specifically to stop a failed re-provision from deleting a real
     * customer's working login (see 2026-07-21 incident).
     */
    public function removeExternalArtifacts(string $tenantId, bool $force = false): void
    {
        $token = $this->kc->token();
        $artifacts = DB::table('tenant_artifacts')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')->get();
 
        foreach ($artifacts as $a) {
            try {
                 if ($a->tool === 'keycloak' && $a->artifact_type === 'user') {
                    // A user WITH credentials predates this run — they set a real
                    // password. Rollback must not destroy them (2026-07-21: a WAF
                    // failure on a re-run deleted a working tenant admin). Keep the
                    // user AND the artifact row; log it honestly and move on.
                    // A genuine deprovision (force=true) intentionally overrides
                    // this — the whole tenant, credentials included, is meant to
                    // go. Confirmed 2026-07-23: without force, deprovision left
                    // real Keycloak users orphaned on every run.
                    if (!$force && $this->kc->userHasCredentials($token, $a->external_id) !== false) {
                        Audit::log($tenantId, 'system', "teardown.{$a->tool}.{$a->artifact_type}", 'skipped', $a->tool,
                            ['external_id' => $a->external_id, 'reason' => 'user has credentials — not this run\'s artifact']);
                        continue;
                    }
                    $this->kc->deleteUser($token, $a->external_id);
                } elseif ($a->tool === 'keycloak' && $a->artifact_type === 'group') {
                    $this->kc->deleteGroup($token, $a->external_id);
                } elseif ($a->tool === 'fleet' && $a->artifact_type === 'enroll_secret') {
                    $this->fleet->removeEnrollSecret($a->external_id);
                } elseif ($a->tool === 'wazuh' && $a->artifact_type === 'agent_group') {
                    $this->wazuh->deleteGroup($this->wazuh->token(), $a->external_id);
                } elseif ($a->tool === 'ciso_assistant' && $a->artifact_type === 'domain') {
                    $this->ciso->deleteDomain($a->external_id);
                } elseif ($a->tool === 'fleet' && $a->artifact_type === 'label') {
                    // Added 2026-07-23: this branch never existed. Every teardown
                    // before today fell through the if/elseif silently, then still
                    // deleted the artifact row and logged "success" — a tenant's
                    // Fleet label survived every deprovision undetected until now.
                    $this->fleet->deleteLabel((int) $a->external_id);
                } elseif ($a->tool === 'zabbix' && $a->artifact_type === 'host_group') {
                    // Added from day one (2026-08-04), specifically BECAUSE this
                    // project's own real history (the Fleet 'label' gap above)
                    // showed exactly how a missing branch here silently deletes
                    // the artifact row and logs "success" while leaving a real,
                    // orphaned artifact behind in the real, external tool.
                    $this->zabbix->deleteHostGroup((int) $a->external_id);
                } elseif ($a->tool === 'zabbix' && $a->artifact_type === 'autoregistration_action') {
                    $this->zabbix->deleteAction((int) $a->external_id);
                } elseif ($a->tool === 'zabbix' && $a->artifact_type === 'registration_secret') {
                    // Genuinely nothing external to clean up -- this
                    // artifact only ever existed in our own database,
                    // never in Zabbix itself. Falls through to the real,
                    // shared row-delete below, same as every other type.
                }
                DB::table('tenant_artifacts')->where('id', $a->id)->delete();
                Audit::log($tenantId, 'system', "teardown.{$a->tool}.{$a->artifact_type}", 'success', $a->tool, ['external_id' => $a->external_id]);
            } catch (\Throwable $e) {
                Audit::log($tenantId, 'system', "teardown.{$a->tool}.{$a->artifact_type}", 'failure', $a->tool, ['error' => $e->getMessage()]);
            }
        }
    }
}