#!/bin/bash
# Real Website Block revert -- Linux. Removes THIS assignment's own
# marker block entirely -- safe by construction, since remediate.sh
# only ever writes domains marked false (WE added them) into it. Any
# domain that was already blocked before us (true, or by baseline
# protection/anything else) was never written into this block at all,
# so it is naturally, correctly left untouched.
HOSTNAME_ACTUAL="$(hostname)"

PARAMS_JSON=$(curl -sS "$U/enroll/policy-params?run_id=$RUN_ID")
DOMAINS=$(echo "$PARAMS_JSON" | grep -oE '"[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}"' | tr -d '"')

HOSTS_FILE="/etc/hosts"
BEGIN_MARKER="# OBAITS-WEBSITE-BLOCK-BEGIN-${RUN_ID}"
END_MARKER="# OBAITS-WEBSITE-BLOCK-END-${RUN_ID}"

REMOVED_COUNT=$(sudo sed -n "/^${BEGIN_MARKER}$/,/^${END_MARKER}$/p" "$HOSTS_FILE" | grep -c "^0.0.0.0")
sudo sed -i "/^${BEGIN_MARKER}$/,/^${END_MARKER}$/d" "$HOSTS_FILE"

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
STATE="domains_unblocked:${UNBLOCKED_COUNT}|${JSON_PARTS}"

curl -sS -X POST "$U/enroll/policy-result" -d "policy_run_id=$RUN_ID" -d "hostname=$HOSTNAME_ACTUAL" --data-urlencode "result=$STATE" > /dev/null
echo "Website block revert: removed ${REMOVED_COUNT} domain(s) we added, left any pre-existing/baseline-protected domains untouched"
