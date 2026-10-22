#!/bin/bash
# Real IP Block check -- Linux (ufw). First policy with genuine
# tenant-supplied input: fetches its own assignment's IP list via
# /enroll/policy-params (trusted by RUN_ID alone -- never interpolated
# into this trigger, see PolicyRunner's own comments for the real
# injection-risk reasoning).
#
# REAL DESIGN, confirmed with Brian: per-IP prior-state tracking, not
# just a count. This script's own `result` carries BOTH a summary count
# (for the same prefix-matching every other policy already uses) AND a
# real per-IP JSON map of true (already blocked before us) / false (not
# blocked). PolicyRunner already auto-freezes whatever `result` contains
# into prior_state at first remediate -- zero PolicyRunner/schema changes
# needed; this is real per-IP accuracy fitting inside the existing
# mechanism.
HOSTNAME_ACTUAL="$(hostname)"

PARAMS_JSON=$(curl -sS "$U/enroll/policy-params?run_id=$RUN_ID")
if [ -z "$PARAMS_JSON" ] || [ "$PARAMS_JSON" = "[]" ]; then
  echo "IP block check: no IPs configured for this assignment."
  curl -sS -X POST "$U/enroll/policy-result" -d "policy_run_id=$RUN_ID" -d "hostname=$HOSTNAME_ACTUAL" -d "result=up_to_date" > /dev/null
  exit 0
fi

# Real, minimal JSON array parsing -- no jq dependency assumed present
# on every real device (confirmed elsewhere in this project not to be a
# safe assumption). Extracts each quoted string between [ and ].
IPS=$(echo "$PARAMS_JSON" | grep -oE '"[0-9]{1,3}(\.[0-9]{1,3}){3}(/[0-9]{1,2})?"' | tr -d '"')

UFW_STATUS=$(sudo ufw status)
UNBLOCKED_COUNT=0
JSON_PARTS=""

for ip in $IPS; do
  if echo "$UFW_STATUS" | grep "DENY" | grep -qF "$ip"; then
    ALREADY_BLOCKED="true"
  else
    ALREADY_BLOCKED="false"
    UNBLOCKED_COUNT=$((UNBLOCKED_COUNT + 1))
  fi
  JSON_PARTS="${JSON_PARTS}\"${ip}\":${ALREADY_BLOCKED},"
done
JSON_PARTS="{${JSON_PARTS%,}}"

if [ "$UNBLOCKED_COUNT" -gt 0 ]; then
  STATE="ips_unblocked:${UNBLOCKED_COUNT}|${JSON_PARTS}"
else
  STATE="up_to_date|${JSON_PARTS}"
fi

curl -sS -X POST "$U/enroll/policy-result" -d "policy_run_id=$RUN_ID" -d "hostname=$HOSTNAME_ACTUAL" --data-urlencode "result=$STATE" > /dev/null
echo "IP block check: ${UNBLOCKED_COUNT} of $(echo "$IPS" | wc -w) IP(s) not yet blocked"
