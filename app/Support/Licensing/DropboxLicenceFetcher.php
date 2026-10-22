<?php
namespace App\Support\Licensing;
 
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
 
class DropboxLicenceFetcher
{
    public const STALE_CEILING_DAYS = 30;
 
    // ---- Mint a short-lived access token from the long-lived refresh token. ----
    // Dropbox access tokens expire in a few hours; refresh tokens do not expire
    // unless revoked. We fetch a fresh access token on every call rather than
    // caching one across requests — this is a low-frequency job (hourly), so
    // the extra round-trip costs nothing worth optimizing away.
    private function accessToken(): string
    {
        $res = Http::asForm()->timeout(15)->post('https://api.dropboxapi.com/oauth2/token', [
            'grant_type'    => 'refresh_token',
            'refresh_token' => config('services.dropbox.refresh_token'),
            'client_id'     => config('services.dropbox.app_key'),
            'client_secret' => config('services.dropbox.app_secret'),
        ]);
        if (!$res->successful()) {
            throw new \RuntimeException('Dropbox token refresh failed: HTTP ' . $res->status() . ' ' . substr($res->body(), 0, 200));
        }
        $token = $res->json('access_token');
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Dropbox token refresh returned no access_token');
        }
        return $token;
    }

    // ---- Download the master file, or throw. Shared by both entry points. ----
    private function download(): string
    {
        $res = Http::withToken($this->accessToken())
            ->withHeaders(['Dropbox-API-Arg' => json_encode(['path' => config('services.dropbox.path')])])
            ->timeout(30)
            ->post('https://content.dropboxapi.com/2/files/download');
 
        if (!$res->successful()) {
            throw new \RuntimeException('Dropbox returned HTTP ' . $res->status());
        }
        return $res->body();
    }
 
    // ---- THE DAILY/HOURLY RUN: refresh the whole cache. ----
    public function run(): array
    {
        if (DB::table('license_fetch_state')->count() === 0) {
            DB::table('license_fetch_state')->insert(['last_attempt_at' => now(), 'consecutive_failures' => 0]);
        } else {
            DB::table('license_fetch_state')->update(['last_attempt_at' => now()]);
        }
        try {
            $csv = $this->download();
        } catch (\Throwable $e) {
            return $this->fail('Could not reach Dropbox: ' . $e->getMessage());
        }
 
        $rows = $this->parse($csv);
        if ($rows === null)  return $this->fail('The licence file could not be parsed.');
        if (empty($rows))    return $this->fail('The licence file was empty; keeping last-known-good.');
 
        $applied = 0; $skipped = 0;
        foreach ($rows as $row) {
            $outcome = $this->resolve($row);
            if ($outcome === null) { $skipped++; continue; }
 
            DB::table('license_cache')->updateOrInsert(
                ['tenant_id' => $outcome['tenant_id']],
                [
                    'admin_email' => $outcome['email'],
                    'expires_on'  => $outcome['expires_on'],
                    'updated_at'  => now(),
                    'created_at'  => now(),
                ]
            );
            $applied++;
        }
 
        DB::table('license_fetch_state')->update([
            'last_fetch_ok_at'     => now(),
            'last_error'           => null,
            'consecutive_failures' => 0,
        ]);
 
        return ['ok' => true, 'applied' => $applied, 'skipped' => $skipped, 'error' => null];
    }
 
    // ---- ON-DEMAND: validate ONE tenant's presented key against THEIR OWN row. ----
    public function verifyKeyForTenant(string $tenantId, string $presentedKey): array
    {
        $presentedKey = trim($presentedKey);
        if ($presentedKey === '') return ['ok' => false, 'reason' => 'no_key'];
 
        $tenant = DB::table('tenants')->where('id', $tenantId)->first();
        if (!$tenant) return ['ok' => false, 'reason' => 'unknown_tenant'];
 
        $emails = DB::table('tenant_users')->where('tenant_id', $tenantId)
            ->pluck('email')->map(fn ($e) => strtolower(trim($e)))->all();
 
        try {
            $rows = $this->parse($this->download());
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'file_unreachable'];
        }
        if ($rows === null) return ['ok' => false, 'reason' => 'file_unreadable'];
 
        // File-integrity guard: a key must not appear in more than one row.
        $keyCounts = [];
        foreach ($rows as $r) {
            $k = trim($r['license_key'] ?? '');
            if ($k !== '') $keyCounts[$k] = ($keyCounts[$k] ?? 0) + 1;
        }
        if (($keyCounts[$presentedKey] ?? 0) > 1) {
            \App\Support\Health\OperatorAlert::send(
                'Duplicate licence key in master file',
                [['level' => 'critical', 'area' => 'licensing',
                  'message' => 'A tenant presented a key that appears in multiple rows. Fix the master file.']]
            );
            return ['ok' => false, 'reason' => 'key_not_unique'];
        }
 
        foreach ($rows as $row) {
            $rowEmail = strtolower(trim($row['tenant_admin_email'] ?? ''));
            $rowKey   = trim($row['license_key'] ?? '');
            if (!in_array($rowEmail, $emails, true)) continue;    // not this tenant's row
            if ($rowKey === '' || $rowKey !== $presentedKey) continue;   // key must match exactly
 
            $resolved = $this->resolve($row);
            if ($resolved === null) return ['ok' => false, 'reason' => 'row_invalid'];
            return ['ok' => true, 'expires_on' => $resolved['expires_on']];
        }
 
        return ['ok' => false, 'reason' => 'no_match'];
    }
 
    // ---- Shared parsing + row validation. ----
    private function parse(string $csv): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        if (count($lines) < 1) return null;
        $header = array_map(fn ($c) => strtolower(trim($c)), str_getcsv(array_shift($lines)));
        $need = ['tenant_admin_email', 'license_key', 'duration_days', 'date_of_purchase'];
        if (array_diff($need, $header)) return null;
 
        $out = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $out[] = array_combine($header, array_pad(str_getcsv($line), count($header), null));
        }
        return $out;
    }
 
    private function resolve(array $row): ?array
    {
        $email = strtolower(trim($row['tenant_admin_email'] ?? ''));
        $days  = (int) ($row['duration_days'] ?? 0);
        $purch = trim($row['date_of_purchase'] ?? '');
 
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
        if ($days < 1 || $days > 3650) return null;
 
        $tu = DB::table('tenant_users')->where('email', $email)->first();
        if (!$tu) return null;
 
        try {
            $start = $purch !== '' ? Carbon::parse($purch) : now();
        } catch (\Throwable $e) {
            return null;
        }
 
        return [
            'tenant_id'  => $tu->tenant_id,
            'email'      => $email,
            'expires_on' => $start->copy()->addDays($days)->toDateString(),
        ];
    }
 
    private function fail(string $error): array
    {
        // Null-safe: if the singleton row somehow still doesn't exist (should not
        // happen after run()'s self-heal above, but never assume), don't fatal —
        // count zero prior failures rather than crashing on ->consecutive_failures
        // of null. Mirrors the same defensive pattern EnforceLicences.php already
        // used correctly for this same table.
        $state = DB::table('license_fetch_state')->first();
        $failures = ($state?->consecutive_failures ?? 0) + 1;
        DB::table('license_fetch_state')->update([
            'last_error' => $error, 'consecutive_failures' => $failures,
        ]);
        \App\Support\Health\OperatorAlert::send(
            'Licence file fetch FAILED (' . $failures . ' in a row)',
            [['level' => $failures >= 7 ? 'critical' : 'warning', 'area' => 'licensing', 'message' => $error]]
        );
        // Cache left UNTOUCHED — last-known-good continues to serve.
        return ['ok' => false, 'applied' => 0, 'skipped' => 0, 'error' => $error];
    }
}