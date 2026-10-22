#!/usr/bin/env bash
set -euo pipefail

# Obaits device enrollment. Installs the Fleet (osquery) and Wazuh agents and
# attributes this machine to your organisation. Read it before you run it.

# --- 0. Preconditions -------------------------------------------------------
# ":?" catches an UNSET variable but not one SET TO EMPTY. An empty tenant id
# would create a marker file named "tenant-", the machine would enrol, and it
# would belong to nobody — precisely what the marker exists to prevent.
for v in OBAITS_PORTAL_URL OBAITS_ENROLL_SECRET OBAITS_WAZUH_MANAGER \
         OBAITS_WAZUH_GROUP OBAITS_TENANT_ID OBAITS_WAZUH_VERSION OBAITS_WAZUH_ENROLL_PASSWORD \
         OBAITS_ZABBIX_SERVER OBAITS_ZABBIX_SECRET; do
  if [ -z "${!v:-}" ]; then
    echo "ERROR: $v is not set." >&2
    echo "Copy the whole command from your Obaits portal, not just part of it." >&2
    exit 1
  fi
done

# Everything below writes to /etc and installs packages. Fail here with a clear
# message rather than half way through with a permission error.
if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: this must run as root. Use the sudo form of the command from your portal." >&2
  exit 1
fi

# REAL, SERIOUS CONCERN RAISED by Brian: Wazuh and Zabbix's own Linux
# installs previously added a THIRD-PARTY APT REPO live at enrollment time
# -- a temporary outage or upstream change could break a real enrollment.
# Real fix: fetch the real, specific .deb package directly from Obaits'
# own self-hosted mirror (kept current by a real, proactive daily
# scheduled command), falling back to the real, original external source
# only if our own mirror genuinely fails.
get_obaits_package() {
  local tool="$1" out_file="$2" fallback_url="$3" wazuh_version="${4:-}" os_id="${5:-}" os_version="${6:-}"
  local mirror_url="${OBAITS_PORTAL_URL}/enroll/mirror/${tool}"
  [ -n "$wazuh_version" ] && mirror_url="${mirror_url}?version=${wazuh_version}"
  # REAL FIX 2026-08-11: os_id/os_version are new, optional params --
  # only ever appended when the CALLER actually supplies them (so far,
  # only the Zabbix Linux call site does). Every other existing call
  # (Wazuh, restic, Tailscale) is completely unaffected, since these
  # arguments are simply empty for them.
  if [ -n "$os_id" ] && [ -n "$os_version" ]; then
    if [ -n "$wazuh_version" ]; then
      mirror_url="${mirror_url}&os_id=${os_id}&os_version=${os_version}"
    else
      mirror_url="${mirror_url}?os_id=${os_id}&os_version=${os_version}"
    fi
  fi
  if curl -fsSL -H "X-Obaits-Enroll-Secret: ${OBAITS_ENROLL_SECRET}" "$mirror_url" -o "$out_file" 2>/dev/null; then
    echo "    (served from the Obaits mirror)"
    return 0
  fi
  if [ -z "$fallback_url" ]; then
    echo "    WARNING: the Obaits mirror failed for ${tool}, and no simple, real, direct fallback URL exists for this tool (its own real version changes too often to guess reliably)." >&2
    return 1
  fi
  echo "    (Obaits mirror unavailable, falling back to ${fallback_url})"
  if curl -fsSL "$fallback_url" -o "$out_file" 2>/dev/null; then
    return 0
  fi
  echo "    WARNING: both the Obaits mirror and the original external source failed for ${tool}." >&2
  return 1
}

echo "==> Obaits: enrolling this computer"

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

case "$(uname -s)" in
  Linux)  PKG_TYPE="deb"; [ -f /etc/redhat-release ] && PKG_TYPE="rpm" ;;
  Darwin) PKG_TYPE="pkg" ;;
  *) echo "Unsupported operating system"; exit 1 ;;
esac

# REAL FIX 2026-08-11: needed so the Zabbix agent mirror can serve a
# package genuinely built for THIS machine's real distro/version --
# confirmed live, a hardcoded ubuntu24.04 assumption broke Zabbix
# outright on a real Ubuntu 22.04.5 machine (real libc6/libssl3t64
# dependency mismatch). Read once, here, from the same real
# /etc/os-release every modern Debian-family distro provides -- honest
# empty strings on anything else (RPM/macOS), which get_obaits_package
# simply omits from the mirror URL rather than guessing.
OS_ID=""; OS_VERSION=""
if [ -f /etc/os-release ]; then
  . /etc/os-release
  OS_ID="${ID:-}"
  OS_VERSION="${VERSION_ID:-}"
fi

# --- 0.5 Detect pre-existing agents -----------------------------------------
# Package upgrades PRESERVE existing configuration and identity:
#   - dpkg keeps /var/ossec/etc/ossec.conf, so WAZUH_MANAGER at install time is
#     IGNORED on an upgrade — the agent keeps talking to its old manager.
#   - fleetd keeps its node key in /opt/orbit; presented to a different Fleet
#     server it 401-loops and does NOT self-heal.
# Both were observed on 2026-07-21. Record what exists now; enforce after install.
PREV_WAZUH_ADDR=""
if [ -f /var/ossec/etc/ossec.conf ]; then
  PREV_WAZUH_ADDR="$(sed -n 's|.*<address>\(.*\)</address>.*|\1|p' /var/ossec/etc/ossec.conf | head -1 || true)"
  echo "  - existing Wazuh agent found (manager: ${PREV_WAZUH_ADDR:-unknown})"
fi

PREV_ORBIT_URL=""
if [ -f /etc/default/orbit ]; then
  PREV_ORBIT_URL="$(sed -n 's/^ORBIT_FLEET_URL=//p' /etc/default/orbit | head -1 || true)"
  echo "  - existing Fleet agent found (server: ${PREV_ORBIT_URL:-unknown})"
fi

# --- 1. Fetch the agent package FIRST ---------------------------------------
# This doubles as the credential check: a bad enroll secret is rejected here,
# before anything has been written to this machine. Writing the tenant marker
# first would leave a file claiming this machine belongs to an organisation, on
# a run that never installed an agent — evidence of something that didn't happen.
#
# The package is pre-built for your organisation: it carries your enroll secret
# and trusts this server's certificate. A generic package fails on both counts —
# --insecure alone was not enough, osquery still refused the certificate.
echo "  - downloading Fleet agent (${PKG_TYPE})"
# The package is built on first request, so a brand-new organisation may get a
# 503 ("being prepared") for the first ~30-60s while the build runs. Poll: treat
# 503 as "wait and retry", any other non-200 as a real failure, 200 as done.
PKG_URL="${OBAITS_PORTAL_URL}/enroll/package?type=${PKG_TYPE}"
PKG_OUT="${TMP}/fleet-agent.${PKG_TYPE}"
DL_OK="no"
for attempt in $(seq 1 20); do
  HTTP_CODE="$(curl -sSL -w '%{http_code}' \
    -H "X-Obaits-Enroll-Secret: ${OBAITS_ENROLL_SECRET}" \
    "$PKG_URL" -o "$PKG_OUT" 2>/dev/null || echo "000")"
  if [ "$HTTP_CODE" = "200" ]; then
    DL_OK="yes"; break
  elif [ "$HTTP_CODE" = "503" ]; then
    [ "$attempt" -eq 1 ] && echo "    (preparing your agent package, please wait…)"
    sleep 6
  else
    echo "ERROR: package download failed (HTTP ${HTTP_CODE})." >&2
    echo "Check that the command was copied whole from your Obaits portal." >&2
    exit 1
  fi
done

if [ "$DL_OK" != "yes" ]; then
  echo "ERROR: the agent package was not ready after waiting. Try again shortly." >&2
  exit 1
fi

# curl -f fails on an HTTP error, but a truncated download returns success.
if [ ! -s "${TMP}/fleet-agent.${PKG_TYPE}" ]; then
  echo "ERROR: the downloaded agent package is empty." >&2
  exit 1
fi

# --- 2. Tenant marker -------------------------------------------------------
# Fleet Free has no Teams and does not report which enroll secret a host used.
# This file is the ONLY thing attributing this machine to your organisation:
# a Fleet label matches this exact path. Without it the machine enrols
# successfully and belongs to nobody.
MARKER="/etc/obaits/tenant-${OBAITS_TENANT_ID}"
mkdir -p /etc/obaits
touch "$MARKER"
chmod 644 "$MARKER"

# Verify rather than assume.
if [ ! -f "$MARKER" ]; then
  echo "ERROR: could not write the tenant marker at $MARKER" >&2
  exit 1
fi
echo "  - tenant marker written ($MARKER)"

# --- 3. Install the Fleet agent ---------------------------------------------
# ALWAYS clear orbit's prior identity before installing, rather than trying to
# detect which specific setting changed. A stale node key silently carries
# forward whatever capabilities (server URL, --enable-scripts, anything added
# later) were true at ORIGINAL enrollment — dpkg upgrading the binary in place
# does not renegotiate them. Confirmed 2026-07-25: --enable-scripts landed
# correctly in /etc/default/orbit and the process restarted clean, yet Fleet
# still refused scripts until the node key itself was purged and orbit
# re-enrolled from nothing. A fresh enrollment is cheap; a silently-stale one
# is not — this has now bitten the Fleet URL, the Wazuh manager/group, and
# --enable-scripts. Stop special-casing each one.
if [ -n "$PREV_ORBIT_URL" ] || [ -d /opt/orbit ]; then
  echo "  - clearing prior Fleet agent identity (forces fresh enrollment)"
  systemctl stop orbit 2>/dev/null || true
  rm -f  /opt/orbit/secret-orbit-node-key.txt
  rm -rf /opt/orbit/osquery.db
fi

case "$PKG_TYPE" in
  deb) dpkg -i "${TMP}/fleet-agent.deb" ;;
  rpm) rpm -Uvh "${TMP}/fleet-agent.rpm" ;;
  pkg) installer -pkg "${TMP}/fleet-agent.pkg" -target / ;;
esac
echo "  - Fleet agent installed"

# --- 3.1 Install restic (Obaits Backup) --------------------------------------
# Idempotent: skip if already present at a usable version. restic is a single
# static binary with no daemon — nothing to enrol or configure at install time;
# Obaits pushes backup runs later via Fleet Scripts, which need only the binary
# on PATH.
if ! command -v restic >/dev/null 2>&1; then
  echo "  - installing restic"
  case "$PKG_TYPE" in
    deb) apt-get update -qq && apt-get install -y restic ;;
    rpm) yum install -y epel-release 2>/dev/null || true; yum install -y restic ;;
    pkg)
      if command -v brew >/dev/null 2>&1; then
        brew install restic
      else
        echo "    WARNING: Homebrew not found — restic was not installed." >&2
        echo "    Backup selection will be unavailable until it is installed manually." >&2
      fi
      ;;
  esac
  if command -v restic >/dev/null 2>&1; then
    echo "    restic $(restic version | head -1)"
  else
    echo "    WARNING: restic install failed — backup features will be unavailable on this device." >&2
  fi
else
  echo "  - restic already present ($(restic version | head -1))"
fi

# --- 3.2 Install Tailscale + join this tenant's Headscale VPN (Phase 4) -----
# Idempotent: skip installing if the binary exists; ALWAYS attempt to (re)join
# so a re-enrolled/re-imaged device (which lost its prior tailnet identity)
# gets back on the tenant's VPN even if the binary itself never needed
# reinstalling. Failure here WARNS and continues — a VPN join must never
# block the rest of enrollment (Fleet/Wazuh matter more), matching restic's
# own "warn, don't abort" precedent above.
if ! command -v tailscale >/dev/null 2>&1; then
  echo "  - installing Tailscale"
  if curl -fsSL https://tailscale.com/install.sh | sh >/tmp/obaits-tailscale-install.log 2>&1; then
    echo "    tailscale $(tailscale version 2>/dev/null | head -1)"
  else
    echo "    WARNING: Tailscale install failed — see /tmp/obaits-tailscale-install.log" >&2
    echo "    This device will not be reachable over the tenant VPN." >&2
  fi
else
  echo "  - Tailscale already present ($(tailscale version | head -1))"
fi

if command -v tailscale >/dev/null 2>&1; then
  echo "  - fetching VPN credentials"
  HS_ENV="$(curl -sS -H "X-Obaits-Enroll-Secret: ${OBAITS_ENROLL_SECRET}" \
    "${OBAITS_PORTAL_URL}/enroll/headscale-credentials" 2>/dev/null || true)"

  HS_SERVER="$(printf '%s\n' "$HS_ENV" | sed -n 's/^HEADSCALE_LOGIN_SERVER=//p')"
  HS_KEY="$(printf '%s\n' "$HS_ENV" | sed -n 's/^HEADSCALE_AUTH_KEY=//p')"

  if [ -n "$HS_SERVER" ] && [ -n "$HS_KEY" ]; then
    # REAL FIX 2026-08-15, same root cause as the Windows fix: a device
    # with ANY prior non-default Tailscale config (e.g. from an earlier
    # enrollment) will have "tailscale up" refuse to apply new settings
    # unless --reset is passed -- this script already correctly checked
    # the exit code (the Windows version did not, until today), but was
    # still vulnerable to the underlying refusal actually happening.
    # --reset makes every run start from a clean slate.
    if tailscale up --login-server="$HS_SERVER" --authkey="$HS_KEY" --accept-routes --reset 2>/tmp/obaits-tailscale-up.log; then
      echo "    joined tenant VPN: $(tailscale ip -4 2>/dev/null)"
    else
      echo "    WARNING: could not join tenant VPN — see /tmp/obaits-tailscale-up.log" >&2
      echo "    This device will not be reachable over the tenant VPN." >&2
    fi
  else
    echo "    WARNING: no VPN instance provisioned yet for this tenant — skipping VPN join." >&2
  fi
fi


# --- 2.7 Fetch this device's policy credential (Phase: device credentials) --
# Long-lived, rotated on every enrollment run -- unlike the short-lived
# tokens used elsewhere in this project, this one has to survive a device
# being offline for weeks (public holidays, staff leave), which a
# launch-time token genuinely cannot. Warn-and-continue: a device without
# this credential simply can't run policy checks yet, which must never
# block Fleet/Wazuh/Zabbix, the same precedent already set above.
echo "  - fetching device credential"
DEVICE_SECRET="$(curl -sS -X POST \
  -H "X-Obaits-Enroll-Secret: ${OBAITS_ENROLL_SECRET}" \
  --data-urlencode "hostname=$(hostname)" \
  "${OBAITS_PORTAL_URL}/enroll/device-credential" 2>/dev/null || true)"

if [ -n "$DEVICE_SECRET" ]; then
  mkdir -p /etc/obaits
  printf '%s' "$DEVICE_SECRET" > /etc/obaits/device-credential
  chmod 600 /etc/obaits/device-credential
  chown root:root /etc/obaits/device-credential
  echo "    device credential stored"
else
  echo "    WARNING: could not fetch a device credential -- policy checks will be unavailable on this device until the install script is re-run." >&2
fi

# REAL FEATURE 2026-08-23: persist the portal URL so unattended, device-side
# work (the threat-intel timer below) can find the portal without an
# environment -- a systemd unit has none. Deliberately a FILE rather than a
# value baked into the unit: a portal rename (Obaits -> SecX) or an
# on-prem/.iso deployment then edits one line per device instead of
# rewriting and reloading a systemd unit on every device.
#
# Written unconditionally, outside the device-credential block above: the
# portal URL is not a secret and is useful even on a device whose
# credential fetch failed.
mkdir -p /etc/obaits
printf '%s' "${OBAITS_PORTAL_URL}" > /etc/obaits/portal-url
chmod 644 /etc/obaits/portal-url
chown root:root /etc/obaits/portal-url


# --- 4. Wazuh agent, version-pinned -----------------------------------------
# Wazuh refuses any agent NEWER than its manager: "Agent version must be lower
# or equal to manager version". The version is supplied by the platform, which
# reads it from the manager — never hardcoded, or a manager upgrade silently
# breaks every new enrollment.
if [ "$PKG_TYPE" = "deb" ]; then
  # Real fix: fetch the real, specific .deb directly (via the Obaits
  # mirror, with a real fallback to Wazuhs own site) rather than
  # adding a third-party apt repo live at enrollment time.
  WAZUH_DEB="${TMP}/wazuh-agent.deb"
  WAZUH_FALLBACK_URL="https://packages.wazuh.com/4.x/apt/pool/main/w/wazuh-agent/wazuh-agent_${OBAITS_WAZUH_VERSION}_amd64.deb"
  get_obaits_package "wazuh-agent-linux" "$WAZUH_DEB" "$WAZUH_FALLBACK_URL" "$OBAITS_WAZUH_VERSION" || exit 1

  # REAL BUG FOUND 2026-08-11, on two real customer machines
  # (mail.aicexperts.com, obaitsclient1): Wazuh's own .deb package has a
  # genuine, unguarded bug in BOTH its pre-install and post-removal
  # maintainer scripts -- each does an unquoted shell glob
  # (ls /var/ossec/etc/shared/*, mv /var/ossec/tmp/conffiles/* ...) that
  # fails with "No such file or directory" and a real, non-zero exit
  # code whenever that directory is genuinely EMPTY (confirmed directly:
  # a worn prior install, or one interrupted mid-registration, can leave
  # etc/shared/ empty). dpkg then aborts the whole operation -- this
  # surfaced live as a silent "Terminated"/"Killed" inside this script's
  # own subshell, with the REAL underlying error only visible when run
  # manually, outside the script, by hand.
  #
  # Not fixable on our side -- it is Wazuh's own packaging. Real fix:
  # matching the SAME real, established philosophy already applied to
  # Fleet above ("a fresh enrollment is cheap; a silently-stale one is
  # not, stop special-casing each one"), ALWAYS force-purge any existing
  # Wazuh agent and its full data directory before every install, so
  # neither broken maintainer script ever runs against a worn, empty, or
  # partially-removed prior install again. Confirmed live: a first purge
  # attempt can itself hit the same post-removal glob bug and report an
  # error -- --force-remove-reinstreq plus a second purge attempt after
  # removing /var/ossec is the real, empirically-proven sequence that
  # gets dpkg back to a genuinely clean state every time.
  if dpkg -l wazuh-agent 2>/dev/null | grep -q '^[hi]i'; then
    echo "  - clearing prior Wazuh agent installation (forces fresh install; works around a real bug in Wazuh's own packaging)"
    systemctl stop wazuh-agent 2>/dev/null || true
    dpkg --purge --force-remove-reinstreq wazuh-agent >/dev/null 2>&1 || true
    rm -rf /var/ossec
    dpkg --remove --force-remove-reinstreq wazuh-agent >/dev/null 2>&1 || true
    dpkg --purge --force-remove-reinstreq wazuh-agent >/dev/null 2>&1 || true
  fi

  WAZUH_MANAGER="${OBAITS_WAZUH_MANAGER}" WAZUH_AGENT_GROUP="${OBAITS_WAZUH_GROUP}" \
    dpkg -i "$WAZUH_DEB" || apt-get install -f -y
elif [ "$PKG_TYPE" = "rpm" ]; then
  rpm --import https://packages.wazuh.com/key/GPG-KEY-WAZUH
  cat > /etc/yum.repos.d/wazuh.repo <<'REPO'
[wazuh]
gpgcheck=1
gpgkey=https://packages.wazuh.com/key/GPG-KEY-WAZUH
enabled=1
name=Wazuh repository
baseurl=https://packages.wazuh.com/4.x/yum/
protect=1
REPO
  WAZUH_MANAGER="${OBAITS_WAZUH_MANAGER}" WAZUH_AGENT_GROUP="${OBAITS_WAZUH_GROUP}" \
    yum install -y "wazuh-agent-${OBAITS_WAZUH_VERSION}"
else
  curl -fsSL "https://packages.wazuh.com/4.x/macos/wazuh-agent-${OBAITS_WAZUH_VERSION}.pkg" \
    -o "${TMP}/wazuh-agent.pkg"
  echo "WAZUH_MANAGER='${OBAITS_WAZUH_MANAGER}' && WAZUH_AGENT_GROUP='${OBAITS_WAZUH_GROUP}'" \
    > /tmp/wazuh_envs
  installer -pkg "${TMP}/wazuh-agent.pkg" -target /
fi

# --- 4.5 Enforce the intended manager ----------------------------------------
# WAZUH_MANAGER above only takes effect on a FRESH install: on an upgrade the
# package keeps the existing ossec.conf, which may point at another manager
# (observed 2026-07-21: a dev machine "registered" with its own localhost).
# Enforce the address, and if it changed, drop the old identity and re-register.
OSSEC_CONF="/var/ossec/etc/ossec.conf"
[ -f "$OSSEC_CONF" ] || OSSEC_CONF="/Library/Ossec/etc/ossec.conf"
OSSEC_DIR="$(dirname "$(dirname "$OSSEC_CONF")")"

if [ -f "$OSSEC_CONF" ]; then
  CUR_ADDR="$(sed -n 's|.*<address>\(.*\)</address>.*|\1|p' "$OSSEC_CONF" | head -1 || true)"
  if [ -n "$CUR_ADDR" ] && [ "$CUR_ADDR" != "${OBAITS_WAZUH_MANAGER}" ]; then
    echo "  - Wazuh manager changed (${CUR_ADDR} -> ${OBAITS_WAZUH_MANAGER}); re-registering"
    sed -i.obaits-bak "s|<address>.*</address>|<address>${OBAITS_WAZUH_MANAGER}</address>|" "$OSSEC_CONF"
    rm -f "${OSSEC_DIR}/etc/client.keys"
  fi

  # Added 2026-07-23: the address check above does NOT catch a device
  # re-enrolling under a DIFFERENT TENANT with the SAME manager — the most
  # common real case (a machine moved from one customer's Fleet label to
  # another, or a test device reused). ossec.conf's <enrollment><groups>
  # is what agent-auth actually requests, and only gets set at the ORIGINAL
  # enrollment — a plain restart keeps the old, wrong group forever, landing
  # the agent in Wazuh's "default" group, invisible to every tenant's UI.
  CUR_GROUP="$(sed -n '/<enrollment>/,/<\/enrollment>/ s|.*<groups>\(.*\)</groups>.*|\1|p' "$OSSEC_CONF" | head -1 || true)"
  if [ -n "$CUR_GROUP" ] && [ "$CUR_GROUP" != "${OBAITS_WAZUH_GROUP}" ]; then
    echo "  - Wazuh group changed (${CUR_GROUP} -> ${OBAITS_WAZUH_GROUP}); re-registering"
    sed -i.obaits-bak2 "/<enrollment>/,/<\/enrollment>/ s|<groups>.*</groups>|<groups>${OBAITS_WAZUH_GROUP}</groups>|" "$OSSEC_CONF"
    rm -f "${OSSEC_DIR}/etc/client.keys"
  fi
fi

# Write the shared enrollment password where wazuh-agentd's OWN auto
# re-enrollment reads it (ossec.conf's <authorization_pass_path>, checked
# above). Our explicit agent-auth calls below get -P directly, but that does
# NOT cover the agent's internal retry logic if it ever needs to re-enrol
# itself later — without this file, that path fails forever with "no
# authentication password provided", even though this script's own agent-auth
# succeeded. Must be root:wazuh 640, NOT root:root — wazuh-agentd runs as
# user 'wazuh' and cannot read a root-only file. Confirmed 2026-07-23 on a
# live re-enrollment: the retry loop was silently permission-denied, not
# missing the value.
echo "${OBAITS_WAZUH_ENROLL_PASSWORD}" | sudo tee "${OSSEC_DIR}/etc/authd.pass" > /dev/null
sudo chown root:wazuh "${OSSEC_DIR}/etc/authd.pass" 2>/dev/null || true
sudo chmod 640 "${OSSEC_DIR}/etc/authd.pass"

systemctl daemon-reload 2>/dev/null || true
systemctl enable wazuh-agent 2>/dev/null || true
systemctl restart wazuh-agent 2>/dev/null || /Library/Ossec/bin/wazuh-control restart
echo "  - Wazuh agent installed"

# If the agent still has no identity, register explicitly rather than hoping.
sleep 5
if [ ! -s "${OSSEC_DIR}/etc/client.keys" ]; then
  echo "  - registering Wazuh agent with ${OBAITS_WAZUH_MANAGER}"
  AUTH_OUT="$("${OSSEC_DIR}/bin/agent-auth" -m "${OBAITS_WAZUH_MANAGER}" -G "${OBAITS_WAZUH_GROUP}" -P "${OBAITS_WAZUH_ENROLL_PASSWORD}" 2>&1 || true)"
  echo "$AUTH_OUT"

  # A re-imaged or restored machine collides with its own old registration:
  # the manager still holds an agent under this hostname. Ask the server (which
  # holds the monitoring API credentials — this script must not) to clear a DEAD
  # prior identity for THIS tenant, then retry once. A DIFFERENT live machine
  # sharing the name is refused server-side (409) and reported honestly.
  if printf '%s' "$AUTH_OUT" | grep -qi 'Duplicate agent name'; then
    echo "  - name already registered; asking the server to clear the old record"
    PRECHECK_CODE="$(curl -sS -o /tmp/obaits_precheck.txt -w '%{http_code}' \
      -H "X-Obaits-Enroll-Secret: ${OBAITS_ENROLL_SECRET}" \
      --data-urlencode "agent_name=$(hostname)" \
      "${OBAITS_PORTAL_URL}/enroll/wazuh-precheck" 2>/dev/null || echo "000")"

    if [ "$PRECHECK_CODE" = "200" ]; then
      "${OSSEC_DIR}/bin/agent-auth" -m "${OBAITS_WAZUH_MANAGER}" -G "${OBAITS_WAZUH_GROUP}" -P "${OBAITS_WAZUH_ENROLL_PASSWORD}" || true
    elif [ "$PRECHECK_CODE" = "409" ]; then
      echo ""
      cat /tmp/obaits_precheck.txt >&2
      echo ""
    else
      echo "  - could not clear the old record (HTTP ${PRECHECK_CODE}); the computer" >&2
      echo "    may not appear in monitoring. Contact support if this persists." >&2
    fi
    rm -f /tmp/obaits_precheck.txt
  fi
  systemctl restart wazuh-agent 2>/dev/null || /Library/Ossec/bin/wazuh-control restart || true
fi

# --- 4.5 Audit enablement (Phase 4.1 Stage 2) -------------------------------
# Transparent to the user — runs automatically, warns and continues on any
# failure rather than aborting the rest of enrollment (Fleet/Wazuh matter
# more). Fetched fresh each time rather than embedded, so a future update
# to the audit rules reaches every device on next enrollment/re-run
# without needing a new install.sh release.
echo "  - enabling host audit logging (auditd, journald persistence)"
if curl -fsSL "${OBAITS_PORTAL_URL}/enroll/audit-linux.sh" 2>/dev/null | bash 2>/tmp/obaits-audit.log; then
  echo "    audit logging enabled"
else
  echo "    WARNING: audit enablement had issues — see /tmp/obaits-audit.log" >&2
  echo "    Security monitoring will still work, just with less detail than intended." >&2
fi

# --- 4.6 Zabbix agent (IT asset health monitoring) --------------------------
# Idempotent: skip installing if the package is already present; ALWAYS write
# a fresh config and restart, matching restic/Tailscale's own real "warn, do
# not abort" precedent -- a Zabbix problem must never block Fleet/Wazuh, which
# matter more. Uses Zabbix's own native HostMetadata auto-registration
# mechanism (confirmed directly against Zabbix's own official API docs) --
# the server-side action already created for this tenant (ZabbixStep) reads
# this real, random secret and automatically places the new host in the
# correct tenant's host group, with no manual host.create call needed here
# at all. The VPS is deliberately NOT part of the tenant's Tailscale VPN
# (confirmed directly, 2026-08-04), so this connects to the real, public
# OBAITS_ZABBIX_SERVER address, same as Wazuh's own agent traffic above.
echo "  - installing Zabbix agent (IT asset health monitoring)"
if ! command -v zabbix_agent2 >/dev/null 2>&1; then
  case "$PKG_TYPE" in
    deb)
      # Real fix: fetch the real, specific agent2 .deb directly via the
      # Obaits mirror rather than adding a third-party apt repo live at
      # enrollment time. Confirmed directly: Zabbix own real repo has no
      # simple "latest" alias for this specific file, its own real
      # version string changes on every release -- rather than guess a
      # fake fallback URL that would silently fail, this tool has no
      # real, direct external fallback at all; only the Obaits mirror
      # itself (kept genuinely current by a real, daily scheduled sync).
      ZBX_DEB="${TMP}/zabbix-agent2.deb"
      if get_obaits_package "zabbix-agent-linux" "$ZBX_DEB" "" "" "$OS_ID" "$OS_VERSION" 2>/tmp/obaits-zabbix-fetch.log; then
        dpkg -i "$ZBX_DEB" >/tmp/obaits-zabbix-install.log 2>&1 || apt-get install -f -y >>/tmp/obaits-zabbix-install.log 2>&1 || true
      fi
      ;;
    rpm)
      rpm -Uvh "https://repo.zabbix.com/zabbix/7.0/rhel/9/x86_64/zabbix-release-7.0-1.el9.noarch.rpm" 2>/tmp/obaits-zabbix-install.log || true
      yum install -y zabbix-agent2 >>/tmp/obaits-zabbix-install.log 2>&1 || true
      ;;
    pkg)
      if command -v brew >/dev/null 2>&1; then
        brew install zabbix-agent2 >/tmp/obaits-zabbix-install.log 2>&1 || true
      fi
      ;;
  esac
fi

if command -v zabbix_agent2 >/dev/null 2>&1; then
  ZBX_CONF="/etc/zabbix/zabbix_agent2.conf"
  if [ -f "$ZBX_CONF" ]; then
    cat > "$ZBX_CONF" <<ZBXCONF
Server=
ServerActive=${OBAITS_ZABBIX_SERVER}
Hostname=$(hostname)
HostMetadata=${OBAITS_ZABBIX_SECRET}
ZBXCONF
    systemctl enable zabbix-agent2 2>/dev/null || true
    systemctl restart zabbix-agent2 2>/dev/null || true
    if systemctl is-active --quiet zabbix-agent2 2>/dev/null; then
      echo "    Zabbix agent installed and running"
    else
      echo "    WARNING: Zabbix agent installed but not running — see /tmp/obaits-zabbix-install.log" >&2
    fi
  else
    echo "    WARNING: Zabbix agent config not found after install — see /tmp/obaits-zabbix-install.log" >&2
  fi
else
  echo "    WARNING: Zabbix agent install failed — see /tmp/obaits-zabbix-install.log" >&2
  echo "    IT asset health monitoring will be unavailable on this device." >&2
fi

# --- 4.9 Permanently hold platform-managed packages -------------------------
# REAL INCIDENT 2026-08-12: a tenant's own OS Update policy upgraded
# wazuh-agent unconstrained (4.9.2 to 4.14.7 in one step), which reset
# its config to a fresh template and wiped its own client key entirely,
# taking real monitoring offline on a real device. This platform's own
# managed packages must NEVER be touched by a tenant's own OS-update
# tooling, or by any other stray "apt upgrade" run on the box -- their
# versions are deliberately, separately managed by Obaits itself (see
# OBAITS_WAZUH_VERSION above). A PERSISTENT hold (not held-during-one-
# script-run) is the correct fix: it protects against every upgrade
# path on the device, not just this platform's own policy engine.
if [ "$PKG_TYPE" = "deb" ]; then
  apt-mark hold wazuh-agent zabbix-agent2 zabbix-release fleet-osquery tailscale tailscale-archive-keyring restic > /dev/null 2>&1 || true
fi

# --- 4.95 Baseline threat-intel protection, triggered immediately ----------
# REAL FIX 2026-08-16: this protection previously only ever started via
# the hourly scheduled sweep (obaits:refresh-threat-intel) -- a freshly
# enrolled device had up to a 60-minute real gap before its first real
# protection cycle, inconsistent with treating this as genuine BASELINE
# protection the same way Sysmon/auditd audit logging above is enabled
# immediately, not on a delayed schedule. Real, explicit requirement
# (confirmed with Brian): must never slow down or block the rest of
# enrollment -- launched fully detached (nohup ... &, redirected to its
# own log file) so this script's own exit does not wait for it. The
# hourly job then simply keeps this same protection current afterward.
if [ "$PKG_TYPE" = "deb" ]; then
  echo "  - starting baseline threat-intel protection (running in background)"
  nohup bash -c 'curl -sS "'"${OBAITS_PORTAL_URL}"'/enroll/threat-intel-refresh.sh" | sudo bash' \
    > /var/log/obaits-threat-intel-initial.log 2>&1 &
  disown

  # REAL FEATURE 2026-08-23: hourly refresh now runs from a DEVICE-SIDE
  # timer. It previously ran by pushing a script to every host, every hour,
  # through Fleet -- but Fleet SERIALISES script execution per host, and this
  # refresh takes ~5 minutes, so the pushes accumulated into a confirmed
  # 240-item backlog across 3 hosts (oldest queued FIVE DAYS) that starved
  # genuine Group Policy checks of any chance to run. A device-side timer has
  # no such shared channel and scales to any device count.
  #
  # The immediate run above is deliberately KEPT: a freshly enrolled device
  # must not wait up to an hour for its first protection cycle (that gap was
  # itself a real bug, fixed 2026-08-16).
  #
  # RandomizedDelaySec spreads devices across the hour so a hundred of them
  # do not hit the portal, or the upstream feeds, simultaneously on the hour.
  # Persistent=true means a device that was powered off still refreshes on
  # its next boot rather than silently skipping a cycle.
  echo "  - installing the hourly threat-intel refresh timer"
  cat > /etc/systemd/system/obaits-threat-intel.service <<'UNIT'
[Unit]
Description=Obaits baseline threat-intel refresh
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/bin/bash -c 'curl -sS "$(cat /etc/obaits/portal-url)/enroll/threat-intel-refresh.sh" | bash'
UNIT
  cat > /etc/systemd/system/obaits-threat-intel.timer <<'UNIT'
[Unit]
Description=Hourly Obaits baseline threat-intel refresh

[Timer]
OnCalendar=hourly
RandomizedDelaySec=3000
Persistent=true

[Install]
WantedBy=timers.target
UNIT
  systemctl daemon-reload 2>/dev/null || true
  systemctl enable --now obaits-threat-intel.timer 2>/dev/null || true
  if systemctl is-active --quiet obaits-threat-intel.timer 2>/dev/null; then
    echo "    threat-intel timer active"
  else
    echo "    WARNING: the threat-intel timer did not start -- this device will not refresh its threat-intel automatically." >&2
  fi
fi

# --- CIS Baseline via Wazuh SCA, audit-only -----------------------------
# REAL FEATURE 2026-08-18, real, explicit safety decision confirmed with
# Brian: audit-only, NEVER auto-remediate individual CIS checks (they can
# genuinely break real production functionality if auto-applied). Wazuh's
# own SCA module is already enabled by default and auto-scans every real
# .yml policy present in its own ruleset/sca/ directory -- the ONLY real
# gap is that this specific Wazuh version (4.9.2) ships with NO CIS
# policy for Ubuntu 24.04 at all (confirmed directly via Wazuh's own
# official forum: not released until a later version). For 22.04, the
# real, correct default policy is already active -- nothing to do. For
# 24.04/Mint, this fetches a real, verified-safe adapted policy (the
# genuine 22.04 benchmark content, ONLY its OS-version detection gate
# widened to also accept 24.04/Mint -- every real CIS check is
# byte-identical to Wazuh's own official, tested 22.04 file), per
# Wazuh's own officially-recommended workaround for this exact version.
if [ "$PKG_TYPE" = "deb" ]; then
  if [ "$OS_ID" = "ubuntu" ] && [ "${OS_VERSION%%.*}" = "24" ]; then
    echo "  - fetching CIS Baseline SCA policy (Ubuntu 24.04, adapted -- see policy file's own real, honest limitation note)"
    sudo curl -sSf "${OBAITS_PORTAL_URL}/enroll/sca-policy/cis_ubuntu2404_adapted.yml" \
      -o /var/ossec/ruleset/sca/cis_ubuntu2404_adapted.yml 2>/dev/null \
      && echo "    CIS Baseline policy installed" \
      || echo "    WARNING: could not fetch CIS Baseline policy -- SCA will run without a matched policy on this device."
  elif [ "$OS_ID" = "linuxmint" ] && grep -q "UBUNTU_CODENAME=noble" /etc/os-release 2>/dev/null; then
    echo "  - fetching CIS Baseline SCA policy (Linux Mint, noble-based, adapted -- see policy file's own real, honest limitation note)"
    sudo curl -sSf "${OBAITS_PORTAL_URL}/enroll/sca-policy/cis_ubuntu2404_adapted.yml" \
      -o /var/ossec/ruleset/sca/cis_ubuntu2404_adapted.yml 2>/dev/null \
      && echo "    CIS Baseline policy installed" \
      || echo "    WARNING: could not fetch CIS Baseline policy -- SCA will run without a matched policy on this device."
  else
    echo "  - CIS Baseline: real, official Wazuh policy already covers this OS by default, or no matched policy exists yet for it."
  fi
fi




# --- 5. Honest confirmation -------------------------------------------------
# Report only what can be checked locally — and check it against the INTENDED
# servers, not merely that files exist. A registration with the WRONG manager
# is worse than none: it looks green and protects nothing.
sleep 10
FLEET_OK="no"; WAZUH_OK="no"
systemctl is-active --quiet orbit 2>/dev/null && FLEET_OK="yes"

FINAL_ADDR="$(sed -n 's|.*<address>\(.*\)</address>.*|\1|p' "$OSSEC_CONF" 2>/dev/null | head -1 || true)"
if [ -s "${OSSEC_DIR}/etc/client.keys" ] && [ "$FINAL_ADDR" = "${OBAITS_WAZUH_MANAGER}" ]; then
  WAZUH_OK="registered with ${OBAITS_WAZUH_MANAGER}"
elif [ -s "${OSSEC_DIR}/etc/client.keys" ]; then
  WAZUH_OK="REGISTERED WITH WRONG MANAGER (${FINAL_ADDR:-unknown}) — contact support"
fi

echo ""
echo "==> Done."
echo "    Fleet agent running:  ${FLEET_OK}"
echo "    Wazuh agent:          ${WAZUH_OK}"
echo ""
echo "    This computer should appear in your Obaits portal within a few minutes."
echo "    If it does not, the servers did not accept it — check the portal."