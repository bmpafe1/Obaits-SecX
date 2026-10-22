<?php

namespace App\Support\Llm;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeepSeekClient implements LlmClient
{
    /**
     * A tenant's own key and model, or null to use the platform's.
     *
     * Without this constructor the class silently ignored whatever the factory
     * passed and read config instead — so a tenant's key was accepted, stored
     * and never used, and the request went out with an empty bearer token.
     */
    public function __construct(
        private ?string $apiKey = null,
        private ?string $model  = null,
    ) {}

    public function chat(array $messages): string
    {
        $base  = rtrim(config('services.deepseek.base_url'), '/');
        $key   = $this->apiKey ?: config('services.deepseek.api_key');
        $model = $this->model  ?: config('services.deepseek.model');

        if (!$key) {
            Log::warning('DeepSeek called with no API key');
            return __("No AI provider is configured. Please add an API key under Your account.");
        }

        try {
            $res = Http::withToken($key)
                ->timeout(150)
                // Retry up to twice, 3s apart, on connection/timeout errors — DeepSeek
                // occasionally sends nothing (cURL 28) or stalls. One hiccup shouldn't fail generation.
                ->retry(2, 3000, function ($exception, $request) {
                    return $exception instanceof \Illuminate\Http\Client\ConnectionException
                        || $exception instanceof \Illuminate\Http\Client\RequestException;
                }, throw: false)
                ->post($base . '/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                    'stream' => false,
                    // Non-thinking mode: consistent, grounded answers; temperature applies.
                    'temperature' => 0.2,
                ]);

            if (!$res->successful()) {
                Log::warning('DeepSeek error', ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 500)]);
                return $this->explainFailure($res->status(), (string) $res->body());
            }

            // OpenAI-compatible shape. Only read 'content' (never 'reasoning_content').
            return $res->json('choices.0.message.content')
                ?? __("I did not get a usable answer. Please rephrase and try again.");

        } catch (\Throwable $e) {
            Log::warning('DeepSeek exception', ['error' => $e->getMessage()]);
            return __("Sorry, something went wrong while I was thinking. Please try again.");
        }
    }

    /**
     * Say what actually went wrong.
     *
     * A rejected key previously surfaced as "could not reach my reasoning
     * service", which sends a tenant to support for something they could fix in
     * two minutes — and hid the fact that the credential was dead rather than
     * the network being flaky.
     */
    private function explainFailure(int $status, string $body): string
    {
        $b = strtolower($body);

        return match (true) {
            $status === 401 => __("The AI key was rejected. Please check it under Your account — if it is your own key, confirm it is still active with the provider."),
            $status === 402 || str_contains($b, 'insufficient balance')
                            => __("The AI account has no remaining credit. Please top it up with your provider."),
            $status === 429 => __("The AI provider is rate limiting us. Please try again shortly."),
            $status === 400 && str_contains($b, 'model')
                            => __("That AI model name was not recognised. Please check the model under Your account."),
            $status >= 500  => __("The AI provider is having trouble. Please try again shortly."),
            default         => __("The AI request was rejected. Please try again."),
        };
    }
}