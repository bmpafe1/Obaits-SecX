<?php
namespace App\Support\Llm;
 
interface LlmClient
{
    /**
     * @param array $messages  [ ['role'=>'system'|'user'|'assistant', 'content'=>string], ... ]
     * @return string  the assistant's reply text (never throws into the caller)
     */
    public function chat(array $messages): string;
}
