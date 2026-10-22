#!/bin/bash
HOSTNAME_ACTUAL="$(hostname)"
if command -v ufw >/dev/null 2>&1; then
  if ufw status | head -1 | grep -qiE '^status: active'; then STATE=firewall_enabled; else STATE=firewall_disabled; fi
elif command -v firewall-cmd >/dev/null 2>&1; then
  if firewall-cmd --state 2>/dev/null | grep -qiE '^running$'; then STATE=firewall_enabled; else STATE=firewall_disabled; fi
else
  STATE=firewall_unknown
fi
curl -sS -X POST "$U/enroll/policy-result" -d "policy_run_id=$RUN_ID" -d "hostname=$HOSTNAME_ACTUAL" -d "result=$STATE" > /dev/null
echo "Firewall check: $STATE"
