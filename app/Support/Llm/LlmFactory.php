<?php

namespace App\Support\Llm;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class LlmFactory
{
    /**
     * The client for a tenant.
     *
     * A tenant who has configured their own provider gets it — and if their key
     * fails, they are told. We deliberately do NOT fall back to the platform key
     * in that case: a tenant whose key expired would never notice, and the
     * platform would quietly resume paying for their usage.
     */
    public static function for(?string $tenantId): LlmClient
    {
        if ($tenantId) {
            $s = DB::table('tenant_ai_settings')->where('tenant_id', $tenantId)->first();

            if ($s && $s->provider !== 'platform' && $s->api_key) {
                try {
                    $key = Crypt::decryptString($s->api_key);
                } catch (\Throwable $e) {
                    // Key unreadable — usually APP_KEY changed. Do not silently
                    // fall through to the platform's key.
                    Log::warning('Tenant AI key could not be decrypted', ['tenant' => $tenantId]);
                    return new NullLlmClient(__('Your saved AI key could not be read. Please re-enter it under Your account.'));
                }

                return match ($s->provider) {
                    'claude'   => new ClaudeClient($key, $s->model ?: null),
                    'deepseek' => new DeepSeekClient($key, $s->model ?: null),
                    default    => self::platform(),
                };
            }
        }

        return self::platform();
    }

    private static function platform(): LlmClient
    {
        return match (config('services.llm.provider')) {
            'claude' => new ClaudeClient(),
            default  => new DeepSeekClient(),
        };
    }
}