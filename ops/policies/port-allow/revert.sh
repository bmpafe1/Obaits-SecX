#!/bin/bash
# Real Port Allow revert -- Linux (ufw). Removes allow rules ONLY for
# ports where PRIOR_STATE genuinely recorded false (WE added it).
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

REVERTED=0
for port in $PORTS; do
  WAS_ALLOWED=$(echo "$PRIOR_JSON" | grep -oE "\"${port}\":(true|false)" | cut -d: -f2)
  if [ "$WAS_ALLOWED" = "false" ]; then
    sudo ufw delete allow "$port" > /dev/null 2>&1
    REVERTED=$((REVERTED + 1))
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
STATE="ports_unallowed:${UNBLOCKED_COUNT}|${JSON_PARTS}"

curl -sS -X POST "$U/enroll/policy-result" -d "policy_run_id=$RUN_ID" -d "hostname=$HOSTNAME_ACTUAL" --data-urlencode "result=$STATE" > /dev/null
echo "Port allow revert: removed ${REVERTED} rule(s) we added, left any pre-existing allows untouched"
