<?php
namespace App\Support\Reconciliation;

use Illuminate\Support\Facades\DB;

class ArtifactReconciler
{
    public const VERIFIED    = 'verified';
    public const MISSING     = 'missing';
    public const UNREACHABLE = 'unreachable';

    /**
     * Ask each tool whether the artifact we recorded still exists.
     * The database is a CLAIM. This checks reality.
     */
    public static function run(?string $tenantId = null): array
    {
        $q = DB::table('tenant_artifacts');
        if ($tenantId) $q->where('tenant_id', $tenantId);

        $summary = ['verified' => 0, 'missing' => 0, 'unreachable' => 0];

        foreach ($q->get() as $a) {
            $status = self::check($a);
            $summary[$status]++;

            DB::table('tenant_artifacts')->where('id', $a->id)->update([
                'verify_status' => $status,
                'verified_at'   => now(),
                'verify_note'   => self::note($a, $status),
            ]);
        }

        return $summary;
    }

    private static function check(object $a): string
    {
        try {
            return match ($a->tool) {
                'wazuh'          => self::checkWazuh($a),
                'fleet'          => self::checkFleet($a),
                'ciso_assistant' => self::checkCiso($a),
                'keycloak'       => self::checkKeycloak($a),
                'zabbix'         => self::checkZabbix($a),
                default          => self::VERIFIED,   // nothing to verify → leave alone
            };
        } catch (\Throwable $e) {
            return self::UNREACHABLE;                 // errored → we do NOT know
        }
    }

    // --- Wazuh: does the agent group still exist? ---
    private static function checkWazuh(object $a): string
    {
        if ($a->artifact_type !== 'agent_group') return self::VERIFIED;

        $c = app(\App\Clients\WazuhClient::class);
        $token = $c->token();

        // Cannot authenticate → unknown, NOT missing. Wazuh restarting must never
        // make us tell every tenant their monitoring has vanished.
        if (!$token) return self::UNREACHABLE;

        return $c->groupExists($token, $a->external_id) ? self::VERIFIED : self::MISSING;
    }

    // --- Fleet: does the enroll secret still exist? ---
    private static function checkFleet(object $a): string
    {
        if ($a->artifact_type !== 'enroll_secret') return self::VERIFIED;

        $res = app(\App\Clients\FleetClient::class)->enrollSecretExists($a->external_id);
        return self::interpret($res);
    }

    // --- CISO: does the domain (folder) still exist? ---
    private static function checkCiso(object $a): string
    {
        if ($a->artifact_type !== 'domain') return self::VERIFIED;

        $res = app(\App\Clients\CisoClient::class)->folderExists($a->external_id);
        return self::interpret($res);
    }

    // --- Keycloak: groups AND users ---
    private static function checkKeycloak(object $a): string
    {
        $c = app(\App\Clients\KeycloakClient::class);
        $token = $c->token();
        if (!$token) return self::UNREACHABLE;

        $res = match ($a->artifact_type) {
            'group' => $c->groupExists($token, $a->external_id),
            'user'  => $c->userExists($token, $a->external_id),
            default => true,
        };

        return self::interpret($res);
    }

    // --- Zabbix: does the real host group / action still exist? ---
    private static function checkZabbix(object $a): string
    {
        try {
            if ($a->artifact_type === 'host_group') {
                $c = app(\App\Clients\ZabbixClient::class);
                $tenant = DB::table('tenants')->where('id', $a->tenant_id)->first();
                if (!$tenant) return self::UNREACHABLE;

                $groupId = $c->hostGroupIdForSlug($tenant->slug);
                return $groupId !== null ? self::VERIFIED : self::MISSING;
            }

            if ($a->artifact_type === 'autoregistration_action') {
                $c = app(\App\Clients\ZabbixClient::class);
                return $c->actionExists((int) $a->external_id) ? self::VERIFIED : self::MISSING;
            }

            // registration_secret: purely internal, nothing external to
            // check -- genuinely, honestly always VERIFIED.
            return self::VERIFIED;
        } catch (\Throwable $e) {
            return self::UNREACHABLE;
        }
    }

    /**
     * The heart of the design: null is NOT false.
     * "I could not check" and "it is gone" are different facts,
     * and conflating them either cries wolf or hides a real loss.
     */
    private static function interpret(?bool $res): string
    {
        if ($res === null)  return self::UNREACHABLE;
        return $res ? self::VERIFIED : self::MISSING;
    }

    private static function note(object $a, string $status): ?string
    {
        return match ($status) {
            self::MISSING => "Not found in {$a->tool} at " . now()->toDateTimeString()
                           . ". The platform will not claim this protection until it is restored.",
            self::UNREACHABLE => "{$a->tool} did not respond at " . now()->toDateTimeString()
                           . ". Status unknown — will retry.",
            default => null,
        };
    }

    /**
     * Recreate ONLY artifacts that are safe to recreate.
     * Deliberately conservative: healing that runs unattended can hide a real
     * problem. The reconciler's first duty is to TELL you; repair is a separate,
     * explicit act.
     */
    public static function heal(?string $tenantId = null): array
    {
        $q = DB::table('tenant_artifacts')->where('verify_status', self::MISSING);
        if ($tenantId) $q->where('tenant_id', $tenantId);

        $healed = [];
        $skipped = [];

        foreach ($q->get() as $a) {
            $ok = match (true) {
                // SAFE: an empty agent group. Recreating restores the container
                // for future enrolments and loses nothing.
                $a->tool === 'wazuh' && $a->artifact_type === 'agent_group'
                    => self::recreateWazuhGroup($a),

                // SAFE: a CISO domain. Recreating gives evidence somewhere to live.
                $a->tool === 'ciso_assistant' && $a->artifact_type === 'domain'
                    => self::recreateCisoDomain($a),

                // SAFE: an empty Zabbix host group, same real reasoning as
                // Wazuh's own agent group above -- recreating restores it
                // for future host enrolments and loses nothing real.
                $a->tool === 'zabbix' && $a->artifact_type === 'host_group'
                    => self::recreateZabbixHostGroup($a),

                // NOT SAFE — left for a human. See below.
                default => null,
            };

            if ($ok === true) {
                $healed[] = "{$a->tool}/{$a->artifact_type}: {$a->external_id}";
                DB::table('tenant_artifacts')->where('id', $a->id)->update([
                    'verify_status' => self::VERIFIED,
                    'verified_at' => now(),
                    'verify_note' => 'Recreated automatically at ' . now()->toDateTimeString(),
                ]);
            } else {
                $skipped[] = "{$a->tool}/{$a->artifact_type}: {$a->external_id}";
            }
        }

        return ['healed' => $healed, 'skipped' => $skipped];
    }

    private static function recreateWazuhGroup(object $a): bool
    {
        try {
            $c = app(\App\Clients\WazuhClient::class);
            $token = $c->token();
            if (!$token) return false;

            $c->ensureGroup($token, $a->external_id);
            return $c->groupExists($token, $a->external_id);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function recreateCisoDomain(object $a): bool
    {
        try {
            // The domain is created by NAME and returns a NEW id — so we must
            // update our record, not just recreate blindly.
            $tenant = DB::table('tenants')->where('id', $a->tenant_id)->first();
            if (!$tenant) return false;

            $newId = app(\App\Clients\CisoClient::class)->ensureDomain($tenant->slug);
            if (!$newId) return false;

            DB::table('tenant_artifacts')->where('id', $a->id)
                ->update(['external_id' => $newId]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function recreateZabbixHostGroup(object $a): bool
    {
        try {
            // Same real reasoning as recreateCisoDomain() above: a real,
            // genuinely new groupid would be assigned if the original was
            // deleted externally -- our own artifact record must be
            // updated to the new, real id, not just recreated blindly.
            $tenant = DB::table('tenants')->where('id', $a->tenant_id)->first();
            if (!$tenant) return false;

            $newId = app(\App\Clients\ZabbixClient::class)->ensureTenantHostGroup($tenant->slug);
            if (!$newId) return false;

            DB::table('tenant_artifacts')->where('id', $a->id)
                ->update(['external_id' => (string) $newId]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}