#!/bin/bash
# Real OS Update Enforcement check -- Linux (Debian/Ubuntu) only.
#
# REAL INCIDENT 2026-08-12, caught live on linuxclient: an early version
# of this policy upgraded EVERY available package with no exclusions --
# including wazuh-agent, which jumped 4.9.2 to 4.14.7 in one step. The
# major-version upgrade reset /var/ossec/etc/ossec.conf to its own fresh
# default template (wiping the real manager address) AND wiped
# /var/ossec/etc/client.keys entirely, taking this platform's own
# monitoring agent down for real, on a real device, mid-test. Recovered
# via a full re-enrollment, but this class of self-inflicted outage must
# never be possible again.
#
# Held package list confirmed against a REAL device's actual installed
# packages and configured apt repos (dpkg -l + /etc/apt/sources.list.d/),
# not guessed from memory -- covers every package this platform itself
# installs and deliberately, separately manages the version of (see
# OBAITS_WAZUH_VERSION etc. in install.sh), never left to a tenant's own
# OS-update policy.
HELD_PACKAGES="wazuh-agent zabbix-agent2 zabbix-release fleet-osquery tailscale tailscale-archive-keyring restic"

apt-get update > /dev/null 2>&1
apt-mark hold $HELD_PACKAGES > /dev/null 2>&1

# apt list --upgradable's first line is a real, non-package informational
# header ("Listing... Done") -- excluded, not a real pending update. Held
# packages are automatically excluded by apt itself once marked held --
# they will never appear in this list at all.
PENDING=$(apt list --upgradable 2>/dev/null | grep -v '^Listing' | grep -c '\S')

HOSTNAME_ACTUAL="$(hostname)"
if [ "$PENDING" -gt 0 ]; then
  STATE="updates_pending:${PENDING}"
else
  STATE="up_to_date"
fi

curl -sS -X POST "$U/enroll/policy-result" -d "policy_run_id=$RUN_ID" -d "hostname=$HOSTNAME_ACTUAL" -d "result=$STATE" > /dev/null
echo "OS update check: ${STATE}"
