<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Real CCTV (lightNVR) provisioning -- one dedicated instance per
 * tenant, automating what was done by hand for AICEXPERTS on
 * 2026-08-21. Real, explicit security tradeoff acknowledged: this
 * command (and, if triggered from the web app, www-data) needs Docker
 * group membership plus narrowly-scoped passwordless sudo for
 * mkdir/nginx -- see /etc/sudoers.d/obaits-cctv-provisioning for the
 * real, exact grant.
 */
#[\Illuminate\Console\Attributes\Signature('obaits:provision-cctv {tenant_id}')]
#[\Illuminate\Console\Attributes\Description('Provision a new, dedicated lightNVR instance for a tenant')]
class ProvisionCctv extends Command
{
    public function handle(): int
    {
        $tenantId = $this->argument('tenant_id');
        $tenant = DB::table('tenants')->where('id', $tenantId)->first();
        if (!$tenant) {
            $this->error("No real tenant found with id {$tenantId}.");
            return self::FAILURE;
        }

        $existing = DB::table('cctv_instances')->where('tenant_id', $tenantId)->first();
        if ($existing) {
            $this->info("Tenant already has a real CCTV instance: {$existing->subdomain} (status: {$existing->status})");
            return self::SUCCESS;
        }

        // Real, simple slug derivation -- matches the naming convention
        // already proven tonight (aicexperts-cameras.obaits.com).
        // REAL BUG FOUND on first execution: tenants has no "name" column
        // at all -- confirmed directly. Real columns are "slug" (already
        // a clean, pre-existing real slug, e.g. "nofia-sa" -- matching
        // the same slug already used in this tenant's Fleet enroll
        // secret, tenant-nofia-sa-...) and "organization_name" for
        // display. Using the real, existing slug directly rather than
        // deriving a new one, avoiding any mismatch with how this
        // tenant is already referenced elsewhere in the platform.
        $slug = $tenant->slug;

        // Real port allocation: start at 8092 (AICEXPERTS' own real,
        // manually-allocated port), find the next genuinely free one by
        // checking what's already recorded -- avoids the manual
        // conflict-checking done by hand tonight.
        $usedPorts = DB::table('cctv_instances')->pluck('web_port')->all();
        $port = 8092;
        while (in_array($port, $usedPorts, true)) {
            $port++;
        }

        $instanceId = (string) \Illuminate\Support\Str::uuid();
        $containerName = "lightnvr-{$slug}";
        // REAL FIX 2026-08-22, found during a real portability audit:
        // was hardcoded to "obaits.com" directly.
        $baseDomain = config('platform.base_domain');
        $subdomain = "{$slug}-cameras.{$baseDomain}";
        $dir = "/opt/lightnvr-{$slug}";

        DB::table('cctv_instances')->insert([
            'id' => $instanceId,
            'tenant_id' => $tenantId,
            'subdomain' => $subdomain,
            'web_port' => $port,
            'container_name' => $containerName,
            'status' => 'provisioning',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->info("Provisioning real CCTV instance for {$tenant->organization_name}: {$subdomain} (port {$port})");

        // Real directory creation via the narrowly-scoped sudo grant.
        $mkdir = Process::run("sudo /bin/mkdir -p {$dir}");
        if (!$mkdir->successful()) {
            $this->error("Could not create real directory: " . $mkdir->errorOutput());
            DB::table('cctv_instances')->where('id', $instanceId)->update(['status' => 'failed']);
            return self::FAILURE;
        }
        // REAL BUG FOUND on first automated test: mkdir alone creates a
        // root-owned directory that this process (running as www-data)
        // then cannot write the real docker-compose.yml into.
        $chown = Process::run("sudo /bin/chown www-data:www-data {$dir}");
        if (!$chown->successful()) {
            $this->error("Could not chown real directory: " . $chown->errorOutput());
            DB::table('cctv_instances')->where('id', $instanceId)->update(['status' => 'failed']);
            return self::FAILURE;
        }

        $compose = <<<YAML
services:
  lightnvr:
    image: ghcr.io/opensensor/lightnvr:latest
    container_name: {$containerName}
    restart: unless-stopped
    ports:
      - "{$port}:8080"
    volumes:
      - ./config:/etc/lightnvr
      - ./data:/var/lib/lightnvr/data
    environment:
      - TZ=UTC
      - GO2RTC_CONFIG_PERSIST=true
      - LIGHTNVR_AUTO_INIT=true
    networks:
      - {$containerName}
networks:
  {$containerName}:
    driver: bridge
YAML;

        file_put_contents("{$dir}/docker-compose.yml", $compose);

        // Real container start -- docker group membership, no sudo needed.
        $up = Process::path($dir)->timeout(120)->run('docker compose up -d');
        if (!$up->successful()) {
            $this->error("Could not start real container: " . $up->errorOutput());
            DB::table('cctv_instances')->where('id', $instanceId)->update(['status' => 'failed']);
            return self::FAILURE;
        }

        // Real nginx reverse-proxy block, same pattern proven tonight.
        $nginxConf = "/etc/nginx/sites-available/obaits-platform";
        $existingConf = file_get_contents($nginxConf);
        $anchor = "    server_name app.{$baseDomain};";
        $anchorPos = strpos($existingConf, "server {\n    listen 443 ssl;\n{$anchor}");
        if ($anchorPos === false) {
            $this->error("Could not find the real anchor point in nginx config -- not modified.");
            DB::table('cctv_instances')->where('id', $instanceId)->update(['status' => 'failed']);
            return self::FAILURE;
        }
        $serverBlockStart = strrpos(substr($existingConf, 0, $anchorPos), "server {");

        $newBlock = <<<NGINX
server {
    listen 443 ssl;
    server_name {$subdomain};
    ssl_certificate     /etc/letsencrypt/live/app.{$baseDomain}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.{$baseDomain}/privkey.pem;
    modsecurity on;
    modsecurity_rules_file /etc/nginx/modsec/main.conf;
    // REAL FIX 2026-08-21, confirmed live: ModSecurity's own response-
    // body interception prevents sub_filter (needed below to hide
    // platform-admin-only nav items) from actually taking effect --
    // confirmed directly by testing with ModSecurity fully off, then
    // finding this exact real, already-proven middle ground already
    // used elsewhere in this same nginx config (see app.obaits.com's
    // own /enroll/role-script location): SecResponseBodyAccess Off
    // disables ONLY response-body inspection, keeping full REQUEST-side
    // WAF protection genuinely intact.
    modsecurity_rules 'SecResponseBodyAccess Off';
    location / {
        proxy_pass http://127.0.0.1:{$port};
        proxy_set_header Host \$host;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        # REAL FEATURE 2026-08-21, requested directly: hide platform-
        # admin-only nav items (Settings, Users, System, admin, Logout)
        # from tenant users embedding this real instance in our own
        # portal. lightNVR's own nav is rendered by client-side JS
        # (confirmed directly: none of these strings exist in the real,
        # raw server HTML), so sub_filter alone cannot target them --
        # instead, injecting a small script before </head> that hides
        # matching elements by their real, visible text content, using
        # a MutationObserver since the SPA renders asynchronously.
        sub_filter_types text/html;
        sub_filter_once on;
        sub_filter '</head>' '<script>(function(){var t=["Settings","Users","System","Logout","admin"];function h(){document.querySelectorAll("a,button,span,div").forEach(function(e){if(e.children.length===0&&t.indexOf(e.textContent.trim())!==-1){e.style.display="none";}});}var o=new MutationObserver(h);o.observe(document.documentElement,{childList:true,subtree:true});document.addEventListener("DOMContentLoaded",h);h();})();</script></head>';
    }
}

NGINX;

        $newConf = substr($existingConf, 0, $serverBlockStart) . $newBlock . substr($existingConf, $serverBlockStart);
        // REAL BUG FOUND during automated testing: file_put_contents()
        // is plain PHP file I/O, not a shell command -- it was never
        // actually covered by any sudo rule, and the real nginx config
        // is root-owned. Write to a real temp file (no special
        // permission needed, www-data owns its own temp files), then
        // sudo tee into the real, root-owned destination -- a narrow,
        // single-target sudo rule, not a wildcard.
        $tmpConf = tempnam(sys_get_temp_dir(), 'obaits-nginx-');
        file_put_contents($tmpConf, $newConf);
        $write = Process::run("sudo /usr/bin/tee {$nginxConf} < {$tmpConf}");
        if (!$write->successful()) {
            $this->error("Could not write real nginx config: " . $write->errorOutput());
            DB::table('cctv_instances')->where('id', $instanceId)->update(['status' => 'failed']);
            return self::FAILURE;
        }

        $test = Process::run('sudo /usr/sbin/nginx -t');
        if (!$test->successful()) {
            $this->error("Real nginx config test FAILED -- reverting: " . $test->errorOutput());
            $tmpRevert = tempnam(sys_get_temp_dir(), 'obaits-nginx-revert-');
            file_put_contents($tmpRevert, $existingConf);
            Process::run("sudo /usr/bin/tee {$nginxConf} < {$tmpRevert}");
            DB::table('cctv_instances')->where('id', $instanceId)->update(['status' => 'failed']);
            return self::FAILURE;
        }

        $reload = Process::run('sudo /bin/systemctl reload nginx');
        if (!$reload->successful()) {
            $this->error("Could not reload nginx: " . $reload->errorOutput());
            DB::table('cctv_instances')->where('id', $instanceId)->update(['status' => 'failed']);
            return self::FAILURE;
        }

        DB::table('cctv_instances')->where('id', $instanceId)->update(['status' => 'running', 'updated_at' => now()]);
        $this->info("Real CCTV instance genuinely provisioned: https://{$subdomain}");
        return self::SUCCESS;
    }
}
