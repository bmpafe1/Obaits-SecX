#!/bin/bash
HOSTNAME_ACTUAL="$(hostname)"
BEFORE="firewall_unknown"
if command -v ufw >/dev/null 2>&1; then
  if ufw status | head -1 | grep -qiE '^status: active'; then BEFORE=firewall_enabled; else BEFORE=firewall_disabled; fi
  if [ "$BEFORE" = "firewall_disabled" ]; then
    ufw --force enable >/dev/null 2>&1
  fi
elif command -v firewall-cmd >/dev/null 2>&1; then
  if firewall-cmd --state 2>/dev/null | grep -qiE '^running$'; then BEFORE=firewall_enabled; else BEFORE=firewall_disabled; fi
  if [ "$BEFORE" = "firewall_disabled" ]; then
    systemctl enable --now firewalld >/dev/null 2>&1
  fi
else
  BEFORE=firewall_unknown
fi
AFTER="firewall_unknown"
if command -v ufw >/dev/null 2>&1; then
  if ufw status | head -1 | grep -qiE '^status: active'; then AFTER=firewall_enabled; else AFTER=firewall_disabled; fi
elif command -v firewall-cmd >/dev/null 2>&1; then
  if firewall-cmd --state 2>/dev/null | grep -qiE '^running$'; then AFTER=firewall_enabled; else AFTER=firewall_disabled; fi
fi
curl -sS -X POST "$U/enroll/policy-result" -d "policy_run_id=$RUN_ID" -d "hostname=$HOSTNAME_ACTUAL" -d "result=$AFTER" -d "prior_state=$BEFORE" > /dev/null
echo "Firewall remediate: before=$BEFORE after=$AFTER"
