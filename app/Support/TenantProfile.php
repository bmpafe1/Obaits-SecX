<?php
namespace App\Support;
 
use Illuminate\Support\Facades\DB;
 
class TenantProfile
{
    // The full profile for a tenant, merged with the tenants row. Always tenant-scoped.
    public static function for(string $tenantId): array
    {
        $tenant = DB::table('tenants')->where('id', $tenantId)->first();
        $p = DB::table('tenant_profiles')->where('tenant_id', $tenantId)->first();
 
        return [
            // Fall back to the registration name until the legal name is supplied.
            'legal_name'  => $p->legal_name ?? ($tenant->organization_name ?? ''),
            'trading_name'=> $p->trading_name ?? ($tenant->organization_name ?? ''),
            'registration_number' => $p->registration_number ?? '',
            'logo_path'   => $p->logo_path ?? null,
 
            'address_line1' => $p->address_line1 ?? '',
            'address_line2' => $p->address_line2 ?? '',
            'city'          => $p->city ?? '',
            'country'       => $p->country ?? '',
 
            'phone'         => $p->phone ?? '',
            'contact_email' => $p->contact_email ?? '',
            'website'       => $p->website ?? ($tenant->primary_domain ?? ''),
 
            'security_officer_name'  => $p->security_officer_name ?? '',
            'security_officer_title' => $p->security_officer_title ?? '',
            'approver_name'  => $p->approver_name ?? '',
            'approver_title' => $p->approver_title ?? '',
            'sector'         => $p->sector ?? '',
            'headcount'      => $p->headcount ?? null,
 
            'doc_version'    => $p->doc_version ?? '1.0',
            'effective_date' => $p->effective_date ?? null,
            'review_cycle'   => $p->review_cycle ?? 'annual',
 
            'isms_scope'     => $p->isms_scope ?? '',
        ];
    }
 
    // Fields a compliance document CANNOT be issued without.
    public const REQUIRED = [
        'legal_name'            => 'Legal entity name',
        'address_line1'         => 'Registered address',
        'city'                  => 'City',
        'country'               => 'Country',
        'security_officer_name' => 'Person responsible for information security',
        'approver_name'         => 'Person who approves policies',
        'isms_scope'            => 'What your security programme covers',
    ];
 
    // Which required fields are still missing (drives the completeness indicator + the Stage 6 gate).
    public static function missing(string $tenantId): array
    {
        $data = self::for($tenantId);
        $missing = [];
        foreach (self::REQUIRED as $key => $label) {
            if (empty($data[$key])) $missing[$key] = $label;
        }
        return $missing;
    }
 
    public static function completeness(string $tenantId): int
    {
        $total = count(self::REQUIRED);
        $missing = count(self::missing($tenantId));
        return (int) round((($total - $missing) / $total) * 100);
    }
 
    // Is this tenant ready to issue compliance documents?
    public static function readyForDocuments(string $tenantId): bool
    {
        return empty(self::missing($tenantId));
    }
}
