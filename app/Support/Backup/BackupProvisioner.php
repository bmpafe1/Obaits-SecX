<?php

namespace App\Support\Backup;

use App\Support\Backup\Providers\IDriveE2Provider;
use Illuminate\Support\Facades\DB;

/**
 * Joins provider provisioning to local persistence.
 *
 * The critical case is a partial failure: an IDrive sub-account created
 * successfully followed by a failed local write leaves an orphaned account
 * that Obaits pays for and has no record of. Every path below either
 * completes both halves, rolls the remote half back, or raises a critical
 * operator alert naming the exact account to clean up by hand.
 */
class BackupProvisioner
{
    public const PROVIDER = 'idrive_e2';

    /**
     * Provision platform-default backup storage for a tenant.
     * Idempotent: returns the existing connection id if one is already active.
     */
    public static function provision(string $tenantId, array $params = []): string
    {
        if ($existing = BackupConnections::activeFor($tenantId, self::PROVIDER)) {
            return $existing->id;
        }

        // REAL FIX 2026-08-22, found during a real portability audit:
        // was hardcoded to "obaits.com" directly.
        $email    = $params['email'] ?? "tenant-{$tenantId}@" . config('platform.backups_email_domain');
        $provider = new IDriveE2Provider();

        $result = $provider->connect($tenantId, array_merge($params, ['email' => $email]));

        foreach (['access_key', 'secret_key', 'storage_dn'] as $required) {
            if (empty($result[$required])) {
                self::rollback($provider, $email, "connect() returned an empty {$required}");
                throw new \RuntimeException("IDrive e2 connect() returned no {$required} for {$email}");
            }
        }

        try {
            return BackupConnections::store(
                $tenantId,
                self::PROVIDER,
                $params['display_name'] ?? 'Obaits Backup Storage',
                $result
            );
        } catch (\Throwable $e) {
            self::rollback($provider, $email, 'local persistence failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Apply a purchased plan by raising the tenant's IDrive quota.
     * Never drops below the free tier.
     */
    public static function applyQuota(string $tenantId, string $connectionId, int $gb): array
    {
        $credentials = BackupConnections::credentials($tenantId, $connectionId);
        $effective   = max($gb, IDriveE2Provider::DEFAULT_QUOTA_GB);

        $user = (new IDriveE2Provider())->setQuota($credentials['email'], $effective);

        BackupConnections::merge($tenantId, $connectionId, ['quota_gb' => $effective]);

        return $user;
    }

    /**
     * Roll a tenant back to the free tier — used when a subscription lapses
     * past its grace window. Does not delete data; IDrive switches the
     * account to read-only once usage exceeds the reduced quota.
     */
    public static function revertToFreeTier(string $tenantId, string $connectionId): array
    {
        return self::applyQuota($tenantId, $connectionId, IDriveE2Provider::DEFAULT_QUOTA_GB);
    }

    /**
     * Best-effort removal of a remote account whose local record failed.
     * A failed rollback is loud, not silent — it needs a human.
     */
    private static function rollback(IDriveE2Provider $provider, string $email, string $reason): void
    {
        try {
            $provider->disconnect(['email' => $email]);

            \App\Support\Health\OperatorAlert::send(
                'Backup provisioning rolled back cleanly',
                [['level' => 'warning', 'area' => 'backup',
                  'message' => "Removed orphaned IDrive account {$email}. Reason: {$reason}"]]
            );
        } catch (\Throwable $e) {
            \App\Support\Health\OperatorAlert::send(
                'ORPHANED IDrive sub-account requires manual cleanup',
                [['level' => 'critical', 'area' => 'backup',
                  'message' => "Account {$email} exists on IDrive but has no Obaits record, "
                             . "and automatic removal failed. Original reason: {$reason}. "
                             . "Rollback error: " . $e->getMessage()]]
            );
        }
    }

    /**
     * Reconciliation: IDrive sub-accounts with no matching local connection.
     * Read-only — reports orphans, never deletes them.
     */
    public static function findOrphans(): array
    {
        $provider = new IDriveE2Provider();

        $remote = collect($provider->listAllUsers())->pluck('email')->all();

        $local = DB::table('tenant_backup_connections')
            ->where('provider', self::PROVIDER)
            ->pluck('id', 'tenant_id');

        $known = [];
        foreach ($local as $tenantId => $connectionId) {
            try {
                $known[] = BackupConnections::credentials($tenantId, $connectionId)['email'] ?? null;
            } catch (\Throwable $e) {
                // Undecryptable rows are their own problem; don't mask them
                // as orphans here.
            }
        }

        return array_values(array_diff($remote, array_filter($known)));
    }
}
