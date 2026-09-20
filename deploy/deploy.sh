#!/usr/bin/env bash
# GoodTechies HQ: release script. Run as root on every release:
#
#   cd /var/www/goodtechies-hq && deploy/deploy.sh
#
# Environment:
#   APP_DIR    application directory (default: the parent of this script's directory)
#   SKIP_PULL  1 = deploy the code that is already checked out (used for rollback)
#
# Migrations are forward-only. A failed release leaves maintenance mode off again,
# but a schema change is rolled back only by restoring a backup (docs/runbooks/deploy.md).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(dirname "$SCRIPT_DIR")}"
SKIP_PULL="${SKIP_PULL:-0}"
APP_USER="www-data"
PHP_FPM_SERVICE="php8.3-fpm"

export COMPOSER_ALLOW_SUPERUSER=1
export DEBIAN_FRONTEND=noninteractive

step() {
    echo "==> $*"
}

die() {
    echo "ERROR: $*" >&2
    exit 1
}

# Run a service action with systemd, or with `service` where systemd is absent (containers).
svc() {
    local action="$1" name="$2"
    if [ -d /run/systemd/system ]; then
        systemctl "$action" "$name"
    else
        service "$name" "$action"
    fi
}

artisan() {
    php "$APP_DIR/artisan" "$@"
}

fix_ownership() {
    chown -R "$APP_USER:$APP_USER" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
}

WENT_DOWN=0
on_exit() {
    local status=$?
    if [ "$status" -ne 0 ]; then
        echo "ERROR: deploy failed (exit $status)." >&2
        if [ "$WENT_DOWN" -eq 1 ]; then
            echo "==> bringing the application back up after the failure" >&2
            artisan up || true
        fi
        fix_ownership || true
    fi
}
trap on_exit EXIT

[ "$(id -u)" -eq 0 ] || die "run deploy.sh as root"
[ -f "$APP_DIR/artisan" ] || die "no Laravel app in $APP_DIR (set APP_DIR)"
[ -f "$APP_DIR/.env" ] || die "$APP_DIR/.env is missing (run install.sh first)"
cd "$APP_DIR"

step "maintenance mode on"
if [ -f "$APP_DIR/vendor/autoload.php" ]; then
    artisan down --retry=15
    WENT_DOWN=1
else
    echo "no vendor/ yet (first install), skipping"
fi

step "update code"
if [ "$SKIP_PULL" = "1" ]; then
    echo "SKIP_PULL=1, deploying the checked-out commit"
else
    git -C "$APP_DIR" pull --ff-only
fi

step "composer install"
composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$APP_DIR"

step "build frontend assets"
npm ci --include=dev --no-audit --no-fund
npm run build

step "migrate (pgsql_migrator)"
artisan migrate --force --database=pgsql_migrator

step "cache config, routes and views"
artisan config:cache
artisan route:cache
artisan view:cache

step "fix ownership of storage and bootstrap/cache"
fix_ownership

step "restart queue workers"
artisan queue:restart

step "restart Reverb if installed"
commands="$(artisan list --raw)"
if grep -q '^reverb:start' <<<"$commands"; then
    supervisorctl restart hq-reverb
else
    echo "reverb:start not available (Reverb arrives in Phase 6), skipping"
fi

step "reload $PHP_FPM_SERVICE"
svc reload "$PHP_FPM_SERVICE"

step "maintenance mode off"
artisan up
WENT_DOWN=0

step "deployed $(git -C "$APP_DIR" log -1 --format='%h %s (%ci)')"
