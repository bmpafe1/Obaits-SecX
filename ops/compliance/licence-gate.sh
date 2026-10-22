#!/bin/bash
# Fails if commercially-licensed upstream code enters the repository.
#
# Guideline release gates 4 and 5: Fleet Free only (no ee/ or Premium), and
# CISO Assistant Community only (no enterprise/). Both were clean when audited
# on 2026-08-24 -- this makes that a control rather than a matter of practice.
#
# Run manually, from a pre-commit hook, or from CI. Exit 1 blocks.
set -u
FAIL=0

check() {
  local label="$1" pattern="$2"
  local hits
  hits=$(grep -rniE "$pattern" app/ config/ routes/ resources/ ops/ database/ \
           --include=*.php --include=*.blade.php --include=*.sh --include=*.ps1 \
           --include=*.xml --include=*.yml --include=*.yaml 2>/dev/null \
         | grep -vE '^\s*//|^\s*#|licence-gate|COMPONENT-MANIFEST' || true)
  if [ -n "$hits" ]; then
    echo "BLOCKED: $label"
    echo "$hits" | head -10
    FAIL=1
  fi
}

check "Fleet ee/ or Premium code" '(fleet.*\bee/|/ee/|fleet.*premium)'
check "CISO Assistant enterprise/ code" '(ciso.*enterprise/|/enterprise/)'

if [ "$FAIL" -eq 0 ]; then
  echo "licence gate: clean"
fi
exit "$FAIL"
