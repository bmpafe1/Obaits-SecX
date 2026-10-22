<?php
namespace App\Support\Compliance;

use Illuminate\Support\Facades\DB;
use App\Support\TenantProfile;
use App\Support\DocumentApprovals;

class DelegatePack
{
    /**
     * Match the artefact's owner ROLE against the tenant's people register.
     * "HR Manager" should match "Head of HR", "HR Officer", "Human Resources Lead"…
     */
    public static function findOwner(string $tenantId, string $ownerRole): ?object
    {
        $people = DB::table('tenant_people')
            ->where('tenant_id', $tenantId)          // ISOLATION
            ->where('active', true)
            ->get();

        if ($people->isEmpty()) return null;

        // Strip the generic words so we match on the MEANINGFUL one:
        // "HR Manager" -> "hr";  "Facilities / Office Manager" -> "facilities";
        // "Procurement / Legal" -> "procurement";  "Managing Director" -> "managing"
        $stop = ['manager', 'officer', 'head', 'of', 'lead', '/', '&', 'and'];
        $keywords = collect(preg_split('/[\s\/&]+/', strtolower($ownerRole)))
            ->reject(fn ($w) => $w === '' || in_array($w, $stop))
            ->values();

        foreach ($keywords as $kw) {
            foreach ($people as $p) {
                if (str_contains(strtolower($p->title), $kw)) {
                    return $p;
                }
            }
        }

        return null;
    }

    /**
     * Produce the delegate pack: a covering note naming the right person,
     * plus a customised .docx template carrying the tenant's branding.
     */
    public static function pack(string $tenantId, string $key): array
    {
        $doc = DocumentRegister::get($key);
        if (!$doc) {
            return ['body' => '', 'owner' => null, 'note' => '', 'file' => null];
        }

        $profile = TenantProfile::for($tenantId);
        $owner = self::findOwner($tenantId, $doc['owner']);

        // The template body — sections for the owner to complete.
        $body = "## Purpose\n\n"
              . $doc['ask'] . "\n\n"

              . "## To be completed by\n\n"
              . ($owner ? "{$owner->name}, {$owner->title}" : "[{$doc['owner']}]") . "\n\n"

              . "## What to write here\n\n"
              . "[Please describe your current practice. Be factual — write what you actually do today, "
              . "not what you would like to do. If something is not done, say so plainly. That is useful "
              . "information, not a failure: it is exactly what needs to be known before an auditor finds it.]\n\n"

              . "## Evidence to attach\n\n"
              . "[Attach anything that shows this is really happening — records, signed forms, photographs, "
              . "screenshots, certificates. A statement without evidence is only a claim.]\n\n"

              . "## Why this matters\n\n"
              . "This document supports "
              . (empty($doc['controls']) ? 'the organisation\'s information security management system'
                                          : 'control(s) ' . implode(', ', $doc['controls']) . ' of ISO/IEC 27001')
              . ". An auditor will ask to see it.";

        // Render the .docx with the tenant's logo, legal name and signature block.
        $file = DocxRenderer::render(
            $tenantId,
            $doc,
            $profile,
            DocumentApprovals::for($tenantId, $key),
            $body
        );

        // The covering note Brian shows the user.
        $note = $owner
            ? "This one is not yours to write. Based on your people register, **{$owner->name} "
            . "({$owner->title})** is the right person. I have prepared a template with your "
            . "organisation's details already filled in — send it to them."
            : "This belongs to whoever handles **{$doc['owner']}** in your organisation. You have not "
            . "added that person to your people register yet. Add them on the People and approvals page "
            . "and I will address the template to them by name.";

        return [
            'body' => $body,
            'owner' => $owner,
            'note' => $note,
            'file' => $file,
        ];
    }
}