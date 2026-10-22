<?php
namespace App\Support\Compliance;
 
use Illuminate\Support\Facades\DB;
 
class DocumentRegister
{
    public const AUTOMATED = 'automated';
    public const INTERVIEW = 'interview';
    public const DELEGATE  = 'delegate';
    public const GUIDE     = 'guide';
 
    // All documents relevant to the frameworks a tenant is pursuing.
    // A document appears once, even if several frameworks require it.
    public static function forTenant(string $tenantId): array
    {
        $fwIds = DB::table('tenant_frameworks')->where('tenant_id', $tenantId)
            ->pluck('framework_id');
 
        if ($fwIds->isEmpty()) return [];
 
        $keys = DB::table('document_control_map')
            ->whereIn('framework_id', $fwIds)
            ->pluck('doc_key')->unique();
 
        // Documents with no control mapping at all (e.g. the GUIDE items) still belong.
        $unmapped = DB::table('compliance_documents')
            ->whereNotIn('doc_key', DB::table('document_control_map')->pluck('doc_key'))
            ->pluck('doc_key');
 
        $rows = DB::table('compliance_documents')
            ->whereIn('doc_key', $keys->merge($unmapped))
            ->get();
 
        $out = [];
        foreach ($rows as $r) {
            $out[$r->doc_key] = self::hydrate($r, $fwIds->all());
        }
        return $out;
    }
 
    public static function get(string $key, ?string $tenantId = null): ?array
    {
        $r = DB::table('compliance_documents')->where('doc_key', $key)->first();
        if (!$r) return null;
 
        $fwIds = $tenantId
            ? DB::table('tenant_frameworks')->where('tenant_id', $tenantId)->pluck('framework_id')->all()
            : [];
 
        return self::hydrate($r, $fwIds);
    }
 
    private static function hydrate(object $r, array $fwIds): array
    {
        // Which controls this document satisfies, per framework — and whether VERIFIED.
        $maps = DB::table('document_control_map as m')
            ->join('tenant_frameworks as tf', 'tf.framework_id', '=', 'm.framework_id')
            ->where('m.doc_key', $r->doc_key)
            ->when($fwIds, fn ($q) => $q->whereIn('m.framework_id', $fwIds))
            ->select('m.framework_id', 'tf.framework_name', 'm.control_ref', 'm.verified')
            ->distinct()->get()
            ->groupBy('framework_name');
 
        return [
            'key' => $r->doc_key,
            'title' => $r->title,
            'mode' => $r->mode,
            'source' => $r->source,
            'questions' => json_decode($r->questions ?? '[]', true) ?: [],
            'owner' => $r->owner_role,
            'ask' => $r->ask,
            'advice' => $r->advice,
            'frameworks' => $maps,        // per-framework control refs, with verified flags
        ];
    }
 
    public static function byMode(string $tenantId, string $mode): array
    {
        return array_filter(self::forTenant($tenantId), fn ($d) => $d['mode'] === $mode);
    }
}
