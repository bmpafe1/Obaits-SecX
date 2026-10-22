<?php
namespace App\Support;
 
use Illuminate\Support\Facades\DB;
 
class DocumentApprovals
{
    // Signature blocks for a document type: ['prepared_by' => [ ['name','title'], ... ], ...]
    public static function for(string $tenantId, string $documentType): array
    {
        $rows = DB::table('document_approvals as da')
            ->join('tenant_people as p', 'p.id', '=', 'da.person_id')
            ->where('da.tenant_id', $tenantId)               // ISOLATION
            ->where('da.document_type', $documentType)
            ->orderBy('da.sort_order')
            ->select('da.role', 'p.name', 'p.title')
            ->get();
 
        $out = ['prepared_by' => [], 'reviewed_by' => [], 'approved_by' => []];
        foreach ($rows as $r) {
            $out[$r->role][] = ['name' => $r->name, 'title' => $r->title];
        }
        return $out;
    }
 
    // Can this document be issued? It needs at least a preparer and an approver.
    public static function ready(string $tenantId, string $documentType): bool
    {
        $a = self::for($tenantId, $documentType);
        return !empty($a['prepared_by']) && !empty($a['approved_by']);
    }
 
    // Advisory only — never blocking.
    public static function advisories(string $tenantId, string $documentType): array
    {
        $a = self::for($tenantId, $documentType);
        $notes = [];
 
        if (empty($a['reviewed_by'])) {
            $notes[] = 'No reviewer is set. An auditor may ask whether anyone independent checked this document.';
        }
 
        $prep = collect($a['prepared_by'])->pluck('name');
        $appr = collect($a['approved_by'])->pluck('name');
        if ($prep->isNotEmpty() && $prep->diff($appr)->isEmpty() && $appr->diff($prep)->isEmpty()) {
            $notes[] = 'The same person prepares and approves this document. That is common in a small organisation, but an auditor may ask how independence is achieved.';
        }
 
        return $notes;
    }
}
