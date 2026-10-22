#!/bin/bash
# Real OS Update Enforcement remediate -- Linux (Debian/Ubuntu) only.
# Installs available updates EXCLUDING this platform's own managed
# packages (see check.sh for the real incident this protects against).
# Never reboots -- reboot is always left to a human; a required reboot
# is reported honestly in the result, never acted on.
HELD_PACKAGES="wazuh-agent zabbix-agent2 zabbix-release fleet-osquery tailscale tailscale-archive-keyring restic"
HOSTNAME_ACTUAL="$(hostname)"

apt-get update > /dev/null 2>&1
apt-mark hold $HELD_PACKAGES > /dev/null 2>&1

BEFORE=$(apt list --upgradable 2>/dev/null | grep -v '^Listing' | grep -c '\S')

# DEBIAN_FRONTEND=noninteractive prevents apt from blocking on a real
# interactive prompt -- this runs entirely unattended, with no human able
# to answer one even if it appeared. --force-confold is now a permanent
# default: on any REMAINING (not held) package whose conffile changed,
# always keep the currently-installed file rather than prompt or fail.
# This did not cause the 2026-08-12 incident (the held-package upgrade
# itself did), but is independently correct hardening for any other
# package that could hit the identical trap.
DEBIAN_FRONTEND=noninteractive apt-get -y -o Dpkg::Options::="--force-confold" upgrade > /tmp/obaits-os-update.log 2>&1
UPGRADE_EXIT=$?

AFTER=$(apt list --upgradable 2>/dev/null | grep -v '^Listing' | grep -c '\S')

if [ "$AFTER" -gt 0 ]; then
  STATE="updates_pending:${AFTER}"
else
  STATE="up_to_date"
fi

if [ -f /var/run/reboot-required ]; then
  STATE="${STATE}:reboot_required"
fi

if [ "$UPGRADE_EXIT" -ne 0 ]; then
  STATE="update_failed:exit_${UPGRADE_EXIT}"
fi

curl -sS -X POST "$U/enroll/policy-result" -d "policy_run_id=$RUN_ID" -d "hostname=$HOSTNAME_ACTUAL" -d "result=$STATE" > /dev/null
echo "OS update remediate: before=${BEFORE} after=${AFTER} exit=${UPGRADE_EXIT} state=${STATE}"
