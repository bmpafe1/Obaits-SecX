#!/bin/bash
# Real IP Block remediate -- Linux (ufw). Reads PRIOR_STATE, the exact
# per-IP JSON this policy's own check.sh reported the first time this
# device was ever remediated (PolicyRunner auto-freezes this, unchanged,
# from that point forward -- see check.sh's own comments). Only blocks
# an IP if prior_state says it was NOT already blocked (false) -- an IP
# that was already blocked by something unrelated to this policy is
# left completely untouched, both here and on revert.
HOSTNAME_ACTUAL="$(hostname)"

PARAMS_JSON=$(curl -sS "$U/enroll/policy-params?run_id=$RUN_ID")
IPS=$(echo "$PARAMS_JSON" | grep -oE '"[0-9]{1,3}(\.[0-9]{1,3}){3}(/[0-9]{1,2})?"' | tr -d '"')

# PRIOR_STATE looks like "ips_unblocked:2|{...}" or "up_to_date|{...}" --
# the real per-IP JSON is everything after the first "|".
PRIOR_JSON="${PRIOR_STATE#*|}"

BLOCKED_NOW=0
for ip in $IPS; do
  # Extract this IP's real prior value from the frozen JSON -- default
  # to "false" (never seen before, e.g. an IP added to the list AFTER
  # the first remediate) so a genuinely new IP still gets blocked.
  WAS_BLOCKED=$(echo "$PRIOR_JSON" | grep -oE "\"${ip//./\\.}\":(true|false)" | cut -d: -f2)
  WAS_BLOCKED="${WAS_BLOCKED:-false}"

  if [ "$WAS_BLOCKED" = "false" ]; then
    sudo ufw insert 1 deny from "$ip" > /dev/null 2>&1
    BLOCKED_NOW=$((BLOCKED_NOW + 1))
  fi
done

# Real, current state AFTER remediation -- built fresh, same logic as
# check.sh, since $result should always reflect live reality, never the
# frozen prior_state snapshot.
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
echo "IP block remediate: blocked ${BLOCKED_NOW} new IP(s), ${UNBLOCKED_COUNT} still unblocked, result=${STATE}"
