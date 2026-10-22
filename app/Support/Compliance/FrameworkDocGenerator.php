<?php
namespace App\Support\Compliance;
 
use App\Clients\CisoClient;
use App\Support\Llm\LlmClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
 
class FrameworkDocGenerator
{
    public function __construct(
        private CisoClient $ciso,
        private LlmClient $llm,
    ) {}
 
    /**
     * Draft a document set for a framework, grounded in its real controls.
     * Returns ['created' => int, 'skipped' => int, 'error' => ?string].
     */
public function generate(string $frameworkId, string $frameworkName): array
    {
        // 1. GROUND: the framework's real, assessable controls from CISO.
        $controls = $this->ciso->requirements($frameworkId);
        if (empty($controls)) {
            return ['created' => 0, 'skipped' => 0,
                'error' => 'This framework has no assessable controls in CISO, so nothing can be drafted from it.'];
        }

        // Build the real reference set the AI must stay within.
        // Build the real reference set the AI must stay within.
        $realRefs = [];
        $controlLines = [];
        foreach ($controls as $c) {
            $ref = $c['ref_id'] ?? null;
            if (!$ref) continue;
            $realRefs[$ref] = true;
            $desc = $c['description'] ?? ($c['display_long'] ?? '');
            // Trim each description — the AI groups controls by ref_id plus a short hint,
            // not full text. This cuts the prompt from ~91K to ~30K chars: far fewer timeouts.
            // Every ref_id stays intact, so grounding and the anti-hallucination gate are unaffected.
            $desc = \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', $desc)), 110, '');
            $controlLines[] = "{$ref}: {$desc}";
        }
        if (empty($realRefs)) {
            return ['created' => 0, 'skipped' => 0, 'error' => 'No usable control references found.'];
        }

        // 2. ASK the model to ORGANISE the real controls into documents.
        $prompt = $this->buildPrompt($frameworkName, $controlLines);
        try {
            $raw = $this->llm->chat([
                ['role' => 'system', 'content' => self::SYSTEM],
                ['role' => 'user', 'content' => $prompt],
            ]);
        } catch (\Throwable $e) {
            return ['created' => 0, 'skipped' => 0, 'error' => 'The AI service could not be reached. Please try again.'];
        }

        $docs = $this->parseJson($raw);
        if ($docs === null) {
            return ['created' => 0, 'skipped' => 0, 'error' => 'The AI returned something we could not read. Please try again.'];
        }

        // 3. VALIDATE + STORE. Drop any invented references; write everything unverified.
        $created = 0; $skipped = 0;
        foreach ($docs as $doc) {
            $title = trim($doc['title'] ?? '');

            // AI documents are ALWAYS interview-mode: their content comes from the user's own
            // answers via InterviewGenerator (which never claims what the user did not describe).
            // AutoGenerator only understands the built-in automated document keys, so an AI
            // doc_key must never be automated, or it would fail to generate any content.
            $mode = 'interview';

            $refs  = array_values(array_filter(
                (array) ($doc['controls'] ?? []),
                fn ($r) => isset($realRefs[$r])       // ANTI-HALLUCINATION: real refs only
            ));

            if ($title === '' || empty($refs)) { $skipped++; continue; }

            // Plain-language interview questions, grounded in this document's purpose.
            $questions = array_values(array_filter(
                array_map('trim', (array) ($doc['questions'] ?? [])),
                fn ($q) => $q !== ''
            ));
            $questions = array_slice($questions, 0, 6);   // 3-6 intended; cap defensively

            // Stable, namespaced key so regen is idempotent and never collides with hand-built docs.
            $docKey = 'ai_' . Str::slug(substr($frameworkName, 0, 20)) . '_' . Str::slug(substr($title, 0, 40));
            $docKey = substr($docKey, 0, 120);

            DB::table('compliance_documents')->updateOrInsert(
                ['doc_key' => $docKey],
                [
                    'title'      => $title,
                    'mode'       => $mode,
                    'source'     => 'ai_drafted',
                    'owner_role' => $doc['owner'] ?? null,
                    'ask'        => $doc['outline'] ?? null,
                    'questions'  => !empty($questions) ? json_encode($questions) : null,
                    'advice'     => 'This document was drafted by AI from the standard\'s controls. Answer the questions in your own words; anything you leave blank is flagged as a gap. Review the result before relying on it.',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            foreach ($refs as $ref) {
                DB::table('document_control_map')->updateOrInsert(
                    ['doc_key' => $docKey, 'framework_id' => $frameworkId, 'control_ref' => $ref],
                    ['id' => (string) Str::uuid(), 'verified' => false,   // UNVERIFIED by construction
                     'updated_at' => now(), 'created_at' => now()]
                );
            }
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'error' => null];
    }
 
    private const SYSTEM =
        "You are a compliance documentation planner. You are given the ACTUAL controls of a framework. " .
        "Your ONLY job is to group them into the documents an auditor expects, using ONLY the control references provided. " .
        "You must never invent controls, references, or requirements. If unsure, include fewer items. " .
        "Respond with STRICT JSON only, no prose, no markdown.";
 
    private function buildPrompt(string $framework, array $controlLines): string
    {
        $list = implode("\n", $controlLines);
        return <<<TXT
Framework: {$framework}
 
Here are its actual controls (reference: description):
{$list}
 
Produce a JSON array of the documents an organisation needs to satisfy these controls.
Each element:
{
  "title": "string — the document's name",
  "mode": "one of: automated | interview | delegate | guide",
  "owner": "string — the role responsible, e.g. 'IT Manager'",
  "outline": "string — 2-4 sentences on what the document must contain",
  "questions": ["3 to 6 plain-language questions whose answers are everything needed to write this document"],
  "controls": ["array of control references from the list above that this document addresses"]
}

 
Rules:
- Use ONLY control references that appear in the list above. Do not invent any.
- Prefer 8-25 documents that together cover the controls.
- 'mode' meaning: automated = can be built from system data; interview = written after asking the user questions; delegate = owned by another party; guide = only the user can do it.
- 'questions' must be in plain language a non-specialist small-business owner can answer. No jargon. Concrete and specific to what this document needs. Examples of the right style: "Who keeps track of the computers and phones you own?", "What happens to an account when someone leaves?"
- Ask about what the organisation actually DOES, so the document can be written from their real answers. Do not ask them to write policy; ask about their practices.

Return the JSON array and nothing else.
TXT;
    }
 
    private function safeMode(string $m): string
    {
        return in_array($m, ['automated','interview','delegate','guide'], true) ? $m : 'guide';
    }
 
    private function parseJson(string $raw): ?array
    {
        // Strip any accidental markdown fences.
        $raw = trim(preg_replace('/^```(json)?|```$/m', '', $raw));
        $data = json_decode($raw, true);
        if (is_array($data)) return $data;
 
        // Sometimes a model wraps the array in an object; try to find the first array.
        if (preg_match('/\[.*\]/s', $raw, $m)) {
            $data = json_decode($m[0], true);
            if (is_array($data)) return $data;
        }
        return null;
    }
}
