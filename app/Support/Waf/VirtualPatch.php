<?php
namespace App\Support\Waf;
 
use Illuminate\Support\Facades\DB;
 
class VirtualPatch
{
    /**
     * Turn ONE web finding into a ModSecurity rule that blocks THAT attack.
     *
     * Rules are DETERMINISTIC TEMPLATES, never model output. Brian explains them;
     * he does not author them. A hallucinated SecRule blocks real users or protects
     * nothing, and the tenant cannot tell which.
     */
    public static function forFinding(string $tenantId, string $findingId): ?array
    {
        $f = DB::table('findings')->where('id', $findingId)
            ->where('tenant_id', $tenantId)->first();       // ISOLATION
        if (!$f || $f->scan_type !== 'web') return null;
 
        $cwe = (int) preg_replace('/\D/', '', (string) $f->cwe);   // "CWE-89" or "89" -> 89
        $path = self::pathFrom($f->url);
        $param = $f->param ?: null;
 
        $ruleId = 90000 + (crc32($findingId) % 9000);
 
        $rule = match ($cwe) {
            89 => self::sqli($ruleId, $path, $param),        // SQL injection
            79 => self::xss($ruleId, $path, $param),         // Cross-site scripting
            22 => self::traversal($ruleId, $path, $param),   // Path traversal
            98 => self::rfi($ruleId, $path, $param),         // Remote/local file inclusion
            default => null,
        };
 
        if (!$rule) {
            return [
                'supported' => false,
                'finding' => $f->name,
                'note' => 'A web firewall can block injection-style attacks (things typed into a form or URL). '
                        . 'This finding is a different kind of problem and has to be fixed in the application '
                        . 'itself. I can explain what to change if that helps.',
            ];
        }
 
        return [
            'supported' => true,
            'finding' => $f->name,
            'rule_id' => $ruleId,
            'path' => $path,
            'param' => $param,
            'rule' => $rule,
            'caveat' => 'This rule blocks the attack. It does NOT fix the vulnerability — the flaw is still '
                      . 'in your application code. Keep the rule in place, and get the code fixed properly when '
                      . 'you can. A firewall rule buys time; it is not a repair.',
            'install' => self::installNote($tenantId),
        ];
    }
 
    // The path ZAP attacked, e.g. "/vulnerabilities/sqli/". Falls back to "/" only if
    // no URL was captured (older findings) — broader, but never guesses a wrong path.
    private static function pathFrom(?string $url): string
    {
        if (!$url) return '/';
        $p = parse_url($url, PHP_URL_PATH);
        return ($p && $p !== '') ? $p : '/';
    }
 
    // ---- Deterministic templates. Each scopes to the path, and to the parameter
    // when we know it — the narrowest rule that stops the attack. ----
 
    private static function target(?string $param): string
    {
        // ARGS:id inspects only the "id" parameter; ARGS inspects all of them.
        return $param ? "ARGS:" . preg_replace('/[^A-Za-z0-9_\-\[\]]/', '', $param) : 'ARGS';
    }
 
    private static function sqli(int $id, string $path, ?string $param): string
    {
        $t = self::target($param);
        return <<<RULE
# Obaits virtual patch {$id} — SQL injection at {$path}
# BLOCKS the attack. Does NOT fix the code.
SecRule REQUEST_URI "@beginsWith {$path}" \\
    "id:{$id},phase:2,deny,status:403,log,\\
     msg:'Obaits virtual patch: SQL injection blocked',\\
     tag:'obaits-virtual-patch',chain"
    SecRule {$t} "@detectSQLi" "t:none,t:urlDecodeUni,t:removeNulls"
RULE;
    }
 
    private static function xss(int $id, string $path, ?string $param): string
    {
        $t = self::target($param);
        return <<<RULE
# Obaits virtual patch {$id} — Cross-site scripting at {$path}
# BLOCKS the attack. Does NOT fix the code.
SecRule REQUEST_URI "@beginsWith {$path}" \\
    "id:{$id},phase:2,deny,status:403,log,\\
     msg:'Obaits virtual patch: XSS blocked',\\
     tag:'obaits-virtual-patch',chain"
    SecRule {$t} "@detectXSS" "t:none,t:urlDecodeUni,t:htmlEntityDecode"
RULE;
    }
 
    private static function traversal(int $id, string $path, ?string $param): string
    {
        $t = self::target($param);
        return <<<RULE
# Obaits virtual patch {$id} — Path traversal at {$path}
# BLOCKS the attack. Does NOT fix the code.
SecRule REQUEST_URI "@beginsWith {$path}" \\
    "id:{$id},phase:2,deny,status:403,log,\\
     msg:'Obaits virtual patch: path traversal blocked',\\
     tag:'obaits-virtual-patch',chain"
    SecRule {$t} "@rx (?:\\.\\.[\\\\/]|%2e%2e|%252e)" "t:none,t:urlDecodeUni,t:lowercase"
RULE;
    }
 
    private static function rfi(int $id, string $path, ?string $param): string
    {
        $t = self::target($param);
        return <<<RULE
# Obaits virtual patch {$id} — File inclusion at {$path}
# BLOCKS the attack. Does NOT fix the code.
SecRule REQUEST_URI "@beginsWith {$path}" \\
    "id:{$id},phase:2,deny,status:403,log,\\
     msg:'Obaits virtual patch: file inclusion blocked',\\
     tag:'obaits-virtual-patch',chain"
    SecRule {$t} "@rx (?:php://|file://|expect://|/etc/passwd|/proc/self)" "t:none,t:urlDecodeUni,t:lowercase"
RULE;
    }
 
    private static function installNote(string $tenantId): string
    {
        $d = DB::table('waf_deployments')->where('tenant_id', $tenantId)
            ->whereIn('mode', ['detection', 'blocking'])->first();
 
        if (!$d) {
            return 'You do not have a web firewall installed yet. Add your web server on the Web firewall '
                 . 'page first — this rule needs somewhere to run.';
        }
 
        $file = $d->server_type === 'apache'
            ? '/etc/modsecurity/obaits-virtual-patches.conf'
            : '/etc/nginx/modsec/obaits-virtual-patches.conf';
        $reload = $d->server_type === 'apache' ? 'systemctl reload apache2' : 'nginx -t && systemctl reload nginx';
 
        $note = "Add this rule to {$file} on {$d->hostname}, then run: {$reload}";
 
        if ($d->mode === 'detection') {
            $note .= "\n\nNOTE: your firewall is in watching mode, so this rule will RECORD the attack but "
                   . "not stop it. Switch that server to blocking once you are confident the rules are not "
                   . "flagging your own staff.";
        }
        return $note;
    }
}
