#!/bin/bash
# Real Port Allow remediate -- Linux (ufw). Reads PRIOR_STATE, only
# allows a port if it was NOT already allowed (false). Mirrors IP
# Block's exact per-item prior-state logic.
HOSTNAME_ACTUAL="$(hostname)"

PARAMS_JSON=$(curl -sS "$U/enroll/policy-params?run_id=$RUN_ID")
# REAL BUG FOUND 2026-08-17, confirmed live: Laravel's json_encode()
# escapes forward slashes as \/ by default in its own JSON output --
# confirmed directly: /enroll/policy-params genuinely returned
# ["54321\/tcp"], not ["54321/tcp"] as assumed. The original regex had
# no backslash tolerance and matched zero real entries. Fixed to accept
# an optional backslash before the slash, and strip any that appear.
PORTS=$(echo "$PARAMS_JSON" | grep -oE '"[0-9]{1,5}\\?/(tcp|udp)"' | tr -d '"\\')

PRIOR_JSON="${PRIOR_STATE#*|}"

ALLOWED_NOW=0
for port in $PORTS; do
  WAS_ALLOWED=$(echo "$PRIOR_JSON" | grep -oE "\"${port}\":(true|false)" | cut -d: -f2)
  WAS_ALLOWED="${WAS_ALLOWED:-false}"
  if [ "$WAS_ALLOWED" = "false" ]; then
    sudo ufw allow "$port" > /dev/null 2>&1
    ALLOWED_NOW=$((ALLOWED_NOW + 1))
  fi
done

UFW_STATUS=$(sudo ufw status)
UNBLOCKED_COUNT=0
JSON_PARTS=""
for port in $PORTS; do
  if echo "$UFW_STATUS" | grep -E "^${port}[[:space:]]" | grep -q "ALLOW"; then
    ALREADY_ALLOWED="true"
  else
    ALREADY_ALLOWED="false"
    UNBLOCKED_COUNT=$((UNBLOCKED_COUNT + 1))
  fi
  JSON_PARTS="${JSON_PARTS}\"${port}\":${ALREADY_ALLOWED},"
done
JSON_PARTS="{${JSON_PARTS%,}}"

if [ "$UNBLOCKED_COUNT" -gt 0 ]; then
  STATE="ports_unallowed:${UNBLOCKED_COUNT}|${JSON_PARTS}"
else
  STATE="up_to_date|${JSON_PARTS}"
fi

curl -sS -X POST "$U/enroll/policy-result" -d "policy_run_id=$RUN_ID" -d "hostname=$HOSTNAME_ACTUAL" --data-urlencode "result=$STATE" > /dev/null
echo "Port allow remediate: allowed ${ALLOWED_NOW} new port(s), ${UNBLOCKED_COUNT} still unallowed, result=${STATE}"
