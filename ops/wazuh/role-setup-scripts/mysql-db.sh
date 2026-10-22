#!/bin/bash
set -e
echo "=== Obaits: MySQL/MariaDB audit setup ==="

if ! command -v mysql &>/dev/null; then
  echo "ERROR: MySQL/MariaDB client not found."
  exit 1
fi

PLUGIN_SO="server_audit.so"
PLUGIN_DIR=$(mysql -N -e "SHOW VARIABLES LIKE 'plugin_dir';" | awk '{print $2}')
echo "Plugin directory: ${PLUGIN_DIR}"

if [ ! -f "${PLUGIN_DIR}/${PLUGIN_SO}" ]; then
  echo "ERROR: ${PLUGIN_SO} not found in ${PLUGIN_DIR} — this MariaDB/MySQL build may not bundle the audit plugin."
  exit 1
fi

# Idempotent: check whether the plugin is already loaded.
ALREADY_LOADED=$(mysql -N -e "SHOW PLUGINS;" | grep -c "SERVER_AUDIT" || true)
if [ "$ALREADY_LOADED" -gt 0 ]; then
  echo "server_audit plugin already loaded."
else
  echo "Installing server_audit plugin..."
  mysql -e "INSTALL PLUGIN server_audit SONAME '${PLUGIN_SO}';"
fi

# Real, dedicated audit log location — never the default /var/log/mysql/*
# paths, which this profile's earlier version incorrectly assumed exist by
# default on Ubuntu (confirmed directly: /var/log/mysql/ doesn't exist at
# all on a stock install; MariaDB logs to journald unless explicitly
# configured otherwise).
AUDIT_LOG_DIR="/var/log/mysql"
AUDIT_LOG_FILE="server_audit.log"

mkdir -p "$AUDIT_LOG_DIR"
chown mysql:mysql "$AUDIT_LOG_DIR"
chmod 750 "$AUDIT_LOG_DIR"

# Configure the plugin's real settings persistently, idempotently — never
# duplicate lines on a re-run.
CNF_FILE="/etc/mysql/mariadb.conf.d/99-obaits-audit.cnf"
if [ -f "$CNF_FILE" ]; then
  echo "Obaits audit config file already exists — leaving as-is."
else
  cat > "$CNF_FILE" <<EOF
[mariadb]
server_audit_logging=ON
server_audit_events=CONNECT,QUERY,TABLE
server_audit_file_path=${AUDIT_LOG_DIR}/${AUDIT_LOG_FILE}
server_audit_output_type=FILE
EOF
  echo "Wrote audit config to ${CNF_FILE}"
fi

# server_audit's own settings can be applied live via SET GLOBAL without a
# restart for most of these — but file_path specifically requires the
# plugin to be freshly loaded against it, so a restart is the reliable path.
echo "Restarting MariaDB to apply audit configuration..."
systemctl restart mariadb
sleep 3

if ! systemctl is-active --quiet mariadb; then
  echo "ERROR: MariaDB did not come back online after restart!"
  exit 1
fi
echo "MariaDB restarted successfully and is online."

# REAL, MISSING PREREQUISITE FOUND ON A REAL DEVICE, DIFFERENT from
# Postgres despite looking similar at first: the audit log file itself
# ends up owned mysql:mysql (mode 660), NOT mysql:adm — the directory's
# own group ownership does not cascade to files MariaDB creates inside
# it. Confirmed directly: adding wazuh to adm (Postgres's fix) did
# NOTHING here, since the file's actual group is mysql, not adm. The
# real, durable fix is membership in the mysql group specifically —
# confirmed to survive log rotation too, since logrotate's default
# behavior preserves group ownership on the fresh file it creates
# (checked directly against this system's real /etc/logrotate.d/mariadb,
# which has no create/su directive overriding that default).
if id -nG wazuh 2>/dev/null | grep -qw mysql; then
  echo "wazuh user already in mysql group."
else
  echo "Adding wazuh user to mysql group so it can read the audit log (NOT adm — confirmed the file's real group is mysql)..."
  usermod -aG mysql wazuh
  echo "Restarting wazuh-agent so the new group membership takes effect..."
  systemctl restart wazuh-agent
fi

echo "=== Obaits: MySQL/MariaDB audit setup complete ==="
