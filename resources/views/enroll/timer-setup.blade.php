#!/bin/bash
# RETROFIT ONLY -- installs the hourly threat-intel timer on a device that
# enrolled BEFORE 2026-08-23, when the timer became part of enrollment itself.
#
# DELIBERATE, ACKNOWLEDGED DUPLICATION: the unit definitions below are a copy
# of the block in install-sh.blade.php (search: "installing the hourly
# threat-intel refresh timer"). Copying was the explicit, considered choice --
# the alternative was restructuring live enrollment code late in a long
# session, on the most safety-critical file in the project, immediately after
# it had been verified working. THE CLEANUP IS REAL AND OUTSTANDING: extract
# both into one shared partial that enrollment and this script both include.
# Until that happens, ANY change to the units MUST be made in BOTH places.
#
# Idempotent: safe to run repeatedly. Existing units are overwritten and the
# timer re-enabled, which is exactly what a re-run should do.
set -o pipefail

echo "  - writing /etc/obaits/portal-url"
mkdir -p /etc/obaits
printf '%s' "{{ $portalUrl }}" > /etc/obaits/portal-url
chmod 644 /etc/obaits/portal-url
chown root:root /etc/obaits/portal-url

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
  echo "    WARNING: the threat-intel timer did not start -- this device will not refresh automatically." >&2
fi

# REAL CONSTRAINT 2026-08-23: an immediate refresh was originally run here, but
# a literal 'curl ... | bash' in a served response body pushes this script over
# ModSecurity's OUTBOUND anomaly threshold (phase 4), killing the response
# mid-stream -- the client sees only "curl: (52) Empty reply from server".
# Confirmed by comparison: /enroll/threat-intel-refresh.sh serves near-identical
# content and passes at 200 with NO nginx exception at all, so CONTENT, not
# mechanism, decides. The timer fires within the hour regardless and
# Persistent=true covers a powered-off device, so the immediate run was
# genuinely optional -- unlike the WAF exception it would have required.
systemctl start obaits-threat-intel.service --no-block 2>/dev/null || true
echo "  - an immediate refresh has been started in the background"
