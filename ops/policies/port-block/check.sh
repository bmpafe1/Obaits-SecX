#!/bin/bash
# Real Port Block check -- Linux (ufw). Mirrors Port Allow's exact
# design, but "block" acts on BOTH directions (matching IP Block's own
# dual-direction default) -- a blocked port should be genuinely unusable,
# not just closed to inbound connections.
HOSTNAME_ACTUAL="$(hostname)"

PARAMS_JSON=$(curl -sS "$U/enroll/policy-params?run_id=$RUN_ID")
if [ -z "$PARAMS_JSON" ] || [ "$PARAMS_JSON" = "[]" ]; then
  echo "Port block check: no ports configured for this assignment."
  curl -sS -X POST "$U/enroll/policy-result" -d "policy_run_id=$RUN_ID" -d "hostname=$HOSTNAME_ACTUAL" -d "result=up_to_date" > /dev/null
  exit 0
fi

# REAL BUG FOUND 2026-08-17, confirmed live: Laravel's json_encode()
# escapes forward slashes as \/ by default in its own JSON output --
# confirmed directly: /enroll/policy-params genuinely returned
# ["54321\/tcp"], not ["54321/tcp"] as assumed. The original regex had
# no backslash tolerance and matched zero real entries. Fixed to accept
# an optional backslash before the slash, and strip any that appear.
PORTS=$(echo "$PARAMS_JSON" | grep -oE '"[0-9]{1,5}\\?/(tcp|udp)"' | tr -d '"\\')

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
echo "Port block check: ${UNBLOCKED_COUNT} of $(echo "$PORTS" | wc -w) port(s) not yet blocked"
