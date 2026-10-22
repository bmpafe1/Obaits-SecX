<?php
namespace App\Services;
 
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Support\Audit;
 
class DomainVerification
{
    public function issueToken(string $tenantId, string $domain): string
    {
        $token = 'obaits-verify=' . Str::random(40);
        DB::table('tenant_domains')->updateOrInsert(
            ['tenant_id' => $tenantId, 'domain' => $domain],
            ['id' => (string) Str::uuid(), 'verification_token' => $token,
             'verified' => false, 'updated_at' => now(), 'created_at' => now()]
        );
        return $token;
    }
 
    public function check(string $tenantId, string $domain): bool
    {
        $row = DB::table('tenant_domains')
            ->where('tenant_id', $tenantId)->where('domain', $domain)->first();
        if (!$row) return false;
 
        $mode = config('services.domain_verification.mode', 'dns');
 
        if ($mode === 'mock') {
            // In mock mode, a domain is considered verified only if its stored
            // token ends in "-VERIFIED". This lets tests simulate both outcomes
            // with no real DNS involved.
            $verified = str_ends_with($row->verification_token, '-VERIFIED');
        } else {
            $records = @dns_get_record('_obaits-challenge.' . $domain, DNS_TXT) ?: [];
            $verified = false;
            foreach ($records as $r) {
                if (($r['txt'] ?? '') === $row->verification_token) { $verified = true; break; }
            }
        }
 
        if ($verified) {
            DB::table('tenant_domains')->where('id', $row->id)
                ->update(['verified' => true, 'verified_at' => now(), 'updated_at' => now()]);
            Audit::log($tenantId, 'system', 'domain.verified', 'success', null, ['domain' => $domain, 'mode' => $mode]);
            return true;
        }
        Audit::log($tenantId, 'system', 'domain.verify_failed', 'failure', null, ['domain' => $domain, 'mode' => $mode]);
        return false;
    }
}
