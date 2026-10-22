#!/bin/bash
# Real Website Block check -- Linux. Mirrors IP Block's exact design
# (ops/policies/ip-block/check.sh): fetches its own assignment's domain
# list via /enroll/policy-params, builds the same hybrid result -- a
# summary count plus a real per-domain JSON map (true=already blocked
# before us, false=not) that PolicyRunner auto-freezes into prior_state
# at first remediate.
#
# "Already blocked" checks the WHOLE /etc/hosts file, not just any
# marker block of our own -- this correctly treats a domain already
# blocked by baseline threat-intel protection (or anything else) as
# "true", matching IP Block's exact "leave what we didn't add alone"
# philosophy.
HOSTNAME_ACTUAL="$(hostname)"

PARAMS_JSON=$(curl -sS "$U/enroll/policy-params?run_id=$RUN_ID")
if [ -z "$PARAMS_JSON" ] || [ "$PARAMS_JSON" = "[]" ]; then
  echo "Website block check: no domains configured for this assignment."
  curl -sS -X POST "$U/enroll/policy-result" -d "policy_run_id=$RUN_ID" -d "hostname=$HOSTNAME_ACTUAL" -d "result=up_to_date" > /dev/null
  exit 0
fi

DOMAINS=$(echo "$PARAMS_JSON" | grep -oE '"[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}"' | tr -d '"')

UNBLOCKED_COUNT=0
JSON_PARTS=""
for domain in $DOMAINS; do
  if grep -qE "^[0-9.]+[[:space:]]+${domain//./\\.}$" /etc/hosts; then
    ALREADY_BLOCKED="true"
  else
    ALREADY_BLOCKED="false"
    UNBLOCKED_COUNT=$((UNBLOCKED_COUNT + 1))
  fi
  JSON_PARTS="${JSON_PARTS}\"${domain}\":${ALREADY_BLOCKED},"
done
JSON_PARTS="{${JSON_PARTS%,}}"

if [ "$UNBLOCKED_COUNT" -gt 0 ]; then
  STATE="domains_unblocked:${UNBLOCKED_COUNT}|${JSON_PARTS}"
else
  STATE="up_to_date|${JSON_PARTS}"
fi

curl -sS -X POST "$U/enroll/policy-result" -d "policy_run_id=$RUN_ID" -d "hostname=$HOSTNAME_ACTUAL" --data-urlencode "result=$STATE" > /dev/null
echo "Website block check: ${UNBLOCKED_COUNT} of $(echo "$DOMAINS" | wc -w) domain(s) not yet blocked"
