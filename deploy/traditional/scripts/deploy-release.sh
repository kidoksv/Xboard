#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/xboard/current}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
SERVICE_PREFIX="${SERVICE_PREFIX:-xboard}"
RESTART_SERVICES="${RESTART_SERVICES:-true}"
SYSTEMCTL_BIN="${SYSTEMCTL_BIN:-systemctl}"
KEEP_MAINTENANCE_ON_FAILURE="${KEEP_MAINTENANCE_ON_FAILURE:-false}"

cd "$APP_DIR"

if [ ! -f artisan ]; then
  echo "artisan not found in $APP_DIR" >&2
  exit 1
fi

cleanup() {
  status=$?
  if [ "$status" -ne 0 ] && [ "$KEEP_MAINTENANCE_ON_FAILURE" != "true" ]; then
    "$PHP_BIN" artisan up || true
  fi
  exit "$status"
}
trap cleanup EXIT

"$PHP_BIN" artisan down --retry=60 || true

"$COMPOSER_BIN" install \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --optimize-autoloader

"$PHP_BIN" artisan xboard:update
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan event:cache
"$PHP_BIN" artisan storage:link || true

chown -R www-data:www-data storage bootstrap/cache public/theme plugins 2>/dev/null || true
chmod -R ug+rwX storage bootstrap/cache public/theme plugins 2>/dev/null || true

if [ "$RESTART_SERVICES" = "true" ] && command -v systemctl >/dev/null 2>&1; then
  $SYSTEMCTL_BIN restart "${SERVICE_PREFIX}-octane"
  $SYSTEMCTL_BIN restart "${SERVICE_PREFIX}-horizon"
  $SYSTEMCTL_BIN restart "${SERVICE_PREFIX}-ws"
fi

"$PHP_BIN" artisan up

echo "Xboard release deployed successfully."
