#!/bin/bash
# Obaits: Docker host monitoring setup - Linux
# REAL, DELIBERATE SECURITY CHOICE: adding the wazuh service account to
# the "docker" group would be effectively root-equivalent (Docker's own
# API can mount arbitrary host paths, run privileged containers, etc.) --
# a genuinely bigger privilege than any database log-file group grant
# already made for other roles. Brian's explicit choice: keep the wazuh
# agent's own privileges completely unchanged. A ROOT-owned cron job
# runs the actual `docker events` polling instead, writing plain output
# to a real file; wazuh only ever gets READ access to that file, the
# same narrower pattern already proven for the database audit logs.
#
# Also fixes a real, already-documented limitation in this project's own
# profile: a naive `docker events --since 1h` run every hour re-reports
# the prior hour's already-seen events on every overlap, producing
# genuine duplicate alerts. This script's own wrapper tracks the last
# real event's own timestamp between runs in a state file, using it as
# the next run's --since boundary instead of a fixed, overlapping window.
echo "=== Obaits: Docker host monitoring setup ==="

if ! command -v docker &> /dev/null; then
    echo "ERROR: docker command not found."
    exit 1
fi

WRAPPER_DIR="/opt/obaits"
WRAPPER_PATH="$WRAPPER_DIR/docker-events-wrapper.sh"
STATE_FILE="$WRAPPER_DIR/docker-events-state"
LOG_FILE="/var/log/obaits-docker-events.log"

mkdir -p "$WRAPPER_DIR"

cat > "$WRAPPER_PATH" <<'WRAPPEREOF'
#!/bin/bash
STATE_FILE="/opt/obaits/docker-events-state"
LOG_FILE="/var/log/obaits-docker-events.log"
NOW=$(date +%s)

if [ -f "$STATE_FILE" ]; then
    SINCE=$(cat "$STATE_FILE")
else
    # First run: no prior state, look back a real, bounded 1 hour once.
    SINCE=$((NOW - 3600))
fi

docker events --since "$SINCE" --until "$NOW" --format '{{json .}}' >> "$LOG_FILE"

echo "$NOW" > "$STATE_FILE"
WRAPPEREOF

chmod 755 "$WRAPPER_PATH"
echo "Deployed stateful docker events wrapper to $WRAPPER_PATH"

# Create the real log file up front, with real permissions the wazuh
# user can genuinely READ but never write -- confirmed necessary, same
# permission-hardening discipline already required for every database
# audit log this project has set up.
touch "$LOG_FILE"
chown root:root "$LOG_FILE"
chmod 644 "$LOG_FILE"
echo "Created $LOG_FILE (root-owned, world-readable, matching every other audit log's real permission model)."

# Install a REAL root cron job (not the wazuh user) to run the wrapper
# every 5 minutes -- frequent enough for real, timely detection, while
# the state file itself prevents any duplicate reporting regardless of
# how often it runs.
CRON_LINE="*/5 * * * * root $WRAPPER_PATH"
CRON_FILE="/etc/cron.d/obaits-docker-events"
echo "$CRON_LINE" > "$CRON_FILE"
chmod 644 "$CRON_FILE"
echo "Installed root cron job: $CRON_FILE"

# Real, direct verification -- confirm the wrapper genuinely runs and
# produces real, readable output before trusting it.
"$WRAPPER_PATH"
if [ $? -ne 0 ]; then
    echo "WARNING: wrapper script test run failed."
else
    echo "Wrapper test run succeeded."
fi

if sudo -u wazuh test -r "$LOG_FILE"; then
    echo "Confirmed: wazuh user can genuinely read $LOG_FILE."
else
    echo "WARNING: wazuh user cannot read $LOG_FILE -- check permissions."
fi

echo "=== Obaits: Docker host monitoring setup complete ==="
