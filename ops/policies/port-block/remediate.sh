#!/bin/bash
# Real Port Block remediate -- Linux (ufw). Blocks BOTH directions for
# each port marked false in PRIOR_STATE (WE own it).
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

BLOCKED_NOW=0
for port in $PORTS; do
  WAS_BLOCKED=$(echo "$PRIOR_JSON" | grep -oE "\"${port}\":(true|false)" | cut -d: -f2)
  WAS_BLOCKED="${WAS_BLOCKED:-false}"
  if [ "$WAS_BLOCKED" = "false" ]; then
    sudo ufw deny "$port" > /dev/null 2>&1
    BLOCKED_NOW=$((BLOCKED_NOW + 1))
  fi
done

UFW_STATUS=$(sudo ufw status)
UNBLOCKED_COUNT=0
JSON_PARTS=""
for port in $PORTS; do
  if echo "$UFW_STATUS" | grep -E "^${port}[[:space:]]" | grep -q "DENY"; then
    ALREADY_BLOCKED="true"
  else
    ALREADY_BLOCKED="false"
    UNBLOCKED_COUNT=$((UNBLOCKED_COUNT + 1))
  fi
  JSON_PARTS="${JSON_PARTS}\"${port}\":${ALREADY_BLOCKED},"
done
JSON_PARTS="{${JSON_PARTS%,}}"

if [ "$UNBLOCKED_COUNT" -gt 0 ]; then
  STATE="ports_unblocked:${UNBLOCKED_COUNT}|${JSON_PARTS}"
else
  STATE="up_to_date|${JSON_PARTS}"
fi

curl -sS -X POST "$U/enroll/policy-result" -d "policy_run_id=$RUN_ID" -d "hostname=$HOSTNAME_ACTUAL" --data-urlencode "result=$STATE" > /dev/null
echo "Port block remediate: blocked ${BLOCKED_NOW} new port(s), ${UNBLOCKED_COUNT} still unblocked, result=${STATE}"
