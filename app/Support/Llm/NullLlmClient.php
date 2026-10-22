<?php

namespace App\Support\Llm;

/** Returns a specific, actionable message instead of pretending to work. */
class NullLlmClient implements LlmClient
{
    public function __construct(private string $message) {}

    public function chat(array $messages): string
    {
        return $this->message;
    }
}