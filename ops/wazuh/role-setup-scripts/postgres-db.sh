#!/bin/bash
set -e
echo "=== Obaits: PostgreSQL audit setup ==="

if ! command -v pg_lsclusters &>/dev/null; then
  echo "ERROR: PostgreSQL does not appear to be installed (pg_lsclusters not found)."
  exit 1
fi

CLUSTER_INFO=$(pg_lsclusters --no-header | head -1)
if [ -z "$CLUSTER_INFO" ]; then
  echo "ERROR: No PostgreSQL cluster found."
  exit 1
fi

PG_VERSION=$(echo "$CLUSTER_INFO" | awk '{print $1}')
PG_CLUSTER=$(echo "$CLUSTER_INFO" | awk '{print $2}')
CONFIG_FILE="/etc/postgresql/${PG_VERSION}/${PG_CLUSTER}/postgresql.conf"

echo "Detected PostgreSQL ${PG_VERSION}, cluster ${PG_CLUSTER}"
echo "Config file: ${CONFIG_FILE}"

# REAL BUG FIXED, found on a real device: Wazuh's postgresql_log format
# does NOT expand the "*" wildcard the way syslog/other formats do —
# confirmed directly: a literal filename was picked up by logcollector,
# the identical wildcard entry never was. Since the real log filename
# embeds the PostgreSQL version (postgresql-16-main.log, postgresql-17-
# main.log, etc.) and varies per install, hardcoding one version in the
# shared group profile would silently break monitoring on any server
# running a different version. Fix: create a stable, version-independent
# symlink and have the Wazuh group profile watch THAT fixed name instead.
LOG_DIR="/var/log/postgresql"
REAL_LOG="${LOG_DIR}/postgresql-${PG_VERSION}-${PG_CLUSTER}.log"
STABLE_LOG="${LOG_DIR}/postgresql-current.log"

if [ -f "$REAL_LOG" ]; then
  if [ -L "$STABLE_LOG" ] && [ "$(readlink -f "$STABLE_LOG")" = "$(readlink -f "$REAL_LOG")" ]; then
    echo "Stable log symlink already correct."
  else
    ln -sf "$REAL_LOG" "$STABLE_LOG"
    echo "Created stable log symlink: ${STABLE_LOG} -> ${REAL_LOG}"
  fi
else
  echo "WARNING: expected log file ${REAL_LOG} not found — symlink not created."
fi

if [ ! -f "$CONFIG_FILE" ]; then
  echo "ERROR: expected config file not found at ${CONFIG_FILE}"
  exit 1
fi

# Idempotent: install pgaudit only if not already present.
PGAUDIT_PKG="postgresql-${PG_VERSION}-pgaudit"
if dpkg -l | grep -q "^ii  ${PGAUDIT_PKG} "; then
  echo "pgaudit package already installed."
else
  echo "Installing ${PGAUDIT_PKG}..."
  apt-get update -qq
  apt-get install -y "$PGAUDIT_PKG"
fi

NEED_RESTART=false

# Never blindly overwrite an existing shared_preload_libraries line — a
# real server may already preload other extensions, and clobbering that
# could break something unrelated. Only append if the line is genuinely
# absent; if it exists but doesn't mention pgaudit, stop and say so
# clearly rather than silently doing the wrong thing.
EXISTING_PRELOAD=$(grep "^shared_preload_libraries" "$CONFIG_FILE" || true)
if [ -z "$EXISTING_PRELOAD" ]; then
  echo "shared_preload_libraries = 'pgaudit'" >> "$CONFIG_FILE"
  NEED_RESTART=true
  echo "Added shared_preload_libraries = 'pgaudit'"
elif echo "$EXISTING_PRELOAD" | grep -q "pgaudit"; then
  echo "shared_preload_libraries already includes pgaudit — no change needed."
else
  echo "MANUAL REVIEW NEEDED: shared_preload_libraries is already set to '${EXISTING_PRELOAD}'."
  echo "Not overwriting automatically — please add pgaudit to this existing list by hand."
  exit 2
fi

if grep -q "^pgaudit.log" "$CONFIG_FILE"; then
  echo "pgaudit.log already configured."
else
  echo "pgaudit.log = 'all'" >> "$CONFIG_FILE"
  echo "Added pgaudit.log = 'all'"
fi

if [ "$NEED_RESTART" = true ]; then
  echo "Restarting PostgreSQL to apply shared_preload_libraries (required — a reload is not sufficient)..."
  systemctl restart "postgresql@${PG_VERSION}-${PG_CLUSTER}"
  sleep 3
  if ! pg_lsclusters --no-header | grep -q "online"; then
    echo "ERROR: PostgreSQL did not come back online after restart!"
    exit 1
  fi
  echo "PostgreSQL restarted successfully and is online."
else
  echo "No restart needed."
fi

sudo -u postgres psql -c "CREATE EXTENSION IF NOT EXISTS pgaudit;" 2>&1

# REAL, MISSING PREREQUISITE FOUND ON A REAL DEVICE 2026-07-27: PostgreSQL's
# log files are owned postgres:adm, mode 640 — readable only by the
# postgres user and members of the adm group. The Wazuh agent runs as its
# own 'wazuh' user, which is in neither group by default, so it could
# never actually open the log file at all — audit data was being written
# correctly by pgaudit, but Wazuh's logcollector had no permission to read
# it, and failed completely silently (no error, nothing reached the
# manager). This must happen automatically — it's a genuine platform
# prerequisite, not something to leave for a manual step.
if id -nG wazuh 2>/dev/null | grep -qw adm; then
  echo "wazuh user already in adm group."
else
  echo "Adding wazuh user to adm group so it can read PostgreSQL logs..."
  usermod -aG adm wazuh
  echo "Restarting wazuh-agent so the new group membership takes effect..."
  systemctl restart wazuh-agent
fi

echo "=== Obaits: PostgreSQL audit setup complete ==="
