#!/bin/bash
# Real Website Block remediate -- Linux. Reads PRIOR_STATE (the frozen
# per-domain JSON from this device's own first check) -- only adds a
# domain if prior_state says it was NOT already blocked (false). Writes
# into a marker block scoped to THIS assignment's own $RUN_ID -- stable
# for the lifetime of this (assignment, device) pair (PolicyRunner
# reuses the same run row), so this policy's own additions stay cleanly
# separable from baseline threat-intel protection's own marker block
# and from any other Website Block assignment on the same device.
HOSTNAME_ACTUAL="$(hostname)"

PARAMS_JSON=$(curl -sS "$U/enroll/policy-params?run_id=$RUN_ID")
DOMAINS=$(echo "$PARAMS_JSON" | grep -oE '"[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}"' | tr -d '"')

PRIOR_JSON="${PRIOR_STATE#*|}"

TO_ADD=""
for domain in $DOMAINS; do
  WAS_BLOCKED=$(echo "$PRIOR_JSON" | grep -oE "\"${domain//./\\.}\":(true|false)" | cut -d: -f2)
  WAS_BLOCKED="${WAS_BLOCKED:-false}"
  if [ "$WAS_BLOCKED" = "false" ]; then
    TO_ADD="${TO_ADD}${domain}
"
  fi
done

HOSTS_FILE="/etc/hosts"
BEGIN_MARKER="# OBAITS-WEBSITE-BLOCK-BEGIN-${RUN_ID}"
END_MARKER="# OBAITS-WEBSITE-BLOCK-END-${RUN_ID}"

# Real, safe rebuild: strip any PRIOR block for THIS run id only (never
# touches baseline protection's own block, or any other assignment's),
# then append one fresh block built from exactly the domains we own.
sudo sed -i "/^${BEGIN_MARKER}$/,/^${END_MARKER}$/d" "$HOSTS_FILE"

if [ -n "$TO_ADD" ]; then
  {
    echo "$BEGIN_MARKER"
    echo "$TO_ADD" | while read -r domain; do
      [ -n "$domain" ] && echo "0.0.0.0 $domain"
    done
    echo "$END_MARKER"
  } | sudo tee -a "$HOSTS_FILE" > /dev/null
fi

ADDED_COUNT=$(echo "$TO_ADD" | grep -c .)

# Real, current state AFTER remediation -- built fresh, same as check.sh.
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
echo "Website block remediate: added ${ADDED_COUNT} new domain(s), ${UNBLOCKED_COUNT} still unblocked, result=${STATE}"
