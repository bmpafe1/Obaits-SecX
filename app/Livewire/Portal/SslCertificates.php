<?php
namespace App\Livewire\Portal;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use App\Support\Perm;

/**
 * Real, tenant-facing SSL Certificates page. Real mechanism (acme-dns
 * registration, CNAME delegation, Certbot DNS-01 issuance and
 * auto-renewal) fully proven end to end manually before this UI was
 * built -- see ops notes / this session's own real verification against
 * ssltest.obaits.com.
 */
#[Layout('components.layouts.portal')]
class SslCertificates extends Component
{
    public string $tenantId;
    public string $newDomain = '';

    public function mount()
    {
        $this->tenantId = session('tenant_id');
        if (!$this->tenantId) { redirect('/login'); return; }
    }

    public function requestCertificate()
    {
        Perm::require('manage_policies');
        $this->validate([
            'newDomain' => 'required|string|regex:/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',
        ]);

        $existing = DB::table('ssl_certificates')
            ->where('tenant_id', $this->tenantId)
            ->where('domain', $this->newDomain)
            ->first();
        if ($existing) {
            $this->addError('newDomain', __('A certificate request for this domain already exists.'));
            return;
        }

        // REAL BUG FOUND during real end-to-end testing: Laravel's
        // Http facade always attaches SOME body/content-type even with
        // no explicit data, which acme-dns's own strict JSON parser
        // rejects with "malformed_json_payload" -- confirmed directly,
        // a genuinely bodyless request (matching a bare curl -X POST)
        // succeeds, while every real Http:: variant tried did not.
        // Using a raw Guzzle client for this ONE specific call, which
        // sends nothing extra by default, matching the real, working
        // curl behavior exactly.
        try {
            $client = new \GuzzleHttp\Client();
            $guzzleRes = $client->post(config('services.acmedns.base_url') . '/register');
            if ($guzzleRes->getStatusCode() !== 201) {
                $this->addError('newDomain', __('Could not register with the certificate system. Please try again.'));
                return;
            }
            $data = json_decode((string) $guzzleRes->getBody(), true);
        } catch (\Throwable $e) {
            $this->addError('newDomain', __('Could not reach the certificate system. Please try again.'));
            return;
        }

        DB::table('ssl_certificates')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $this->tenantId,
            'domain' => $this->newDomain,
            'acme_dns_subdomain' => $data['subdomain'],
            'acme_dns_username' => $data['username'],
            'acme_dns_password' => $data['password'],
            'acme_dns_fulldomain' => $data['fulldomain'],
            'status' => 'pending_dns',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->newDomain = '';
    }

    public function verifyDns(string $id)
    {
        Perm::require('manage_policies');
        $cert = DB::table('ssl_certificates')->where('id', $id)->where('tenant_id', $this->tenantId)->first();
        if (!$cert) return;

        // Real, direct external verification -- checks whether the
        // real CNAME record genuinely resolves to our expected
        // acme-dns fulldomain, matching what Let's Encrypt's own CA
        // will check during real issuance.
        $records = @dns_get_record('_acme-challenge.' . $cert->domain, DNS_CNAME);
        $found = false;
        foreach ($records ?: [] as $r) {
            if (rtrim($r['target'] ?? '', '.') === rtrim($cert->acme_dns_fulldomain, '.')) {
                $found = true;
                break;
            }
        }

        if ($found) {
            DB::table('ssl_certificates')->where('id', $id)->update(['status' => 'verified', 'updated_at' => now()]);
        } else {
            session()->flash('ssl_error', __('The CNAME record was not found yet. DNS changes can take a few minutes to propagate -- please try again shortly.'));
        }
    }

    public function issueCertificate(string $id)
    {
        Perm::require('manage_policies');
        $cert = DB::table('ssl_certificates')->where('id', $id)->where('tenant_id', $this->tenantId)->first();
        if (!$cert || $cert->status !== 'verified') return;

        // REAL BUG FOUND during real end-to-end testing: our own
        // registration (shown to the user for their real CNAME) and
        // Certbot's own acme-dns-auth.py hook script each independently
        // register a SEPARATE, real acme-dns account for the same
        // domain -- confirmed directly, two different real
        // subdomain/fulldomain values existed, meaning the user's real
        // CNAME never matched what Certbot's hook was actually
        // checking, causing a genuine NXDOMAIN validation failure.
        // Fixed by writing OUR OWN, already-known real registration
        // into the hook's own real storage file BEFORE invoking
        // Certbot, in the EXACT JSON structure/key the hook itself
        // reads (storage.fetch(DOMAIN) -- see acme-dns-auth.py) --
        // this makes the hook recognize the domain as already
        // registered and reuse our single, consistent account.
        $existingRaw = Process::run('sudo /bin/cat /etc/letsencrypt/acmedns.json');
        $existing = $existingRaw->successful() ? (json_decode($existingRaw->output(), true) ?: []) : [];
        $existing[$cert->domain] = [
            'username' => $cert->acme_dns_username,
            'password' => $cert->acme_dns_password,
            'fulldomain' => $cert->acme_dns_fulldomain,
            'subdomain' => $cert->acme_dns_subdomain,
            'allowfrom' => [],
        ];
        $tmpFile = tempnam(sys_get_temp_dir(), 'obaits-acmedns-');
        file_put_contents($tmpFile, json_encode($existing));
        Process::run("sudo /usr/bin/tee /etc/letsencrypt/acmedns.json < {$tmpFile}");
        @unlink($tmpFile);

        $result = Process::timeout(120)->run(
            "sudo /usr/bin/certbot certonly --manual --manual-auth-hook \"python3 /etc/letsencrypt/acme-dns-auth.py\" "
            . "--preferred-challenges dns -d {$cert->domain} --register-unsafely-without-email --agree-tos --non-interactive"
        );

        if (!$result->successful()) {
            DB::table('ssl_certificates')->where('id', $id)->update(['status' => 'failed', 'updated_at' => now()]);
            session()->flash('ssl_error', __('Certificate issuance failed. Please contact support.'));
            return;
        }

        // Real, deliberate copy: NEVER loosen /etc/letsencrypt's own
        // real, correct root-only permissions. Copy into a
        // tenant-specific, www-data-readable location instead.
        $destDir = storage_path("app/ssl-certs/{$this->tenantId}/{$cert->domain}");
        Process::run("sudo /bin/mkdir -p {$destDir}");
        Process::run("sudo /bin/chown www-data:www-data {$destDir}");
        Process::run("sudo /bin/cp /etc/letsencrypt/live/{$cert->domain}/fullchain.pem {$destDir}/fullchain.pem");
        Process::run("sudo /bin/cp /etc/letsencrypt/live/{$cert->domain}/privkey.pem {$destDir}/privkey.pem");
        // REAL BUG FOUND during real end-to-end testing: chowning the
        // DIRECTORY before copying does NOT make files copied into it
        // afterward inherit that ownership -- confirmed directly, the
        // copied fullchain.pem/privkey.pem stayed root:root (privkey.pem
        // 600, root-only), meaning www-data genuinely could not read
        // either file for download. Chowning the actual files too.
        Process::run("sudo /bin/chown www-data:www-data {$destDir}/fullchain.pem");
        Process::run("sudo /bin/chown www-data:www-data {$destDir}/privkey.pem");

        // REAL FEATURE 2026-08-22, requested directly: additional real
        // formats beyond PEM. Certbot already produces cert.pem
        // (leaf-only) and chain.pem (intermediates-only) as real
        // symlinks alongside fullchain.pem -- confirmed directly, zero
        // conversion needed, just copy them too.
        Process::run("sudo /bin/cp /etc/letsencrypt/live/{$cert->domain}/cert.pem {$destDir}/cert.pem");
        Process::run("sudo /bin/cp /etc/letsencrypt/live/{$cert->domain}/chain.pem {$destDir}/chain.pem");
        Process::run("sudo /bin/chown www-data:www-data {$destDir}/cert.pem");
        Process::run("sudo /bin/chown www-data:www-data {$destDir}/chain.pem");

        // Real DER (binary) format -- some Windows/Java-based systems
        // specifically require this instead of PEM's base64 text
        // encoding. www-data can run openssl directly here since the
        // source files are already copied locally, no sudo needed.
        Process::run("openssl x509 -in {$destDir}/cert.pem -outform der -out {$destDir}/cert.der");

        // Real PKCS12/PFX bundle -- commonly required by Windows/IIS
        // and various enterprise software. Bundles the private key, so
        // it needs a real, randomly-generated password (never empty),
        // matching this project's own established security discipline.
        $pfxPassword = bin2hex(random_bytes(12));
        Process::run(
            "openssl pkcs12 -export -out {$destDir}/certificate.pfx "
            . "-inkey {$destDir}/privkey.pem -in {$destDir}/cert.pem -certfile {$destDir}/chain.pem "
            . "-passout pass:{$pfxPassword}"
        );

        DB::table('ssl_certificates')->where('id', $id)->update([
            'status' => 'issued',
            'issued_at' => now(),
            'expires_at' => now()->addDays(90),
            'updated_at' => now(),
        ]);
    }

    public function downloadFullchain(string $id)
    {
        $cert = DB::table('ssl_certificates')->where('id', $id)->where('tenant_id', $this->tenantId)->first();
        if (!$cert || $cert->status !== 'issued') return;
        $path = storage_path("app/ssl-certs/{$this->tenantId}/{$cert->domain}/fullchain.pem");
        return response()->download($path, "{$cert->domain}-fullchain.pem");
    }

    public function downloadPrivkey(string $id)
    {
        $cert = DB::table('ssl_certificates')->where('id', $id)->where('tenant_id', $this->tenantId)->first();
        if (!$cert || $cert->status !== 'issued') return;
        $path = storage_path("app/ssl-certs/{$this->tenantId}/{$cert->domain}/privkey.pem");
        return response()->download($path, "{$cert->domain}-privkey.pem");
    }

    public function downloadCertOnly(string $id)
    {
        $cert = DB::table('ssl_certificates')->where('id', $id)->where('tenant_id', $this->tenantId)->first();
        if (!$cert || $cert->status !== 'issued') return;
        $path = storage_path("app/ssl-certs/{$this->tenantId}/{$cert->domain}/cert.pem");
        return response()->download($path, "{$cert->domain}-cert.pem");
    }

    public function downloadChain(string $id)
    {
        $cert = DB::table('ssl_certificates')->where('id', $id)->where('tenant_id', $this->tenantId)->first();
        if (!$cert || $cert->status !== 'issued') return;
        $path = storage_path("app/ssl-certs/{$this->tenantId}/{$cert->domain}/chain.pem");
        return response()->download($path, "{$cert->domain}-chain.pem");
    }

    public function downloadDer(string $id)
    {
        $cert = DB::table('ssl_certificates')->where('id', $id)->where('tenant_id', $this->tenantId)->first();
        if (!$cert || $cert->status !== 'issued') return;
        $path = storage_path("app/ssl-certs/{$this->tenantId}/{$cert->domain}/cert.der");
        return response()->download($path, "{$cert->domain}-cert.der");
    }

    public function downloadPfx(string $id)
    {
        $cert = DB::table('ssl_certificates')->where('id', $id)->where('tenant_id', $this->tenantId)->first();
        if (!$cert || $cert->status !== 'issued') return;
        $path = storage_path("app/ssl-certs/{$this->tenantId}/{$cert->domain}/certificate.pfx");
        return response()->download($path, "{$cert->domain}.pfx");
    }

    public function render()
    {
        $certificates = DB::table('ssl_certificates')
            ->where('tenant_id', $this->tenantId)
            ->orderByDesc('created_at')
            ->get();

        return view('livewire.portal.ssl-certificates', [
            'certificates' => $certificates,
        ]);
    }
}
