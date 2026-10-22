<?php
namespace App\Support\Ai;
 
class Deidentifier
{
    // Replace tenant-identifying details with generic descriptions before any external call.
    public static function scrub(string $text): string
    {
        // IPv4 addresses
        $text = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[an IP address]', $text);
        // Email addresses
        $text = preg_replace('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', '[an email address]', $text);
        // Hostnames / FQDNs (simple heuristic: word.word(.word)+ )
        $text = preg_replace('/\b([a-z0-9\-]+\.)+[a-z]{2,}\b/i', '[a hostname]', $text);
        // URLs
        $text = preg_replace('#https?://\S+#i', '[a URL]', $text);
        return $text;
    }
 
    // Whether external LLM calls are allowed to include tenant data at all.
    // For the cloud provider we scrub; a future local provider may set this false to skip scrubbing.
    public static function required(): bool
    {
        return config('services.llm.provider') !== 'local';
    }
}
