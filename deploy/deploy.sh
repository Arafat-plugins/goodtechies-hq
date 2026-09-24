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
#
# Realtime: this script starts, restarts or stops hq-reverb to match BROADCAST_CONNECTION in
# .env, and `npm run build` bakes VITE_REALTIME into the assets — so both halves of the switch
# are applied by a release and by nothing else. See docs/runbooks/realtime.md.
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

step "Reverb follows .env"
# Two conditions, both necessary. `reverb:start` must exist (the package is installed), and
# .env must actually be in socket mode — BROADCAST_CONNECTION=reverb. Polling is a supported
# mode (master prompt Part B), so a release in that mode STOPS Reverb rather than leaving a
# websocket server running that nothing connects to.
#
# The restart itself drops every open socket. That is fine and it is why the client reconnects
# on its own: the bell falls back to polling in the gap and says so, and a reconnect makes it
# re-read rather than assume. See resources/js/Components/Notifications/notifications.ts.
#
# Note the ORDER in this script: `npm run build` ran several steps ago, and it is what bakes
# VITE_REALTIME into the assets. A .env change to either half of the switch therefore needs a
# release (`SKIP_PULL=1 deploy/deploy.sh`), never a `config:clear`.
commands="$(artisan list --raw)"
broadcast_connection="$(sed -n 's/^BROADCAST_CONNECTION=//p' "$APP_DIR/.env" | tail -n 1 | tr -d "\"'")"
if ! grep -q '^reverb:start' <<<"$commands"; then
    echo "reverb:start is not available (laravel/reverb is not installed), skipping"
elif [ "$broadcast_connection" = "reverb" ]; then
    supervisorctl restart hq-reverb
else
    echo "BROADCAST_CONNECTION=${broadcast_connection:-unset}, so the bell polls; stopping hq-reverb"
    supervisorctl stop hq-reverb > /dev/null 2>&1 || true
fi

step "reload $PHP_FPM_SERVICE"
svc reload "$PHP_FPM_SERVICE"

step "maintenance mode off"
artisan up
WENT_DOWN=0

step "deployed $(git -C "$APP_DIR" log -1 --format='%h %s (%ci)')"
