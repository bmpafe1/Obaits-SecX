<?php
namespace App\Clients;

use Illuminate\Support\Facades\Http;

/**
 * Real IRIS (dfir-iris/iris-web) API client. One SHARED, admin-level
 * API key authenticates every request regardless of which tenant it's
 * acting on behalf of -- matching this project's own established
 * pattern for Wazuh/Zabbix (one shared service credential, not one per
 * end user). A real, honest characteristic worth knowing: every action
 * this client takes is attributed to the "administrator" IRIS user in
 * IRIS's own internal audit trail, not to the real end user who
 * triggered it from our portal.
 *
 * REAL, CONFIRMED LIMITATION (verified directly against our own live
 * v2.4.29 instance, matching a still-open GitHub issue for the nearby
 * v2.4.22): the modern POST /api/v2/cases endpoint genuinely returns
 * "Resource not found" -- it does not work in this version range
 * despite being documented as the real, non-deprecated path. Real case
 * creation uses the older /manage/cases/add endpoint instead, confirmed
 * directly to work.
 */
class IrisClient
{
    private function baseUrl(): string
    {
        return rtrim(config('services.iris.base_url'), '/');
    }

    private function http()
    {
        return Http::withoutVerifying()
            ->withToken(config('services.iris.api_key'))
            ->timeout(20);
    }

    /**
     * Real, live authentication test -- returns true only if IRIS
     * itself confirms the key is valid ("pong").
     */
    public function ping(): bool
    {
        try {
            $res = $this->http()->get($this->baseUrl() . '/api/ping');
            return $res->successful() && ($res->json('message') === 'pong');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function listCustomers(): array
    {
        try {
            $res = $this->http()->get($this->baseUrl() . '/manage/customers/list');
            return $res->json('data') ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Real customer creation. Returns the real, live customer_id and
     * client_uuid IRIS assigns, or null on failure.
     */
    public function createCustomer(string $name): ?array
    {
        try {
            $res = $this->http()->post($this->baseUrl() . '/manage/customers/add', [
                'customer_name' => $name,
            ]);
            if (!$res->successful() || $res->json('status') !== 'success') {
                return null;
            }
            return [
                'customer_id' => $res->json('data.customer_id'),
                'client_uuid' => $res->json('data.client_uuid'),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Real, complete case list -- IRIS has no native per-customer
     * filter at this API version (confirmed directly: cid=1 does not
     * restrict results at all), so callers filter client-side by the
     * real client_name field each case already carries.
     */
    public function listAllCases(): array
    {
        try {
            $res = $this->http()->get($this->baseUrl() . '/manage/cases/list', ['cid' => 1]);
            return $res->json('data') ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function listCasesForCustomerName(string $customerName): array
    {
        return array_values(array_filter(
            $this->listAllCases(),
            fn ($case) => ($case['client_name'] ?? null) === $customerName
        ));
    }

    /**
     * Real case creation via the CONFIRMED-WORKING legacy endpoint (see
     * this class's own real, documented limitation above -- the
     * "correct" v2 endpoint genuinely does not work on this version).
     */
    public function createCase(int $customerId, string $name, string $description, ?string $socId = null): ?array
    {
        try {
            $res = $this->http()->post($this->baseUrl() . '/manage/cases/add', [
                'case_name' => $name,
                'case_description' => $description,
                'case_customer' => $customerId,
                'case_soc_id' => $socId ?? ('OBAITS-' . now()->format('YmdHis')),
            ], ['cid' => 1]);
            if (!$res->successful() || $res->json('status') !== 'success') {
                return null;
            }
            return $res->json('data');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
