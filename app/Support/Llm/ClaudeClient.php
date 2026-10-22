<?php

namespace App\Support\Llm;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic Claude.
 *
 * Not a drop-in swap for the DeepSeek client. Three differences matter:
 *
 *  - Authentication is an x-api-key header, not a bearer token.
 *  - An anthropic-version header is required; without it the request is rejected.
 *  - The system prompt is a TOP-LEVEL parameter, not a message with role "system".
 *    BrianAssistant sends two system messages, so they are extracted here and
 *    joined. Left in the messages array they would be rejected outright.
 *
 * max_tokens is also mandatory, where OpenAI-compatible APIs treat it as optional.
 */
class ClaudeClient implements LlmClient
{
    public function __construct(
        private ?string $apiKey = null,
        private ?string $model  = null,
    ) {}

    public function chat(array $messages): string
    {
        $key = $this->apiKey ?: config('services.claude.api_key');

        if (!$key) {
            Log::warning('Claude called with no API key configured');
            return __("No AI provider is configured. Please add an API key under Your account.");
        }

        // Split system messages out of the conversation.
        $system = [];
        $turns  = [];

        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system') {
                $system[] = $m['content'] ?? '';
            } else {
                $turns[] = ['role' => $m['role'], 'content' => $m['content']];
            }
        }

        // Anthropic requires the conversation to begin with a user turn.
        if (empty($turns) || ($turns[0]['role'] ?? '') !== 'user') {
            array_unshift($turns, ['role' => 'user', 'content' => '(no question)']);
        }

        try {
            $res = Http::withHeaders([
                    'x-api-key'         => $key,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])
                ->timeout(150)
                ->retry(2, 3000, function ($e) {
                    return $e instanceof \Illuminate\Http\Client\ConnectionException
                        || $e instanceof \Illuminate\Http\Client\RequestException;
                }, throw: false)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'       => $this->model ?: config('services.claude.model', 'claude-sonnet-5'),
                    'max_tokens'  => 4096,
                    'system'      => implode("\n\n", $system),
                    'messages'    => $turns,
                    'temperature' => 0.2,
                ]);

            if (!$res->successful()) {
                return $this->explainFailure($res->status(), $res->json('error.message') ?? '');
            }

            // The response is an array of content blocks; join the text ones.
            $text = collect($res->json('content') ?? [])
                ->where('type', 'text')
                ->pluck('text')
                ->implode('');

            return $text !== ''
                ? $text
                : __("I did not get a usable answer. Please rephrase and try again.");

        } catch (\Throwable $e) {
            Log::warning('Claude exception', ['error' => $e->getMessage()]);
            return __("Sorry, something went wrong while I was thinking. Please try again.");
        }
    }

    /**
     * Say what actually went wrong.
     *
     * A tenant using their own API key needs to know the difference between
     * "the platform is broken" and "your key was rejected" or "your account is
     * out of credit". The second two they can fix themselves in minutes; a
     * generic failure message would leave them raising a support ticket instead.
     */
    private function explainFailure(int $status, string $detail): string
    {
        Log::warning('Claude API error', ['status' => $status, 'detail' => mb_substr($detail, 0, 300)]);

        return match (true) {
            $status === 401 => __("The AI key is not valid. Please check it under Your account."),
            $status === 403 => __("The AI key was refused. It may lack permission for this model."),
            $status === 429 => __("The AI provider is rate limiting us. Please try again shortly."),
            $status === 400 && str_contains(strtolower($detail), 'credit')
                            => __("The AI account has no remaining credit. Please top it up with your provider."),
            $status >= 500  => __("The AI provider is having trouble. Please try again shortly."),
            default         => __("The AI request was rejected. Please try again."),
        };
    }
}