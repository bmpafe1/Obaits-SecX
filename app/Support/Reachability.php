<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class Reachability
{
    // True if the IP is a private (RFC1918) / loopback / link-local address —
    // i.e. NOT reachable from the platform over the public internet.
    public static function isPrivate(?string $ip): bool
    {
        if (!$ip) return true;   // unknown -> treat as unreachable
        // filter_var returns false when the IP is in a private or reserved range.
        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * True if the platform can currently scan this IP, FOR THIS TENANT.
     *
     * $tenantId is REQUIRED (Phase 4, was previously optional/absent):
     * every Headscale instance is a separate, independent tailnet, so IP
     * addresses are NOT globally unique across tenants — 100.64.0.1 can be
     * a genuinely different real device under two different tenants.
     * "Is this IP in the VPN range" is not a sufficient check on its own;
     * it must additionally be a currently-online node on THIS tenant's own
     * instance, per headscale_node_cache (synced by
     * SyncHeadscaleNodeCache, never queried live from a web request — see
     * that command's docblock for why).
     */
    public static function isScannable(?string $ip, string $tenantId): bool
    {
        if (!$ip) return false;
        if (in_array($ip, ['127.0.0.1', 'localhost'])) return true;   // dev exception

        if (self::isInVpnRange($ip)) {
            return DB::table('headscale_node_cache')
                ->where('tenant_id', $tenantId)
                ->where('ip_v4', $ip)
                ->where('online', true)
                ->exists();
        }

        return !self::isPrivate($ip);
    }

    // Placeholder for the VPN CIDR (Headscale/Tailscale default is the 100.64.0.0/10 CGNAT range).
    public static function isInVpnRange(?string $ip): bool
    {
        if (!$ip) return false;
        // 100.64.0.0/10 covers 100.64.0.0 - 100.127.255.255
        $long = ip2long($ip);
        if ($long === false) return false;
        return $long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255');
    }

    /**
     * The actual, scannable VPN address for a Fleet-enrolled device,
     * resolved by hostname — NOT Fleet's own primary_ip, which is always
     * the device's real LAN address and NEVER its Tailscale address
     * (confirmed empirically 2026-07-26: Fleet reported 10.100.119.x for
     * devices that were simultaneously, verifiably online at 100.64.x.x).
     * Returns null if the device has no cached VPN membership (never
     * enrolled onto the tenant's VPN, or not yet synced).
     */
    public static function vpnIpForHost(string $tenantId, string $fleetHostname): ?string
    {
        // REAL BUG FOUND on a real device: Fleet reports a DOMAIN-JOINED
        // Windows machine's hostname as its full FQDN (e.g.
        // "WIN-2UQ47DD9KL4.AICEXPERTS.LOCAL"), while Headscale/Tailscale
        // registers the same device under its plain, short hostname
        // (e.g. "WIN-2UQ47DD9KL4") -- an exact string match between the
        // two silently found nothing, showing "not connected" even
        // though the device was genuinely, verifiably online via
        // Tailscale the whole time. Confirmed directly: this is the
        // first domain-joined device this project has ever tested,
        // which is exactly why this exact mismatch never surfaced
        // before. Real fix: match on the short (pre-first-dot) form,
        // which both systems genuinely agree on, rather than requiring
        // an exact full-string match.
        $shortHostname = explode('.', $fleetHostname)[0];

        return DB::table('headscale_node_cache')
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(hostname) = LOWER(?)', [$shortHostname])
            ->value('ip_v4');
    }
}
