#!/bin/bash
# Real IP Block revert -- Linux (ufw). Removes deny rules ONLY for IPs
# where PRIOR_STATE genuinely recorded false (WE added the block). Any
# IP recorded true (already blocked before us) or with no record at all
# is left untouched -- conservative by design, never removes a rule we
# have no proof we added ourselves.
HOSTNAME_ACTUAL="$(hostname)"

PARAMS_JSON=$(curl -sS "$U/enroll/policy-params?run_id=$RUN_ID")
IPS=$(echo "$PARAMS_JSON" | grep -oE '"[0-9]{1,3}(\.[0-9]{1,3}){3}(/[0-9]{1,2})?"' | tr -d '"')
PRIOR_JSON="${PRIOR_STATE#*|}"

REVERTED=0
for ip in $IPS; do
  WAS_BLOCKED=$(echo "$PRIOR_JSON" | grep -oE "\"${ip//./\\.}\":(true|false)" | cut -d: -f2)
  if [ "$WAS_BLOCKED" = "false" ]; then
    sudo ufw delete deny from "$ip" > /dev/null 2>&1
    REVERTED=$((REVERTED + 1))
  fi
  # WAS_BLOCKED = "true" or empty/unknown -> deliberately left alone
done

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
STATE="ips_unblocked:${UNBLOCKED_COUNT}|${JSON_PARTS}"

curl -sS -X POST "$U/enroll/policy-result" -d "policy_run_id=$RUN_ID" -d "hostname=$HOSTNAME_ACTUAL" --data-urlencode "result=$STATE" > /dev/null
echo "IP block revert: removed ${REVERTED} rule(s) we added, left any pre-existing/unknown blocks untouched"
