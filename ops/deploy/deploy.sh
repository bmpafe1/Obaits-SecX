#!/bin/bash
# Obaits deployment.
#
# WHY THIS EXISTS: the correct deploy sequence is four commands with real
# ordering constraints, and forgetting any one produces a SILENT failure --
# something that looks deployed, reports success, and does not take effect.
# That happened twice in a single day on 2026-08-25/26:
#
#   1. A fix to a queued job was deployed and the queue worker kept running the
#      OLD code from memory. Two hours of debugging before anyone thought to
#      restart the worker.
#
#   2. Config caching was enabled, cutting local TTFB from 791ms to 52ms -- a
#      fifteen-fold improvement. But every later `config:clear` silently
#      reverted it, and nothing was broken, only slow, so nobody noticed.
#
# Run from the deploy directory on the VPS:  ./ops/deploy/deploy.sh
set -euo pipefail

cd "$(dirname "$0")/../.."
ROOT="$(pwd)"
echo "Deploying in $ROOT"
echo

# --- 1. Code -----------------------------------------------------------------
echo "1/5  Pulling..."
BEFORE="$(git rev-parse --short HEAD)"
git pull --quiet
AFTER="$(git rev-parse --short HEAD)"
if [ "$BEFORE" = "$AFTER" ]; then
  echo "     already at $AFTER (no new commits)"
else
  echo "     $BEFORE -> $AFTER"
fi

# --- 2. Dependencies ---------------------------------------------------------
# Only when composer.lock actually changed -- composer install is slow and
# almost never needed.
if git diff --name-only "$BEFORE" "$AFTER" 2>/dev/null | grep -q '^composer.lock$'; then
  echo "2/5  composer.lock changed, installing..."
  composer install --no-dev --optimize-autoloader --no-interaction --quiet
else
  echo "2/5  composer.lock unchanged, skipping install"
fi

# --- 3. Database -------------------------------------------------------------
echo "3/5  Migrating..."
php artisan migrate --force --no-interaction

# --- 4. Caches ---------------------------------------------------------------
# ORDER MATTERS. Clear first, then rebuild -- caching stale artefacts is worse
# than not caching at all.
#
# config:cache is what took local TTFB from 791ms to 52ms. Without it Laravel
# re-parses every config file, rescans for event listeners and rebuilds the
# route table on EVERY request, and this project has an 800-line route file.
#
# NOTE once config is cached, .env changes DO NOT take effect until this runs
# again. Anything editing .env must re-run this script or `php artisan
# config:cache` by hand.
echo "4/5  Rebuilding caches..."
php artisan config:clear  --quiet
php artisan route:clear   --quiet
php artisan view:clear    --quiet
php artisan event:clear   --quiet 2>/dev/null || true

php artisan config:cache  --quiet
php artisan route:cache   --quiet
php artisan event:cache   --quiet
echo "     config, routes, events cached; views cleared (rebuilt on demand)"

# --- 5. Queue worker ---------------------------------------------------------
# A running worker holds the OLD code in memory. git pull does not affect it.
# Any change to a queued job is invisible until the worker recycles.
echo "5/5  Restarting queue worker..."
if systemctl is-active --quiet obaits-queue; then
  sudo systemctl restart obaits-queue
  sleep 2
  if systemctl is-active --quiet obaits-queue; then
    echo "     obaits-queue restarted"
  else
    echo "     WARNING: obaits-queue did NOT come back up" >&2
    exit 1
  fi
else
  echo "     WARNING: obaits-queue was not running before deploy" >&2
fi

# --- Verify ------------------------------------------------------------------
echo
echo "Verifying..."
# REAL BUG 2026-08-29: this measured http://127.0.0.1:8092, which is a
# lightNVR instance, NOT the application. It reported 1.4ms and looked like
# excellent performance while testing entirely the wrong service. The app is
# behind nginx, so measure it there.
APP_URL="https://app.obaits.com"
RESOLVE="--resolve app.obaits.com:443:127.0.0.1"

TTFB="$(curl -sS $RESOLVE -o /dev/null -w '%{time_starttransfer}' "$APP_URL/login" 2>/dev/null || echo 'n/a')"
echo "  TTFB:       ${TTFB}s   (expect under 0.3s; ~1s means config is not cached)"

# Smoke check. A 500 on any of these means something is fatally broken --
# catching it here is far better than a customer finding it. These are
# unauthenticated requests, so 302 (redirect to login) is the healthy answer for
# portal routes; only 5xx is a failure.
echo
echo "Smoke check:"
FAILED=0
for path in /login /portal/marketplace/providers /portal/marketplace/services /portal/trainings/awareness /dashboard /operator/analytics; do
  CODE="$(curl -sS $RESOLVE -o /dev/null -w '%{http_code}' "$APP_URL$path" 2>/dev/null || echo '000')"
  if [ "${CODE:0:1}" = "5" ] || [ "$CODE" = "000" ]; then
    echo "  $CODE  $path   <-- FAILING"
    FAILED=1
  else
    echo "  $CODE  $path"
  fi
done
[ "$FAILED" = "1" ] && echo && echo "One or more routes are returning a server error." >&2

echo
echo "  commit:     $(git rev-parse --short HEAD)"
echo
echo "Deployed."
