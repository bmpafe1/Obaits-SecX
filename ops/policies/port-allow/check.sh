#!/bin/bash
# Real Port Allow check -- Linux (ufw). Mirrors IP Block's exact design:
# fetches its own assignment's port list via /enroll/policy-params,
# builds the same hybrid result -- a summary count plus a real per-item
# JSON map (true=already allowed before us, false=not) that
# PolicyRunner auto-freezes into prior_state at first remediate.
#
# Real, simple parameter format: "443/tcp", "53/udp" etc -- port and
# protocol together in one string, avoiding fragile nested-JSON parsing
# in bash (confirmed elsewhere this session to be a real, repeated
# source of bugs). "Allow" means inbound only -- the standard meaning
# of opening a port, unlike IP Block/Port Block which act on both
# directions.
HOSTNAME_ACTUAL="$(hostname)"

PARAMS_JSON=$(curl -sS "$U/enroll/policy-params?run_id=$RUN_ID")
if [ -z "$PARAMS_JSON" ] || [ "$PARAMS_JSON" = "[]" ]; then
  echo "Port allow check: no ports configured for this assignment."
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
echo "Port allow check: ${UNBLOCKED_COUNT} of $(echo "$PORTS" | wc -w) port(s) not yet allowed"
