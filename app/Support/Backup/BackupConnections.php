<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persistence for tenant_backup_connections.
 *
 * Credentials are encrypted at the application layer with the Crypt facade
 * (APP_KEY-derived). The project uses the query builder throughout rather
 * than Eloquent, so the encrypted:array cast is not available — encryption
 * is explicit here instead, and MUST go through these methods. Writing to
 * tenant_backup_connections.credentials directly would store plaintext
 * access keys.
 *
 * NOTE: rotating APP_KEY renders every stored credential undecryptable.
 * If APP_KEY is ever rotated, every tenant connection must be re-provisioned.
 */
class BackupConnections
{
    public static function store(
        string $tenantId,
        string $provider,
        string $displayName,
        array $credentials,
        string $status = 'active'
    ): string {
        $id  = (string) Str::uuid();
        $now = now();

        DB::table('tenant_backup_connections')->insert([
            'id'           => $id,
            'tenant_id'    => $tenantId,
            'provider'     => $provider,
            'display_name' => $displayName,
            'credentials'  => Crypt::encryptString(json_encode($credentials)),
            'status'       => $status,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        return $id;
    }

    /**
     * Fetch and decrypt a connection's credentials, scoped to its tenant.
     *
     * tenant_id is a required argument rather than optional: every read of
     * a credential must be tenant-scoped, matching the platform invariant.
     */
    public static function credentials(string $tenantId, string $connectionId): array
    {
        $row = DB::table('tenant_backup_connections')
            ->where('id', $connectionId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$row) {
            throw new \RuntimeException("Backup connection {$connectionId} not found for tenant {$tenantId}");
        }

        return self::decode($row->credentials, $connectionId);
    }

    public static function activeFor(string $tenantId, string $provider): ?object
    {
        return DB::table('tenant_backup_connections')
            ->where('tenant_id', $tenantId)
            ->where('provider', $provider)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Merge new values into an existing credential blob — used when a key
     * is rotated or a field is backfilled.
     */
    public static function merge(string $tenantId, string $connectionId, array $changes): void
    {
        $current = self::credentials($tenantId, $connectionId);

        DB::table('tenant_backup_connections')
            ->where('id', $connectionId)
            ->where('tenant_id', $tenantId)
            ->update([
                'credentials' => Crypt::encryptString(json_encode(array_merge($current, $changes))),
                'updated_at'  => now(),
            ]);
    }

    public static function setStatus(string $tenantId, string $connectionId, string $status): void
    {
        DB::table('tenant_backup_connections')
            ->where('id', $connectionId)
            ->where('tenant_id', $tenantId)
            ->update(['status' => $status, 'updated_at' => now()]);
    }

    private static function decode(?string $blob, string $connectionId): array
    {
        if ($blob === null || $blob === '') {
            throw new \RuntimeException("Backup connection {$connectionId} has empty credentials");
        }

        try {
            $decrypted = Crypt::decryptString($blob);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Unable to decrypt credentials for backup connection {$connectionId}. "
                . "This usually means APP_KEY changed since the connection was created."
            );
        }

        $decoded = json_decode($decrypted, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException("Backup connection {$connectionId} credentials are not valid JSON");
        }

        return $decoded;
    }
}
