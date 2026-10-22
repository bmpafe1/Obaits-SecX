<?php
namespace App\Support\Backup;

interface BackupProviderInterface
{
    /**
     * Provision a new tenant sub-account/connection with this provider.
     * Returns an array of connection details to store (encrypted) in
     * tenant_backup_connections.credentials.
     */
    public function connect(string $tenantId, array $params = []): array;

    /**
     * List folders available for backup on a given connection — used by
     * the portal's folder-picker UI.
     */
    public function listFolders(array $credentials): array;

    /**
     * Trigger (or queue) a backup run for the given folder selections.
     */
    public function backup(array $credentials, array $folderPaths): array;

    /**
     * Restore files from a given snapshot/point-in-time reference.
     */
    public function restore(array $credentials, string $snapshotId, string $destinationPath): array;

    /**
     * Health/status check — used for the monitoring dashboard.
     */
    public function status(array $credentials): array;

    /**
     * Cleanly disconnect/remove this tenant's connection (deprovision).
     */
    public function disconnect(array $credentials): void;
}