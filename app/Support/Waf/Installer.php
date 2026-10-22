<?php
namespace App\Support\Waf;

class Installer
{
    // Pick the right script for a detected server.
    public static function scriptFor(string $deployPath, array $opts = []): string
    {
        return match ($deployPath) {
            'modsec3_nginx'  => self::nginx(),
            'modsec2_apache' => self::apache(),
            'nginx_proxy'    => self::iisReverseProxy($opts['domain'] ?? 'your-app.example.com'),
            'modsec2_iis'    => self::iisInProcess(),
            default          => '# Unknown server type',
        };
    }

    // 2.2 — nginx + ModSecurity 3
    public static function nginx(): string
    {
        return <<<'SH'
#!/usr/bin/env bash
set -e
# Obaits WAF — runs on YOUR server. Your traffic never leaves your network.
apt-get update
apt-get install -y nginx libnginx-mod-http-modsecurity
mkdir -p /etc/nginx/modsec
curl -sSL https://raw.githubusercontent.com/owasp-modsecurity/ModSecurity/v3/master/modsecurity.conf-recommended -o /etc/nginx/modsec/modsecurity.conf
curl -sSL https://raw.githubusercontent.com/owasp-modsecurity/ModSecurity/v3/master/unicode.mapping -o /etc/nginx/modsec/unicode.mapping
cd /etc/nginx
curl -sSL https://github.com/coreruleset/coreruleset/archive/refs/tags/v4.16.0.tar.gz | tar xz
mv coreruleset-4.16.0 owasp-crs
cp owasp-crs/crs-setup.conf.example owasp-crs/crs-setup.conf
cat > /etc/nginx/modsec/main.conf <<'EOF'
Include /etc/nginx/modsec/modsecurity.conf
Include /etc/nginx/owasp-crs/crs-setup.conf
Include /etc/nginx/owasp-crs/rules/*.conf
Include /etc/nginx/modsec/obaits-virtual-patches.conf
EOF
touch /etc/nginx/modsec/obaits-virtual-patches.conf
sed -i 's/^SecRuleEngine .*/SecRuleEngine DetectionOnly/' /etc/nginx/modsec/modsecurity.conf
sed -i 's|^SecAuditLog .*|SecAuditLog /var/log/modsec_audit.log|' /etc/nginx/modsec/modsecurity.conf
echo 'SecAuditLogFormat JSON' >> /etc/nginx/modsec/modsecurity.conf
echo "Add to your server block:  modsecurity on;  modsecurity_rules_file /etc/nginx/modsec/main.conf;"
nginx -t && systemctl reload nginx
echo "WAF installed in DETECTION mode. Nothing is being blocked yet."
SH;
    }

    // 2.3 — Apache + ModSecurity 2.9
    public static function apache(): string
    {
        return <<<'SH'
#!/usr/bin/env bash
set -e
apt-get update
apt-get install -y libapache2-mod-security2
a2enmod security2
cd /etc/modsecurity
curl -sSL https://github.com/coreruleset/coreruleset/archive/refs/tags/v4.16.0.tar.gz | tar xz
mv coreruleset-4.16.0 owasp-crs
cp owasp-crs/crs-setup.conf.example owasp-crs/crs-setup.conf
cp modsecurity.conf-recommended modsecurity.conf
sed -i 's/^SecRuleEngine .*/SecRuleEngine DetectionOnly/' modsecurity.conf
echo 'SecAuditLogFormat JSON' >> modsecurity.conf
touch /etc/modsecurity/obaits-virtual-patches.conf
cat >> /etc/apache2/mods-enabled/security2.conf <<'EOF'
IncludeOptional /etc/modsecurity/owasp-crs/crs-setup.conf
IncludeOptional /etc/modsecurity/owasp-crs/rules/*.conf
IncludeOptional /etc/modsecurity/obaits-virtual-patches.conf
EOF
apachectl configtest && systemctl reload apache2
echo "WAF installed in DETECTION mode. Nothing is being blocked yet."
SH;
    }

    // 2.4 — IIS via nginx reverse proxy (on the tenant's own machine)
    public static function iisReverseProxy(string $domain): string
    {
        return <<<SH
#!/usr/bin/env bash
set -e
# Run the nginx installer first (see the nginx script), then create this site.
# IIS must be rebound to listen on 127.0.0.1:8080 instead of the public port.
cat > /etc/nginx/sites-available/obaits-waf.conf <<'EOF'
server {
    listen 443 ssl;
    server_name {$domain};
    ssl_certificate     /etc/ssl/certs/your-app.crt;
    ssl_certificate_key /etc/ssl/private/your-app.key;
    modsecurity on;
    modsecurity_rules_file /etc/nginx/modsec/main.conf;
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
EOF
ln -sf /etc/nginx/sites-available/obaits-waf.conf /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
echo "Now rebind your IIS site to 127.0.0.1:8080 in IIS Manager."
SH;
    }

    // 2.5 — IIS in-process (ModSecurity 2.9 IIS module) — second-best
    public static function iisInProcess(): string
    {
        return "# ModSecurity 2.9 IIS module (PowerShell, run as Administrator)\n"
             . "# 1. Download the ModSecurity IIS installer (MSI) from the OWASP ModSecurity releases.\n"
             . "# 2. Run the MSI — it registers ModSecurity as a native IIS module.\n"
             . "# 3. Download OWASP CRS v4.16 and extract to C:\\inetpub\\modsec\\owasp-crs\\\n"
             . "# 4. Point the module config at the CRS, set SecRuleEngine DetectionOnly.\n"
             . "# This path is offered because it needs no proxy hop, but the nginx-proxy\n"
             . "# path above is on the actively-developed ModSecurity 3 line and is preferred.\n";
    }
}