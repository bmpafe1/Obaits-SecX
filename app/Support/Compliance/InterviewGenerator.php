<?php
namespace App\Support\Compliance;

use App\Support\Ai\BrianAssistant;

class InterviewGenerator
{
    /**
     * Brian writes the document from the user's OWN answers.
     *
     * @param array $answers  indexed by question position: [0 => 'weekly', 1 => 'in the cloud', ...]
     */
    public static function body(string $tenantId, string $key, array $doc, array $answers): string
    {
        // Pair each question with what the user actually said.
        $qa = [];
        foreach (($doc['questions'] ?? []) as $i => $q) {
            $a = trim($answers[$i] ?? '');
            $qa[] = "Q: {$q}\nA: " . ($a !== '' ? $a : '(not answered)');
        }

        $prompt = "Write a short, professional \"{$doc['title']}\" for a small organisation, "
            . "using ONLY the facts below.\n\n"
            . "Rules you must follow:\n"
            . "- Write in plain language a non-specialist can follow.\n"
            . "- Do NOT invent any practice the organisation did not describe. If they said they do not do "
            . "something, or did not answer, write a clearly marked [gap: ...] note explaining what is missing "
            . "and why it matters. Never claim something is done when it is not.\n"
            . "- Structure with these ## headings: Purpose, Scope, Policy, Responsibilities, Review.\n"
            . "- Keep it concise. This is a working document, not an essay.\n"
            . "- Do not add a signature block or version number — those are added automatically.\n\n"
            . "What the organisation told us:\n\n"
            . implode("\n\n", $qa);

        // Grounded strictly in the user's own answers.
        return BrianAssistant::reply($tenantId, $prompt, [], session('locale', app()->getLocale()));
    }
}