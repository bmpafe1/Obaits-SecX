<?php

namespace App\Support\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

/**
 * Flutterwave v4 API client.
 *
 * CONFIRMED EMPIRICALLY 2026-07-25 against the real sandbox
 * (developersandbox-api.flutterwave.com), plus first-party docs fetched
 * directly rather than assumed:
 *
 * - OAuth2 client-credentials against idp.flutterwave.com, tokens last
 *   ~10 minutes — cached here with an 8-minute TTL as a safety margin.
 * - v4 has NO single-call hosted-checkout-link endpoint like v3's
 *   POST /v3/payments. It is object-oriented: create a customer, create a
 *   payment_method (shape varies by type), create a charge referencing
 *   both, then handle whatever next_action comes back.
 * - Card encryption: AES-256-GCM. Key = the Encryption Key, base64-decoded
 *   to raw bytes (confirmed: decodes to exactly 32 bytes). Nonce is exactly
 *   12 random alphanumeric characters, used as the GCM IV, ONE nonce shared
 *   across all four encrypted_* fields in a single request. Source, fetched
 *   directly: https://developer.flutterwave.com/docs/encryption — this is
 *   NOT the same as the older 3DES-whole-payload scheme documented for
 *   v2/v3; do not mix the two up.
 * - PHP's openssl_encrypt returns the GCM tag SEPARATELY from the
 *   ciphertext (Node/Java's crypto libraries append it automatically) —
 *   must concatenate ciphertext . tag before base64-encoding, or
 *   Flutterwave's decryption will fail with CLIENT_ENCRYPTION_ERROR.
 * - mobile_money payment methods: GET /mobile-networks?country=CM (alpha-2)
 *   confirms MTN and Orange Money ARE listed as supported for Cameroon, but
 *   POST /payment-methods with type=mobile_money rejects both a 3-digit
 *   ISO-numeric (120) and calling-code (237) country_code with "Country
 *   code not supported for Mobile Money" — a genuine, still-unresolved
 *   contradiction between two endpoints on the same account. createMobileMoneyPaymentMethod()
 *   is written but NOT confirmed working — a support question is pending
 *   with Flutterwave. Do not enable mobile money in the portal UI until
 *   this resolves; card is confirmed working end to end.
 */
class FlutterwaveClient
{
    private function baseUrl(): string
    {
        return rtrim((string) config('services.flutterwave.base_url'), '/');
    }

    /**
     * Cached OAuth2 access token. Flutterwave tokens last ~10 minutes;
     * cached for 8 to leave margin, and refetched transparently on expiry
     * rather than relying on a long-lived variable the way manual testing
     * did (which genuinely expired mid-session today).
     */
    private function token(): string
    {
        return Cache::remember('flutterwave_access_token', 480, function () {
            $res = Http::asForm()->post('https://idp.flutterwave.com/realms/flutterwave/protocol/openid-connect/token', [
                'grant_type'    => 'client_credentials',
                'client_id'     => config('services.flutterwave.client_id'),
                'client_secret' => config('services.flutterwave.client_secret'),
            ]);

            if (!$res->successful()) {
                throw new \RuntimeException('Flutterwave OAuth token request failed: HTTP ' . $res->status() . ' ' . substr($res->body(), 0, 300));
            }

            $token = $res->json('access_token');
            if (!$token) {
                throw new \RuntimeException('Flutterwave OAuth response had no access_token');
            }

            return $token;
        });
    }

    private function http(array $extraHeaders = [])
    {
        return Http::withToken($this->token())
            ->withHeaders(array_merge(
                ['X-Trace-Id' => 'obaits-' . bin2hex(random_bytes(8))],
                $extraHeaders
            ))
            ->asJson();
    }

    /**
     * Encrypt one field per Flutterwave's AES-256-GCM spec. $nonce must be
     * the SAME 12-character nonce used for every field in a single request.
     */
    private function encryptField(string $plaintext, string $nonce): string
    {
        if (strlen($nonce) !== 12) {
            throw new \InvalidArgumentException('Flutterwave nonce must be exactly 12 characters');
        }

        $key = base64_decode((string) config('services.flutterwave.encryption_key'), true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('Flutterwave encryption key is not valid base64 for a 32-byte AES-256 key');
        }

        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false) {
            throw new \RuntimeException('AES-256-GCM encryption failed');
        }

        return base64_encode($ciphertext . $tag);
    }

    private static function generateNonce(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $nonce = '';
        for ($i = 0; $i < 12; $i++) {
            $nonce .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $nonce;
    }

    /**
     * Idempotent: Flutterwave's /customers CONFIRMED EMPIRICALLY 2026-07-25
     * to reject a second create for an already-registered email with
     * HTTP 409 RESOURCE_CONFLICT ("Customer already exists"), rather than
     * returning the existing customer. A real tenant retrying checkout, or
     * simply re-visiting it, will legitimately hit this on the second
     * attempt — caught in production the first time the SAME real email
     * was submitted twice (every prior test used a fresh email each time,
     * so this was never exercised until then). Falls back to
     * POST /customers/search on a 409, which does return the existing
     * customer by email.
     */
    public function createCustomer(string $email): array
    {
        $res = $this->http()->post($this->baseUrl() . '/customers', ['email' => $email]);

        if ($res->status() === 409) {
            $search = $this->http()->post($this->baseUrl() . '/customers/search', ['email' => $email]);
            $existing = $search->json('data.0');
            if ($search->successful() && $existing) {
                return $existing;
            }
            throw new \RuntimeException('Flutterwave customer conflict but search found nothing: HTTP ' . $search->status() . ' ' . substr($search->body(), 0, 500));
        }

        if (!$res->successful()) {
            throw new \RuntimeException('Flutterwave create customer failed: HTTP ' . $res->status() . ' ' . substr($res->body(), 0, 500));
        }

        return $res->json('data');
    }

    public function createCardPaymentMethod(string $cardNumber, string $expiryMonth, string $expiryYear, string $cvv): array
    {
        $nonce = self::generateNonce();

        $payload = [
            'type' => 'card',
            'card' => [
                'nonce'                  => $nonce,
                'encrypted_card_number'  => $this->encryptField($cardNumber, $nonce),
                'encrypted_expiry_month' => $this->encryptField($expiryMonth, $nonce),
                'encrypted_expiry_year'  => $this->encryptField($expiryYear, $nonce),
                'encrypted_cvv'          => $this->encryptField($cvv, $nonce),
            ],
        ];

        $res = $this->http()->post($this->baseUrl() . '/payment-methods', $payload);

        if (!$res->successful()) {
            throw new \RuntimeException('Flutterwave create card payment method failed: HTTP ' . $res->status() . ' ' . substr($res->body(), 0, 500));
        }

        return $res->json('data');
    }

    public function createMobileMoneyPaymentMethod(string $countryCode, string $phoneNumber, string $network): array
    {
        $res = $this->http()->post($this->baseUrl() . '/payment-methods', [
            'type' => 'mobile_money',
            'mobile_money' => [
                'country_code' => $countryCode,
                'phone_number' => $phoneNumber,
                'network'      => $network,
            ],
        ]);

        if (!$res->successful()) {
            throw new \RuntimeException('Flutterwave create mobile money payment method failed: HTTP ' . $res->status() . ' ' . substr($res->body(), 0, 500));
        }

        return $res->json('data');
    }

    /**
     * $scenarioKey: SANDBOX-ONLY testing tool, sets X-Scenario-Key to force
     * a specific test outcome. The format REQUIRES the 'scenario:' prefix --
     * 'scenario:auth_pin&issuer:approved' forces a
     * PIN-required response. WITHOUT the prefix the header is ignored and
     * the charge silently takes the default noauth flow, which is what
     * happened on 2026-08-31 and made the chain look untestable.
     * Confirmed in Flutterwave's own
     * testing docs (developer.flutterwave.com/docs/testing). Never set in
     * production — leaving it null (the default) omits the header
     * entirely, and Flutterwave's real production API does not act on it.
     */
    public function createCharge(string $paymentMethodId, string $customerId, int $amountMinorUnits, string $currency, string $reference, string $redirectUrl, array $meta = [], ?string $scenarioKey = null): array
    {
        $payload = [
            'payment_method_id' => $paymentMethodId,
            'customer_id'       => $customerId,
            'amount'            => $amountMinorUnits,
            'currency'          => $currency,
            'reference'         => $reference,
            'redirect_url'      => $redirectUrl,
        ];

        // CONFIRMED EMPIRICALLY 2026-07-25: an empty PHP array [] serialises
        // to JSON [] rather than {}. Flutterwave's schema expects meta as an
        // OBJECT and rejects the mismatch with a bare "Malformed request"
        // and no field-level detail — the least informative error we hit
        // all session. Only include meta when non-empty, and force object
        // encoding with (object) so a populated meta can never regress into
        // the same trap.
        if (!empty($meta)) {
            $payload['meta'] = (object) $meta;
        }

        $headers = $scenarioKey ? ['X-Scenario-Key' => $scenarioKey] : [];
        $res = $this->http($headers)->post($this->baseUrl() . '/charges', $payload);

        if (!$res->successful()) {
            throw new \RuntimeException('Flutterwave create charge failed: HTTP ' . $res->status() . ' ' . substr($res->body(), 0, 500));
        }

        return $res->json();
    }

    public function submitCardPin(string $chargeId, string $pin): array
    {
        $nonce = self::generateNonce();

        $res = $this->http()->put($this->baseUrl() . "/charges/{$chargeId}", [
            'authorization' => [
                'type' => 'pin',
                'pin'  => [
                    'nonce'         => $nonce,
                    'encrypted_pin' => $this->encryptField($pin, $nonce),
                ],
            ],
        ]);

        if (!$res->successful()) {
            throw new \RuntimeException('Flutterwave PIN submission failed: HTTP ' . $res->status() . ' ' . substr($res->body(), 0, 500));
        }

        return $res->json();
    }

    /**
     * CONFIRMED EMPIRICALLY 2026-07-25: submitting a PIN can resolve the
     * charge OR move it to a FURTHER step (next_action.type=requires_otp)
     * — this is a genuine multi-step authorization chain, not a single
     * PIN-and-done flow. field_name is authorization.otp.code (found via
     * a deliberately wrong first guess — the API's own validation error
     * named the real field).
     */
    public function submitCardOtp(string $chargeId, string $code): array
    {
        $res = $this->http()->put($this->baseUrl() . "/charges/{$chargeId}", [
            'authorization' => [
                'type' => 'otp',
                'otp'  => ['code' => $code],
            ],
        ]);

        if (!$res->successful()) {
            throw new \RuntimeException('Flutterwave OTP submission failed: HTTP ' . $res->status() . ' ' . substr($res->body(), 0, 500));
        }

        return $res->json();
    }

    /** Confirmed valid values for the refunds 'reason' field (a closed
     * enum, NOT free text — 'other' was tried and rejected). Confirmed
     * empirically 2026-07-25: duplicate, fraudulent, requested_by_customer
     * all pass; there may be more, but these are the ones proven. */
    public const REFUND_REASON_DUPLICATE = 'duplicate';
    public const REFUND_REASON_FRAUDULENT = 'fraudulent';
    public const REFUND_REASON_REQUESTED_BY_CUSTOMER = 'requested_by_customer';

    /**
     * Initiate a refund for a completed charge.
     *
     * CONFIRMED EMPIRICALLY 2026-07-25 against the live sandbox:
     * - amount is REQUIRED, contrary to the docs example — omitting it
     *   fails with "amount must not be null" even for a full refund. Full
     *   refund still means passing the original charge amount explicitly.
     * - reason is a closed enum, not free text. 'other' is rejected
     *   outright with "Invalid value passed for enum 'ReasonEnum'." — use
     *   one of the REFUND_REASON_* constants above.
     * - A successful call returns status "new", not "completed" — refunds
     *   appear to process asynchronously. Not yet confirmed how completion
     *   is reported (webhook event? polling /refunds?) — treat "new" as
     *   "initiated, not yet certain to have reached the customer" until
     *   that's investigated further.
     */
    public function refundCharge(string $chargeId, int $amountMinorUnits, string $reason): array
    {
        $res = $this->http()->post($this->baseUrl() . '/refunds', [
            'charge_id' => $chargeId,
            'amount' => $amountMinorUnits,
            'reason' => $reason,
        ]);

        if (!$res->successful()) {
            throw new \RuntimeException('Flutterwave refund failed: HTTP ' . $res->status() . ' ' . substr($res->body(), 0, 500));
        }

        return $res->json('data');
    }

    public function getCharge(string $chargeId): array
    {
        $res = $this->http()->get($this->baseUrl() . "/charges/{$chargeId}");

        if (!$res->successful()) {
            throw new \RuntimeException('Flutterwave get charge failed: HTTP ' . $res->status() . ' ' . substr($res->body(), 0, 500));
        }

        return $res->json('data');
    }
}
