<?php

namespace App\Support\Llm;

/**
 * Which tenant the next LLM resolution is for.
 *
 * A queued job has no session, so the tenant cannot be inferred there. Inferring
 * it wrongly would mean one customer's API key paying for another customer's
 * documents, and their compliance content going to a provider they did not
 * choose — a tenant isolation break, not merely a billing error.
 *
 * So a job states the tenant explicitly. Web requests fall back to the session.
 */
class LlmContext
{
    private static ?string $tenantId = null;

    public static function set(?string $tenantId): void { self::$tenantId = $tenantId; }
    public static function tenantId(): ?string { return self::$tenantId; }
    public static function clear(): void { self::$tenantId = null; }
}