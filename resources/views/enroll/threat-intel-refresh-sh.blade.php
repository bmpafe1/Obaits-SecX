#!/bin/bash
# Real baseline platform protection -- Linux (ipset+iptables for IPs,
# /etc/hosts for domains). Runs on EVERY enrolled device, independent of
# any tenant-configured Group Policy (IP Block, Website Block remain
# separate, unaffected mechanisms a tenant can additionally configure).
#
# FOUR real sources, each verified directly before use:
#   - Spamhaus DROP (IPs): free, anonymous, bulk flat file, ~1700 entries
#   - URLhaus hostfile (domains): free, anonymous, bulk hosts-file format
#   - hagezi Threat Intelligence Feeds (domains): free, anonymous, bulk
#     hosts-file format -- their SECURITY-specific list, deliberately NOT
#     their general ad/tracker-blocking lists (different purpose,
#     real false-positive/business-disruption risk if conflated)
#   - ThreatFox (abuse.ch): free, anonymous, CSV -- MIXED indicator types
#     (domain, ip:port, url). Deliberately restricted to domain and
#     ip:port rows ONLY -- url-type rows are skipped, since blocking a
#     full URL at IP/DNS level would mean blocking the entire underlying
#     domain, and ThreatFox's own url entries are frequently hosted on
#     large, mostly-legitimate shared platforms (same real over-blocking
#     risk that separately ruled out OpenPhish's feed, which is
#     URL-only). Also filtered to confidence_level >= 50, since
#     ThreatFox includes some anonymous, unverified submissions.
#
# AlienVault OTX, OpenPhish, and PhishTank deliberately excluded:
#   - OTX requires a real user account + API key + curated pulse
#     subscription -- genuinely different provisioning work.
#   - OpenPhish is URL-only, same real over-blocking risk as ThreatFox's
#     url-type rows, at the scale of its ENTIRE feed rather than a
#     filterable subset -- not used at all for this reason.
#   - PhishTank's anonymous access is real but rate-limited (confirmed
#     live: 75 requests per 3-day window) -- incompatible with an
#     hourly refresh schedule without a real registered API key.
# All three logged as separate, scoped future work.
set -o pipefail

CACHE_DIR="/var/lib/obaits"
sudo mkdir -p "$CACHE_DIR"

# REAL FEATURE 2026-08-16, requested directly: report real, current
# per-source counts centrally after each refresh, so the portal's
# Threat Intelligence Sources tab shows genuine live data.
#
# REAL CHANGE 2026-08-23: this file was previously served as a STATIC
# file with the portal URL hardcoded. It is now a Blade view, so the URL
# is injected per request -- required for the eventual on-prem/.iso build,
# where "app.obaits.com" is simply wrong. Found during the 2026-08-22
# portability audit, fixed here. NOTE: the Windows equivalent
# (ops/platform/threat-intel-refresh.ps1) deliberately remains a static
# file and receives its URL as an argument instead -- it contains
# PowerShell @{...} hashtable literals that Blade would try to parse as
# its own directives.
PORTAL_URL="{{ $portalUrl }}"
HOSTNAME_ACTUAL="$(hostname)"

report_source_count() {
  local source="$1" count="$2"
  curl -sS -X POST "$PORTAL_URL/enroll/threat-intel-source-counts" \
    -d "source=$source" -d "count=$count" -d "hostname=$HOSTNAME_ACTUAL" > /dev/null 2>&1
}

# --- IP blocking (Spamhaus DROP + ThreatFox ip:port rows, combined) --------
SET_NAME="obaits-threat-intel"
TMP_SET="obaits-threat-intel-tmp-$$"
IP_FEED_URL="https://www.spamhaus.org/drop/drop.txt"
THREATFOX_URL="https://threatfox.abuse.ch/export/csv/recent/"

if ! command -v ipset >/dev/null 2>&1; then
  echo "  installing ipset (required for threat-intel IP blocking)"
  sudo apt-get update -y >/dev/null 2>&1
  sudo apt-get install -y ipset >/dev/null 2>&1
fi

# Real ThreatFox fetch happens BEFORE the Spamhaus block below, so both
# sources' IPs can be merged into the SAME temp set and swapped in
# atomically together -- one combined update, not two separate,
# potentially racy ones.
THREATFOX_IPS=""
THREATFOX_DOMAINS=""
THREATFOX_IP_COUNT=0
THREATFOX_DOMAIN_COUNT=0
TF_RAW=$(curl -sS --max-time 30 "$THREATFOX_URL")
if [ $? -eq 0 ] && [ -n "$TF_RAW" ]; then
  # Real CSV format, confirmed directly against the live feed:
  # "first_seen_utc","ioc_id","ioc_value","ioc_type","threat_type",
  # "fk_malware","malware_alias","malware_printable","last_seen_utc",
  # "confidence_level","is_compromised","reference","tags","anonymous",
  # "reporter" -- ioc_value=field 3, ioc_type=field 4,
  # confidence_level=field 10. "#"-prefixed header/comment lines to skip.
  TF_ROWS=$(echo "$TF_RAW" | grep -vE '^#' | awk -F'", *"' '{
    gsub(/^"/, "", $1); gsub(/"$/, "", $15);
    print $3 "|" $4 "|" $10
  }')
  THREATFOX_IPS=$(echo "$TF_ROWS" | awk -F'|' '$2=="ip:port" && $3+0>=50 {split($1,a,":"); print a[1]}' | grep -E '^[0-9]{1,3}(\.[0-9]{1,3}){3}$')
  THREATFOX_DOMAINS=$(echo "$TF_ROWS" | awk -F'|' '$2=="domain" && $3+0>=50 {print $1}' | grep -E '^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$')
  THREATFOX_IP_COUNT=$(echo "$THREATFOX_IPS" | grep -c .)
  THREATFOX_DOMAIN_COUNT=$(echo "$THREATFOX_DOMAINS" | grep -c .)
  TF_TOTAL=$((THREATFOX_IP_COUNT + THREATFOX_DOMAIN_COUNT))
  if [ "$TF_TOTAL" -gt 0 ]; then
    report_source_count "threatfox" "$TF_TOTAL"
    echo "  ThreatFox: ${THREATFOX_IP_COUNT} IP(s), ${THREATFOX_DOMAIN_COUNT} domain(s) (confidence >= 50)"
  fi
else
  echo "  WARNING: could not fetch ThreatFox -- continuing with other sources only."
fi

if command -v ipset >/dev/null 2>&1; then
  RAW_FEED=$(curl -sS --max-time 30 "$IP_FEED_URL")
  if [ $? -eq 0 ] && [ -n "$RAW_FEED" ]; then
    CIDRS=$(echo "$RAW_FEED" | grep -vE '^;' | awk '{print $1}' | grep -E '^[0-9]{1,3}(\.[0-9]{1,3}){3}(/[0-9]{1,2})?$')
    CIDR_COUNT=$(echo "$CIDRS" | grep -c .)
    if [ "$CIDR_COUNT" -ge 100 ]; then
      # REAL PERFORMANCE BUG FOUND 2026-08-17, confirmed live on a real
      # device: individual "sudo ipset add" calls in a for loop each
      # spawn a fresh sudo+ipset process. This was fine at Spamhaus
      # DROP's own ~1700 entries alone, but once ThreatFox's ~2700
      # additional IPs were merged in via the same per-item loop, one
      # real refresh cycle took OVER 38 MINUTES and was still not done
      # -- confirmed directly by watching the same execution's own
      # elapsed time and partial entry count. This caused the NEXT
      # hourly refresh to start before the previous one finished,
      # creating two genuinely concurrent executions racing to build and
      # swap in the SAME real ipset -- a real, live production bug this
      # session caught by chance while debugging an unrelated stuck
      # Website Block test. Fixed with a single bulk "ipset restore"
      # call (one process invocation, entries fed via stdin) instead of
      # one process spawn per entry -- the standard, correct way to
      # populate a large set efficiently.
      sudo ipset create "$TMP_SET" hash:net -exist
      {
        for cidr in $CIDRS; do
          echo "add $TMP_SET $cidr -exist"
        done
        for ip in $THREATFOX_IPS; do
          echo "add $TMP_SET $ip -exist"
        done
      } | sudo ipset restore -exist
      if sudo ipset list "$SET_NAME" >/dev/null 2>&1; then
        sudo ipset swap "$SET_NAME" "$TMP_SET"
        sudo ipset destroy "$TMP_SET"
      else
        sudo ipset rename "$TMP_SET" "$SET_NAME"
      fi
      if ! sudo iptables -C INPUT -m set --match-set "$SET_NAME" src -j DROP 2>/dev/null; then
        sudo iptables -I INPUT -m set --match-set "$SET_NAME" src -j DROP
      fi
      if ! sudo iptables -C OUTPUT -m set --match-set "$SET_NAME" dst -j DROP 2>/dev/null; then
        sudo iptables -I OUTPUT -m set --match-set "$SET_NAME" dst -j DROP
      fi
      report_source_count "spamhaus_drop" "$CIDR_COUNT"
      echo "  IP block: ${CIDR_COUNT} range(s) from Spamhaus DROP + ${THREATFOX_IP_COUNT} from ThreatFox"
    else
      echo "  WARNING: Spamhaus DROP feed implausibly small (${CIDR_COUNT}) -- leaving existing IP block list untouched."
    fi
  else
    echo "  WARNING: could not fetch Spamhaus DROP -- leaving existing IP block list untouched."
  fi
else
  echo "  WARNING: ipset unavailable -- skipping IP blocking this cycle."
fi

# --- Domain blocking (URLhaus + hagezi TIF + ThreatFox domains, merged) ----
# Real per-source caching: each source's own last-good fetch is cached
# separately, only overwritten when THAT source's fetch succeeds and
# looks plausible. /etc/hosts is rebuilt from whatever's in the cache
# files -- so one source's outage never empties or corrupts the others'
# real, current contribution.
# REAL BUGS FOUND 2026-08-16, confirmed live against the real feeds:
#   1. URLhaus's hostfile.txt uses CRLF line endings (confirmed via
#      `cat -A`: every line ends "^M$", not just "$") -- the trailing \r
#      silently defeated the domain-validation regex's end anchor,
#      making every real entry fail validation despite looking correct
#      when printed. Fixed with `tr -d '\r'`.
#   2. hagezi's real repo structure has NO hosts/ or domains/ directory
#      at all (confirmed via GitHub's own API) -- the real path is
#      dnsmasq/tif.mini.txt, and its real format is dnsmasq syntax
#      (local=/domain/), not a simple hosts-file "<ip> <domain>" pair --
#      genuinely different parsing from URLhaus, not a shared function.
#      Deliberately using the MINI variant: the full tif.txt has over
#      2 MILLION entries, confirmed live -- far too large for /etc/hosts
#      (real per-lookup performance cost, real file-generation cost on
#      every hourly refresh). tif.mini.txt has ~170,000 -- still large,
#      but a real, accepted tradeoff rather than an unusable one.
URLHAUS_URL="https://urlhaus.abuse.ch/downloads/hostfile.txt"
HAGEZI_URL="https://raw.githubusercontent.com/hagezi/dns-blocklists/main/dnsmasq/tif.mini.txt"
URLHAUS_CACHE="$CACHE_DIR/urlhaus-domains.cache"
HAGEZI_CACHE="$CACHE_DIR/hagezi-tif-domains.cache"
THREATFOX_CACHE="$CACHE_DIR/threatfox-domains.cache"

refresh_urlhaus() {
  local raw
  raw=$(curl -sS --max-time 30 "$URLHAUS_URL")
  if [ $? -ne 0 ] || [ -z "$raw" ]; then
    echo "  WARNING: could not fetch URLhaus -- using last-known-good cache."
    return
  fi
  local domains
  domains=$(echo "$raw" | tr -d '\r' | grep -vE '^#' | awk '{print $2}' | grep -E '^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$')
  local count
  count=$(echo "$domains" | grep -c .)
  if [ "$count" -lt 50 ]; then
    echo "  WARNING: URLhaus feed implausibly small (${count}) -- using last-known-good cache instead."
    return
  fi
  echo "$domains" | sudo tee "$URLHAUS_CACHE" > /dev/null
  report_source_count "urlhaus" "$count"
  echo "  URLhaus: ${count} domain(s) refreshed"
}

refresh_hagezi() {
  local raw
  raw=$(curl -sS --max-time 30 "$HAGEZI_URL")
  if [ $? -ne 0 ] || [ -z "$raw" ]; then
    echo "  WARNING: could not fetch hagezi TIF -- using last-known-good cache."
    return
  fi
  # Real dnsmasq syntax: "local=/domain.tld/" per real entry, "#"-
  # prefixed header/comment lines to skip -- strip the local=/ prefix
  # and trailing / to get just the domain.
  local domains
  domains=$(echo "$raw" | tr -d '\r' | grep -vE '^#' | sed -n 's|^local=/\(.*\)/$|\1|p' | grep -E '^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$')
  local count
  count=$(echo "$domains" | grep -c .)
  if [ "$count" -lt 1000 ]; then
    echo "  WARNING: hagezi TIF feed implausibly small (${count}) -- using last-known-good cache instead."
    return
  fi
  echo "$domains" | sudo tee "$HAGEZI_CACHE" > /dev/null
  report_source_count "hagezi_tif" "$count"
  echo "  hagezi TIF: ${count} domain(s) refreshed"
}

refresh_urlhaus
refresh_hagezi

# ThreatFox's domains were already fetched/filtered above (alongside its
# IPs), so this just caches them the same way the other two sources do --
# no second network fetch needed.
if [ "$THREATFOX_DOMAIN_COUNT" -gt 0 ]; then
  echo "$THREATFOX_DOMAINS" | sudo tee "$THREATFOX_CACHE" > /dev/null
fi

HOSTS_FILE="/etc/hosts"
BEGIN_MARKER="# OBAITS-THREAT-INTEL-BEGIN -- managed automatically, do not edit inside this block"
END_MARKER="# OBAITS-THREAT-INTEL-END"

if [ -f "$URLHAUS_CACHE" ] || [ -f "$HAGEZI_CACHE" ] || [ -f "$THREATFOX_CACHE" ]; then
  # Real, safe rebuild: strip any PRIOR marked block entirely (our own
  # old content only -- never anything outside the markers), then
  # append one fresh block built from the current cache files.
  sudo sed -i "/^${BEGIN_MARKER}$/,/^${END_MARKER}$/d" "$HOSTS_FILE"

  {
    echo "$BEGIN_MARKER"
    cat "$URLHAUS_CACHE" "$HAGEZI_CACHE" "$THREATFOX_CACHE" 2>/dev/null | sort -u | while read -r domain; do
      [ -n "$domain" ] && echo "0.0.0.0 $domain"
    done
    echo "$END_MARKER"
  } | sudo tee -a "$HOSTS_FILE" > /dev/null

  TOTAL_DOMAINS=$(cat "$URLHAUS_CACHE" "$HAGEZI_CACHE" "$THREATFOX_CACHE" 2>/dev/null | sort -u | grep -c .)
  echo "  Domain block: ${TOTAL_DOMAINS} unique domain(s) now blocked via /etc/hosts (URLhaus + hagezi TIF + ThreatFox combined)"
else
  echo "  WARNING: no domain block data available from any source (first run, all fetches failed) -- /etc/hosts not touched."
fi

echo "threat-intel refresh complete"

# REAL FEATURE 2026-08-23: report this device's own refresh health back to
# the portal. Needed because this refresh now runs on a DEVICE-SIDE timer
# rather than being pushed per-host through Fleet, so Fleet's own activity
# log no longer records that it happened. Authenticated with the tenant's
# own enrollment secret (see /enroll/threat-intel-health's own comment for
# the full, explicit trust decision).
#
# SUCCESS means this script reached the end and applied a ruleset. A single
# dead upstream feed is NOT a failure -- blocking 170k domains from three of
# four sources is a working device. The one real failure case is every
# source failing, leaving the device genuinely unprotected.
report_health() {
  local status="$1" err="$2"
  # Uses the per-device credential issued at enrollment, NOT the tenant-wide
  # enrollment secret: the portal resolves tenant AND hostname from it, so
  # this script sends neither, and a leaked credential exposes one device.
  local cred
  cred="$(sudo cat /etc/obaits/device-credential 2>/dev/null)"
  [ -n "$cred" ] || return 0
  curl -sS -X POST "$PORTAL_URL/enroll/threat-intel-health" \
    -d "device_credential=$cred" \
    -d "platform=linux" \
    -d "status=$status" \
    -d "error=$err" > /dev/null 2>&1
}

if [ "${TOTAL_DOMAINS:-0}" -eq 0 ] && [ "${CIDR_COUNT:-0}" -eq 0 ] && [ "${THREATFOX_IP_COUNT:-0}" -eq 0 ]; then
  report_health failure "no data from any source -- device is not protected"
else
  report_health success ""
fi
