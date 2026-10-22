<?php
namespace App\Clients;

use Illuminate\Support\Facades\Http;

class KeycloakClient
{
    private function baseUrl(): string
    {
        return rtrim(config('services.keycloak_orchestrator.base_url'), '/');
    }

    private function realm(): string
    {
        return config('services.keycloak_orchestrator.realm');
    }

    // Throw unless the response is acceptable. $okStatuses lets ensure-methods
    // treat 409 (already exists) and deletes treat 404 (already gone) as success.
    // Added after 2026-07-21: teardown logged success while the WAF returned 403
    // HTML — no mutating call may go unchecked again.
    private function assertOk(\Illuminate\Http\Client\Response $resp, string $operation, array $okStatuses = []): void
    {
        if ($resp->successful() || in_array($resp->status(), $okStatuses, true)) {
            return;
        }
        throw new \RuntimeException(
            "Keycloak $operation failed: HTTP " . $resp->status() . ' ' . substr((string) $resp->body(), 0, 200)
        );
    }

    public function token(): string
    {
        $res = Http::asForm()->post(
            $this->baseUrl() . '/realms/' . $this->realm() . '/protocol/openid-connect/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => config('services.keycloak_orchestrator.client_id'),
                'client_secret' => config('services.keycloak_orchestrator.client_secret'),
            ]
        );
        $this->assertOk($res, 'token grant');
        $token = $res->json('access_token');
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Keycloak token grant returned no access_token');
        }
        return $token;
    }

    // Returns the group id (existing or newly created).
    public function ensureGroup(string $token, string $name): string
    {
        $found = Http::withToken($token)
            ->get($this->baseUrl() . '/admin/realms/' . $this->realm() . '/groups', ['search' => $name])
            ->json();
        foreach ($found as $g) {
            if (($g['name'] ?? null) === $name) return $g['id'];
        }
        $resp = Http::withToken($token)->post(
            $this->baseUrl() . '/admin/realms/' . $this->realm() . '/groups', ['name' => $name]
        );
        $this->assertOk($resp, "group create ($name)", [409]);
        // fetch again to get the id
        $found = Http::withToken($token)
            ->get($this->baseUrl() . '/admin/realms/' . $this->realm() . '/groups', ['search' => $name])
            ->json();
        foreach ($found as $g) {
            if (($g['name'] ?? null) === $name) return $g['id'];
        }
        throw new \RuntimeException("Group $name not found after creation");
    }

    public function ensureRealmRole(string $token, string $name): void
    {
        $res = Http::withToken($token)
            ->get($this->baseUrl() . '/admin/realms/' . $this->realm() . "/roles/$name");
        if ($res->status() === 200) return; // already exists
        $resp = Http::withToken($token)->post(
            $this->baseUrl() . '/admin/realms/' . $this->realm() . '/roles', ['name' => $name]
        );
        $this->assertOk($resp, "role create ($name)", [409]);
    }

    // Returns the user id (existing or newly created).
    public function ensureUser(string $token, string $email, string $first, string $last): string
    {
        $found = Http::withToken($token)
            ->get($this->baseUrl() . '/admin/realms/' . $this->realm() . '/users', ['username' => $email])
            ->json();
        if (!empty($found)) return $found[0]['id'];
        $resp = Http::withToken($token)->post(
            $this->baseUrl() . '/admin/realms/' . $this->realm() . '/users',
            [
                'username' => $email, 'email' => $email,
                'firstName' => $first, 'lastName' => $last, 'enabled' => true,
            ]
        );
        $this->assertOk($resp, "user create ($email)", [409]);
        $found = Http::withToken($token)
            ->get($this->baseUrl() . '/admin/realms/' . $this->realm() . '/users', ['username' => $email])
            ->json();
        if (empty($found)) throw new \RuntimeException("User $email not found after creation");
        return $found[0]['id'];
    }

    public function setPassword(string $token, string $userId, string $password): void
    {
        $resp = Http::withToken($token)->put(
            $this->baseUrl() . '/admin/realms/' . $this->realm() . "/users/$userId/reset-password",
            ['type' => 'password', 'value' => $password, 'temporary' => true]
        );
        $this->assertOk($resp, 'set password');
    }

    // Email the user a Keycloak link to set their own password. We never see or
    // store the password — Keycloak owns the whole flow. No redirect_uri: after
    // setting the password Keycloak shows its own confirmation, which avoids
    // sending a code-less GET at our OIDC callback.
    public function sendSetPasswordEmail(string $token, string $userId, int $lifespanSeconds = 86400): void
    {
        $resp = Http::withToken($token)->put(
            $this->baseUrl() . '/admin/realms/' . $this->realm()
                . "/users/$userId/execute-actions-email?lifespan=$lifespanSeconds",
            ['UPDATE_PASSWORD']
        );
        $this->assertOk($resp, 'set-password email');
    }

    public function addUserToGroup(string $token, string $userId, string $groupId): void
    {
        $resp = Http::withToken($token)->put(
            $this->baseUrl() . '/admin/realms/' . $this->realm() . "/users/$userId/groups/$groupId"
        );
        $this->assertOk($resp, 'add user to group');
    }

    public function assignRealmRole(string $token, string $userId, string $roleName): void
    {
        // Look up the role representation, then assign it to the user.
        $role = Http::withToken($token)
            ->get($this->baseUrl() . '/admin/realms/' . $this->realm() . "/roles/$roleName")
            ->json();
        if (empty($role['id'])) {
            throw new \RuntimeException("Keycloak role $roleName not found for assignment");
        }
        // CONFIRMED EMPIRICALLY 2026-09-01: posting the role representation
        // Keycloak itself returned from GET fails with HTTP 400
        // "Cannot parse the JSON". The culprit is its own "attributes": []
        // field -- an empty JSON ARRAY, where this endpoint expects an object.
        // Keycloak contradicts its own output.
        //
        // Tested three ways against a live realm: the full representation fails
        // both as form-encoded and with asJson(); id and name alone returns 204.
        // Sending only what the endpoint needs is both the fix and the safer
        // habit -- this is the same trap as Flutterwave's meta field, where an
        // empty PHP array serialised to [] instead of {} and produced an
        // equally unhelpful "Malformed request".
        //
        // Team invitations had NEVER worked because of this: aicexperts has run
        // since July with exactly one user, the one created at registration.
        $resp = Http::withToken($token)->asJson()->post(
            $this->baseUrl() . '/admin/realms/' . $this->realm() . "/users/$userId/role-mappings/realm",
            [['id' => $role['id'], 'name' => $role['name']]]
        );
        $this->assertOk($resp, "assign role ($roleName)");
    }

    public function deleteGroup(string $token, string $groupId): void
    {
        $resp = Http::withToken($token)->delete(
            $this->baseUrl() . '/admin/realms/' . $this->realm() . "/groups/$groupId"
        );
        // 404 = already gone, which is the goal of a delete.
        $this->assertOk($resp, 'group delete', [404]);
    }

    public function deleteUser(string $token, string $userId): void
    {
        $resp = Http::withToken($token)->delete(
            $this->baseUrl() . '/admin/realms/' . $this->realm() . "/users/$userId"
        );
        $this->assertOk($resp, 'user delete', [404]);
    }

    // --- Convenience methods for Stage 2 team management ---

    // Create a user in a tenant group with a role, all by name/email. Self-manages the token.
    public function createUser(string $tenantSlug, string $email, string $first, string $last, string $roleName): string
    {
        $token = $this->token();
        $userId = $this->ensureUser($token, $email, $first, $last);
        $groupId = $this->ensureGroup($token, $tenantSlug);
        $this->addUserToGroup($token, $userId, $groupId);
        $this->assignRealmRole($token, $userId, $roleName);
        return $userId;
    }

    // Delete a user by email. Self-manages the token.
    public function deleteUserByEmail(string $email): void
    {
        $token = $this->token();
        $found = Http::withToken($token)
            ->get($this->baseUrl() . '/admin/realms/' . $this->realm() . '/users', ['username' => $email])
            ->json();
        if (!empty($found)) {
            $this->deleteUser($token, $found[0]['id']);
        }
    }

    // Users in a group (a tenant's members).
    public function groupMembers(string $token, string $groupId): array
    {
        $res = Http::withToken($token)
            ->timeout(60)
            ->get($this->baseUrl() . '/admin/realms/' . $this->realm() . "/groups/$groupId/members");
        return $res->json() ?? [];
    }

    // Identity evidence for a tenant's group: who exists, enabled, when added.
    // NOTE: roles are NOT read here — platform roles live in tenant_users (see the component).
    public function accessForGroup(string $groupId): array
    {
        $token = $this->token();                 // token() is public
        $members = $this->groupMembers($token, $groupId);

        $out = [];
        foreach ($members as $u) {
            $out[] = [
                'email'   => $u['email'] ?? ($u['username'] ?? '—'),
                'name'    => trim(($u['firstName'] ?? '') . ' ' . ($u['lastName'] ?? '')) ?: '—',
                'enabled' => (bool) ($u['enabled'] ?? true),
                'roles'   => [],                 // filled from tenant_users by the component
                'created' => isset($u['createdTimestamp'])
                    ? date('Y-m-d', (int) ($u['createdTimestamp'] / 1000))
                    : '—',
            ];
        }
        return $out;
    }

     // Create a READ-ONLY LDAP/AD user federation provider. editMode=READ_ONLY => never writes to the directory.
    public function addLdapFederation(string $token, string $tenantSlug, array $cfg): bool
    {
        $payload = [
            'name' => 'ldap-' . $tenantSlug,
            'providerId' => 'ldap',
            'providerType' => 'org.keycloak.storage.UserStorageProvider',
            'config' => [
                'enabled' => ['true'],
                'priority' => ['0'],
                'editMode' => ['READ_ONLY'],
                'importEnabled' => ['true'],
                'syncRegistrations' => ['false'],
                'vendor' => [$cfg['vendor'] ?? 'ad'],
                'connectionUrl' => [$cfg['connection_url']],
                'bindDn' => [$cfg['bind_dn']],
                'bindCredential' => [$cfg['bind_credential']],
                'usersDn' => [$cfg['users_dn']],
                'usernameLDAPAttribute' => [$cfg['username_attr'] ?? 'sAMAccountName'],
                'rdnLDAPAttribute' => [$cfg['rdn_attr'] ?? 'cn'],
                'uuidLDAPAttribute' => [$cfg['uuid_attr'] ?? 'objectGUID'],
                'userObjectClasses' => [$cfg['user_object_classes'] ?? 'person, organizationalPerson, user'],
                'searchScope' => ['2'],
                'useTruststoreSpi' => ['ldapsOnly'],
                'connectionPooling' => ['true'],
                'pagination' => ['true'],
            ],
        ];

        $res = Http::withToken($token)
            ->timeout(60)
            ->post($this->baseUrl() . '/admin/realms/' . $this->realm() . '/components', $payload);

        return $res->successful();
    }

    /**
     * Does this Keycloak group still exist?
     * true = exists, false = confirmed gone (404), null = Keycloak did not answer.
     */
    public function groupExists(string $token, string $groupId): ?bool
    {
        try {
            $res = Http::withToken($token)->timeout(20)
                ->get($this->baseUrl() . "/admin/realms/{$this->realm()}/groups/{$groupId}");

            if ($res->status() === 404) return false;
            if (!$res->successful()) return null;
            return true;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Does this Keycloak user still exist?
     * true = exists, false = confirmed gone, null = Keycloak did not answer.
     */
    public function userExists(string $token, string $userId): ?bool
    {
        try {
            $res = Http::withToken($token)->timeout(20)
                ->get($this->baseUrl() . "/admin/realms/{$this->realm()}/users/{$userId}");

            if ($res->status() === 404) return false;
            if (!$res->successful()) return null;
            return true;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Does this user have any credentials (a set password, OTP, ...)?
     * Used by teardown: a credentialed user predates the current provisioning
     * run and must not be destroyed by its rollback.
     * true = has credentials, false = confirmed none, null = could not tell.
     * Callers deciding whether to DELETE should treat null as "do not delete".
     */
    public function userHasCredentials(string $token, string $userId): ?bool
    {
        try {
            $res = Http::withToken($token)->timeout(20)
                ->get($this->baseUrl() . "/admin/realms/{$this->realm()}/users/{$userId}/credentials");
            if (!$res->successful()) return null;
            $creds = $res->json();
            return is_array($creds) && count($creds) > 0;
        } catch (\Throwable $e) {
            return null;
        }
    }
}