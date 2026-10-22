#!/bin/bash
HOSTNAME_ACTUAL="$(hostname)"
# PRIOR_STATE is injected by PolicyRunner from this run's own stored
# prior_state -- only ever turn the firewall OFF if WE were the one who
# turned it on. A device that already had it on before we touched it
# must never be reverted to off; that would be actively unsafe, not
# neutral, and is precisely why revert is not a blind inverse script.
if [ "$PRIOR_STATE" = "firewall_disabled" ]; then
  if command -v ufw >/dev/null 2>&1; then
    ufw --force disable >/dev/null 2>&1
  elif command -v firewall-cmd >/dev/null 2>&1; then
    systemctl disable --now firewalld >/dev/null 2>&1
  fi
fi
AFTER="firewall_unknown"
if command -v ufw >/dev/null 2>&1; then
  if ufw status | head -1 | grep -qiE '^status: active'; then AFTER=firewall_enabled; else AFTER=firewall_disabled; fi
elif command -v firewall-cmd >/dev/null 2>&1; then
  if firewall-cmd --state 2>/dev/null | grep -qiE '^running$'; then AFTER=firewall_enabled; else AFTER=firewall_disabled; fi
fi
curl -sS -X POST "$U/enroll/policy-result" -d "policy_run_id=$RUN_ID" -d "hostname=$HOSTNAME_ACTUAL" -d "result=$AFTER" > /dev/null
echo "Firewall revert: prior_state=$PRIOR_STATE after=$AFTER"
