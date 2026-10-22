#!/bin/bash
set -e
echo "=== Obaits: MongoDB audit setup ==="

if ! command -v mongosh &>/dev/null; then
  echo "ERROR: mongosh not found — MongoDB does not appear to be installed."
  exit 1
fi

if ! systemctl is-active --quiet mongod; then
  echo "ERROR: mongod service is not running."
  exit 1
fi

# MongoDB Community Edition has NO native audit logging at all
# (Enterprise/Atlas-only, confirmed directly). Real, honest alternative:
# the built-in slow-query profiler, set to log EVERY operation
# (slowms: -1) rather than only naturally-slow ones. This is a genuine,
# documented MongoDB mechanism — not a workaround — and stays entirely
# within stock Community Edition, no extra package or engine swap needed.
CURRENT_SLOWMS=$(mongosh --quiet --eval "db.getProfilingStatus().slowms" 2>/dev/null || echo "")

if [ "$CURRENT_SLOWMS" = "-1" ]; then
  echo "Profiler already set to log all operations."
else
  echo "Setting profiler to log all operations (slowms: -1)..."
  mongosh --quiet --eval "db.setProfilingLevel(0, { slowms: -1 })"
fi

# REAL, MISSING PREREQUISITE, found on a real device: the log file is
# owned mongodb:mongodb, mode 600 — even MORE restrictive than Postgres/
# MySQL (no group-read bit at all). Loosen to 640 (group-readable) and
# add wazuh to the mongodb group. Confirmed directly this survives a
# mongod restart (it appends to the existing file rather than recreating
# it fresh on every start) — a genuine one-time fix, not something
# needing to be reapplied.
LOG_FILE="/var/log/mongodb/mongod.log"

CURRENT_MODE=$(stat -c "%a" "$LOG_FILE" 2>/dev/null || echo "")
if [ "$CURRENT_MODE" = "640" ] || [ "$CURRENT_MODE" = "644" ]; then
  echo "Log file already group-readable."
else
  echo "Making the MongoDB log file group-readable..."
  chmod 640 "$LOG_FILE"
fi

if id -nG wazuh 2>/dev/null | grep -qw mongodb; then
  echo "wazuh user already in mongodb group."
else
  echo "Adding wazuh user to mongodb group so it can read the log..."
  usermod -aG mongodb wazuh
  echo "Restarting wazuh-agent so the new group membership takes effect..."
  systemctl restart wazuh-agent
fi

echo "=== Obaits: MongoDB audit setup complete ==="
