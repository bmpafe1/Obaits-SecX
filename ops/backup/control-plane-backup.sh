#!/bin/bash
# Obaits control-plane backup.
#
# REAL GAP FOUND 2026-08-25: the platform sells backup as a product -- restic,
# scheduled jobs, subscription enforcement, restore polling -- and protected
# nothing of its own. /var/backups held only Debian's alternatives.tar. If this
# host were lost, every tenant's device inventory, monitoring history, incident
# cases, identity records and the entire orchestrator database would be gone.
#
# SCOPE: irreplaceable state ONLY. Of ~39GB across 32 docker volumes, about 31GB
# is re-derivable feed data (SCAP 20GB, VT 639MB, notus 642MB, cert-data 163MB)
# and is deliberately NOT backed up -- it re-syncs from Greenbone. GVM's own
# operational data is only ~855MB (public + cert schemas); the scap schema is
# excluded for the same reason.
#
# KNOWN LIMITATION, stated plainly: this writes to the SAME HOST it protects.
# That is not a backup, it is a snapshot. It is a large improvement on nothing
# and gives the production migration a clean restorable set, but offsite copy
# is REQUIRED before this counts as real protection. When Proxmox Backup Server
# arrives for the customer-facing product, point these dumps at it too.
set -uo pipefail

OUT_ROOT="${OBAITS_BACKUP_DIR:-/var/backups/obaits}"
STAMP="$(date +%Y-%m-%d_%H%M)"
OUT="$OUT_ROOT/$STAMP"
RETAIN_DAYS="${OBAITS_BACKUP_RETAIN_DAYS:-7}"
MIN_BYTES=1024          # anything smaller than this is a failed dump, not a small database

mkdir -p "$OUT"
FAILED=()
OK=()

record() {
  local name="$1" file="$2"
  if [ ! -f "$file" ]; then
    FAILED+=("$name (no file produced)"); return
  fi
  local size
  size=$(stat -c%s "$file")
  if [ "$size" -lt "$MIN_BYTES" ]; then
    FAILED+=("$name (only ${size} bytes -- dump failed)"); return
  fi
  OK+=("$name ($(numfmt --to=iec "$size"))")
}

echo "Obaits control-plane backup -> $OUT"
echo

# --- Orchestrator database (host postgres). The single most irreplaceable
# --- store on the platform: tenants, device credentials, policies, tickets,
# --- compliance documents, scans, findings.
echo "  obaits_orchestrator..."
PGPASSWORD="${DB_PASSWORD:-}" pg_dump -h 127.0.0.1 -p 5432 -U obaits_app -d obaits_orchestrator \
  2>/dev/null | gzip > "$OUT/obaits_orchestrator.sql.gz"
record "obaits_orchestrator" "$OUT/obaits_orchestrator.sql.gz"

# --- Fleet: every enrolled device, policy and query history.
echo "  fleet (mysql)..."
docker exec fleet-mysql sh -c 'exec mysqldump -ufleet -p"$MYSQL_PASSWORD" --single-transaction fleet' \
  2>/dev/null | gzip > "$OUT/fleet.sql.gz"
record "fleet" "$OUT/fleet.sql.gz"

# --- Zabbix: monitoring history.
echo "  zabbix..."
docker exec zabbix-postgres sh -c 'exec pg_dump -U zabbix zabbix' \
  2>/dev/null | gzip > "$OUT/zabbix.sql.gz"
record "zabbix" "$OUT/zabbix.sql.gz"

# --- Keycloak: identity. Lose this and nobody can log in.
echo "  keycloak..."
docker exec keycloak-db sh -c 'exec pg_dump -U keycloak keycloak' \
  2>/dev/null | gzip > "$OUT/keycloak.sql.gz"
record "keycloak" "$OUT/keycloak.sql.gz"

# --- DFIR-IRIS: incident cases and evidence.
echo "  iris..."
docker exec iriswebapp_db sh -c 'exec pg_dump -U postgres iris_db' \
  2>/dev/null | gzip > "$OUT/iris.sql.gz"
record "iris" "$OUT/iris.sql.gz"

# --- GVM: operational data only. The scap schema is 20GB of re-derivable
# --- CVE/CPE feed data and is deliberately excluded.
echo "  gvmd (public + cert schemas only)..."
docker exec greenbone-community-edition-pg-gvm-1 sh -c \
  'exec pg_dump -U gvmd -n public -n cert gvmd' \
  2>/dev/null | gzip > "$OUT/gvmd.sql.gz"
record "gvmd" "$OUT/gvmd.sql.gz"

# --- Retention
find "$OUT_ROOT" -maxdepth 1 -type d -name '20*' -mtime "+$RETAIN_DAYS" -exec rm -rf {} + 2>/dev/null

echo
echo "SUCCEEDED:"
for o in "${OK[@]:-}"; do [ -n "$o" ] && echo "  $o"; done
if [ "${#FAILED[@]}" -gt 0 ]; then
  echo
  echo "FAILED:"
  for f in "${FAILED[@]}"; do echo "  $f"; done
  echo
  echo "Total backed up: $(du -sh "$OUT" | cut -f1)"
  exit 1
fi
echo
echo "Total backed up: $(du -sh "$OUT" | cut -f1)"
echo "All databases backed up successfully."
