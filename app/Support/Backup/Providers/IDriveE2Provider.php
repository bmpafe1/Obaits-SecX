<?php

namespace App\Support\Backup\Providers;

use App\Support\Backup\BackupProviderInterface;
use Illuminate\Support\Facades\Http;

class IDriveE2Provider implements BackupProviderInterface
{
    /** Free tier granted to every tenant, in GB. */
    public const DEFAULT_QUOTA_GB = 10;

    private function baseUrl(): string
    {
        return rtrim(config('services.idrive_e2.reseller_base_url'), '/');
    }

    private function authHeader(): array
    {
        return ['token' => config('services.idrive_e2.reseller_api_key')];
    }

    /**
     * Create an IDrive e2 sub-account for this tenant, enable a storage
     * region, and generate a scoped access key.
     *
     * Provisions at the free-tier quota with enforcement ON by default —
     * a billing bug must never be able to grant unlimited storage.
     */
    public function connect(string $tenantId, array $params = []): array
    {
        // REAL FIX 2026-08-22, found during a real portability audit:
        // was hardcoded to "obaits.com" directly.
        $email   = $params['email'] ?? "tenant-{$tenantId}@" . config('platform.backups_email_domain');
        $region  = $params['region'] ?? 'IE'; // Ireland (eu-west-1)
        $quotaGb = $params['quota_gb'] ?? self::DEFAULT_QUOTA_GB;

        // 1. Create the sub-account, already quota-limited.
        $created = Http::withHeaders($this->authHeader())->put(
            $this->baseUrl() . '/create_user',
            [
                'email'              => $email,
                'password'           => base64_encode(bin2hex(random_bytes(16))),
                'first_name'         => 'Obaits',
                'last_name'          => 'Tenant',
                'quota'              => $quotaGb,   // GB, integer. 0 = unlimited.
                'enforce_quota'      => true,       // read-only on exceed
                'email_notification' => false,      // no IDrive-branded mail to tenants
            ]
        );

        if (!$created->successful()) {
            throw new \RuntimeException('IDrive e2 create_user failed: HTTP ' . $created->status() . ' ' . substr($created->body(), 0, 300));
        }

        // 2. Enable a storage region for the new user.
        $regionResp = Http::withHeaders($this->authHeader())->post(
            $this->baseUrl() . '/enable_user_region',
            ['email' => $email, 'region' => $region]
        );

        if (!$regionResp->successful()) {
            throw new \RuntimeException('IDrive e2 enable_user_region failed: HTTP ' . $regionResp->status() . ' ' . substr($regionResp->body(), 0, 300));
        }

        $storageDn = $regionResp->json('storage_dn');
        if (!$storageDn) {
            throw new \RuntimeException('IDrive e2 enable_user_region returned no storage_dn');
        }

        // 3. Create an access key scoped to this user's storage.
        $keyResp = Http::withHeaders($this->authHeader())->post(
            $this->baseUrl() . '/create_access_key',
            [
                'email'       => $email,
                'storage_dn'  => $storageDn,
                'name'        => 'obaits-backup',
                'permissions' => 2, // Read/Write
            ]
        );

        if (!$keyResp->successful()) {
            throw new \RuntimeException('IDrive e2 create_access_key failed: HTTP ' . $keyResp->status() . ' ' . substr($keyResp->body(), 0, 300));
        }

        // 4. Read back the region record for the tenant endpoint — needed for
        //    S3 client configuration and any future CNAME white-labelling.
        $regionInfo = $this->regionInfo($email, $storageDn);

        return [
            'email'           => $email,
            'region'          => $region,
            'storage_dn'      => $storageDn,
            'user_dn'         => $regionInfo['user_dn'] ?? null,
            'tenant_endpoint' => $regionInfo['tenant_endpoint'] ?? null,
            'quota_gb'        => $quotaGb,
            'access_key'      => $keyResp->json('data.access_key'),
            'secret_key'      => $keyResp->json('data.secret_key'),
        ];
    }

    /**
     * Look up the enabled-region record for a user, matching on storage_dn
     * when a tenant has more than one region enabled.
     */
    public function regionInfo(string $email, ?string $storageDn = null): array
    {
        $resp = Http::withHeaders($this->authHeader())->post(
            $this->baseUrl() . '/list_user_regions',
            ['email' => $email]
        );

        if (!$resp->successful()) {
            throw new \RuntimeException('IDrive e2 list_user_regions failed: HTTP ' . $resp->status() . ' ' . substr($resp->body(), 0, 300));
        }

        $regions = $resp->json('user_regions') ?? [];

        if ($storageDn !== null) {
            foreach ($regions as $r) {
                if (($r['user_dn'] ?? null) === $storageDn || ($r['tenant_endpoint'] ?? null) === $storageDn) {
                    return $r;
                }
            }
        }

        return $regions[0] ?? [];
    }

    /**
     * Set a tenant's storage quota in GB. Used when a tenant buys storage,
     * and when a lapsed subscription is downgraded back to the free tier.
     *
     * Verifies by reading the account back — a silently-unapplied quota
     * means either a tenant can't use what they paid for, or they can
     * consume unlimited storage at our expense.
     */
    public function setQuota(string $email, int $quotaGb, bool $enforce = true): array
    {
        $resp = Http::withHeaders($this->authHeader())->post(
            $this->baseUrl() . '/edit_user',
            [
                'email'         => $email,
                'quota'         => $quotaGb,
                'enforce_quota' => $enforce,
            ]
        );

        if (!$resp->successful()) {
            throw new \RuntimeException('IDrive e2 edit_user failed: HTTP ' . $resp->status() . ' ' . substr($resp->body(), 0, 300));
        }

        $user = $this->findUser($email);

        if (($user['storage_quota'] ?? null) !== $quotaGb) {
            throw new \RuntimeException(
                "IDrive e2 quota read-back mismatch for {$email}: expected {$quotaGb} GB, got " . var_export($user['storage_quota'] ?? null, true)
            );
        }

        return $user;
    }

    /**
     * Fetch a single sub-account record by email.
     */
    public function findUser(string $email): array
    {
        $resp = Http::withHeaders($this->authHeader())->get(
            $this->baseUrl() . '/users',
            ['email' => $email]
        );

        if (!$resp->successful()) {
            throw new \RuntimeException('IDrive e2 users lookup failed: HTTP ' . $resp->status() . ' ' . substr($resp->body(), 0, 300));
        }

        $users = $resp->json('users') ?? [];

        if (empty($users)) {
            throw new \RuntimeException("IDrive e2 sub-account not found: {$email}");
        }

        return $users[0];
    }

    /**
     * Every sub-account under the reseller profile. Used for reconciliation
     * against local records.
     */
    public function listAllUsers(): array
    {
        $resp = Http::withHeaders($this->authHeader())->get($this->baseUrl() . '/users');

        if (!$resp->successful()) {
            throw new \RuntimeException('IDrive e2 users list failed: HTTP ' . $resp->status() . ' ' . substr($resp->body(), 0, 300));
        }

        return $resp->json('users') ?? [];
    }

    public function listFolders(array $credentials): array
    {
        throw new \RuntimeException('Not yet implemented — requires Fleet osquery live-query integration, built next.');
    }

    public function backup(array $credentials, array $folderPaths): array
    {
        throw new \RuntimeException('Not yet implemented — requires restic invocation design, built next.');
    }

    public function restore(array $credentials, string $snapshotId, string $destinationPath): array
    {
        throw new \RuntimeException('Not yet implemented.');
    }

    /**
     * Real status check: usage over the last 30 days, plus the account
     * record (quota, read-only state) so the portal can show storage
     * used / remaining and whether the tenant has been locked to read-only.
     *
     * NOTE: IDrive refreshes usage figures up to 24 hours behind. Display
     * these as "as of" values, never as live.
     */
    public function status(array $credentials): array
    {
        $email = $credentials['email'];

        $resp = Http::withHeaders($this->authHeader())->post(
            $this->baseUrl() . '/usage_stats',
            [
                'email'     => $email,
                'date_from' => now()->subDays(30)->toDateString(),
                'date_to'   => now()->toDateString(),
            ]
        );

        if (!$resp->successful()) {
            throw new \RuntimeException('IDrive e2 usage_stats failed: HTTP ' . $resp->status() . ' ' . substr($resp->body(), 0, 300));
        }

        $user = $this->findUser($email);

        return [
            'usage'          => $resp->json() ?? [],
            'storage_used'   => $user['storage_used'] ?? null,   // bytes
            'storage_quota'  => $user['storage_quota'] ?? null,  // GB
            'enforce_quota'  => $user['enforce_quota'] ?? null,
            'read_only_mode' => $user['read_only_mode'] ?? null,
            'is_active'      => $user['is_active'] ?? null,
        ];
    }

    public function disconnect(array $credentials): void
    {
        $email = $credentials['email'];

        // IDrive requires disable-before-remove.
        $disable = Http::withHeaders($this->authHeader())->post($this->baseUrl() . '/disable_user', ['email' => $email]);
        if (!$disable->successful()) {
            throw new \RuntimeException('IDrive e2 disable_user failed: HTTP ' . $disable->status() . ' ' . substr($disable->body(), 0, 300));
        }

        $remove = Http::withHeaders($this->authHeader())->post($this->baseUrl() . '/remove_user', ['email' => $email]);
        if (!$remove->successful()) {
            throw new \RuntimeException('IDrive e2 remove_user failed: HTTP ' . $remove->status() . ' ' . substr($remove->body(), 0, 300));
        }
    }
}
