#!/usr/bin/env bash
# GoodTechies HQ: release script. Run as root on every release:
#
#   cd /var/www/goodtechies-hq && bash deploy/deploy.sh
#
# (`bash` because the repository is committed from Windows and the file has no execute bit.)
#
# Environment:
#   APP_DIR    application directory (default: the parent of this script's directory)
#   BRANCH     the branch releases come from (default: main)
#   SKIP_PULL  1 = deploy the code that is already checked out (used for rollback)
#   NODE_BUILD_HEAP_MB  Node's heap for `npm run build` on a box under 2 GB of RAM (default 768)
#   SKIP_NPM_CI  1 = keep node_modules (packages unchanged); deploy/live-deploy.sh decides this
#
# One-click release from Windows: deploy-live.bat (repo root) runs deploy/live-deploy.sh over
# SSH, which backs up the database, frees memory for the build, then calls this script.
#
# Automatic deploys: GitHub Actions (.github/workflows/deploy.yml) logs in with a key that
# /root/.ssh/authorized_keys restricts to running this script and nothing else
# (deploy/setup-actions-key.sh). The command it asks for, `deploy <commit>`, reaches this
# script as SSH_ORIGINAL_COMMAND; the commit id is the only thing read from it, and it is used
# to prove that what is deployed contains what was pushed.
#
# What a release never touches: .env and storage/ (both git-ignored; storage/ is only chowned).
# A release refuses to start when the server checkout has local changes or is not on BRANCH,
# so the server always runs exactly what is on GitHub.
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
BRANCH="${BRANCH:-main}"
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

# One release at a time: a push that lands while another release runs waits here (Actions also
# queues them, but a manual run and an automatic one could still meet).
LOCK_FILE="${LOCK_FILE:-/var/lock/goodtechies-hq-deploy.lock}"
mkdir -p "$(dirname "$LOCK_FILE")"
exec 9> "$LOCK_FILE"
if ! flock -n 9; then
    echo "another release is running; waiting for it (up to 30 minutes)"
    flock -w 1800 9 || die "another release is still running after 30 minutes"
fi

EXPECTED_SHA=""
if [ -n "${SSH_ORIGINAL_COMMAND:-}" ]; then
    if [[ "$SSH_ORIGINAL_COMMAND" =~ ^deploy\ ([0-9a-f]{40})$ ]]; then
        EXPECTED_SHA="${BASH_REMATCH[1]}"
    elif [ "$SSH_ORIGINAL_COMMAND" != "deploy" ]; then
        die "refused: this key may only run 'deploy [<40-character commit id>]'"
    fi
fi

step "check the checkout"
git ls-files --error-unmatch .env > /dev/null 2>&1 && die ".env is tracked by git; a pull would overwrite it. Remove it from the repository first"
if [ "$SKIP_PULL" = "1" ]; then
    echo "SKIP_PULL=1, deploying the checked-out commit $(git rev-parse --short HEAD)"
else
    current_branch="$(git symbolic-ref --quiet --short HEAD || true)"
    [ -n "$current_branch" ] \
        || die "HEAD is detached at $(git rev-parse --short HEAD) (a rollback?). Resume releases with: cd $APP_DIR && git checkout $BRANCH && bash deploy/deploy.sh"
    [ "$current_branch" = "$BRANCH" ] || die "the server checkout is on '$current_branch', releases come from '$BRANCH'"
    local_changes="$(git status --porcelain)"
    if [ -n "$local_changes" ]; then
        echo "$local_changes" >&2
        die "the server checkout has local changes (above). Releases ship exactly what is on GitHub: commit them there, or discard them here with 'git -C $APP_DIR stash' / 'git checkout -- <file>'"
    fi
    git fetch --quiet origin "$BRANCH"
    git merge-base --is-ancestor HEAD "origin/$BRANCH" \
        || die "origin/$BRANCH does not contain the deployed commit $(git rev-parse --short HEAD) (history was rewritten?); refusing a non-fast-forward release"
    if [ -n "$EXPECTED_SHA" ]; then
        git merge-base --is-ancestor "$EXPECTED_SHA" "origin/$BRANCH" 2> /dev/null \
            || die "the pushed commit $EXPECTED_SHA is not on origin/$BRANCH"
    fi
    echo "on $BRANCH, clean, $(git rev-parse --short HEAD) -> $(git rev-parse --short "origin/$BRANCH")"
fi

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
    # The fast-forward-only equivalent of `git pull --ff-only` on BRANCH, against the commit
    # fetched and checked above.
    git merge --ff-only --quiet "origin/$BRANCH"
    if [ -n "$EXPECTED_SHA" ]; then
        if [ "$(git rev-parse HEAD)" = "$EXPECTED_SHA" ]; then
            echo "deploying the pushed commit $EXPECTED_SHA"
        else
            echo "deploying $(git rev-parse HEAD), which contains the pushed $EXPECTED_SHA (a later push landed first)"
        fi
    fi
fi

step "composer install"
composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$APP_DIR"

step "build frontend assets"
# Measured (brief 030, Docker capped at 1 GB, one CPU): `npm run build` peaks at about 0.95 GB
# resident, mostly the bundler's native memory rather than Node's heap. With no swap the kernel
# killed it; with install.sh's 2 GB swap file it finished in 12 s using ~80 MB of swap. So swap
# is what makes a 1 GB box survive, and the heap cap below only keeps Node's own share bounded.
# NODE_OPTIONS wins if set.
mem_mb="$(awk '/^MemTotal:/ { print int($2 / 1024) }' /proc/meminfo)"
if [ "$mem_mb" -lt 2000 ]; then
    if [[ "${NODE_OPTIONS:-}" != *max-old-space-size* ]]; then
        export NODE_OPTIONS="${NODE_OPTIONS:+$NODE_OPTIONS }--max-old-space-size=${NODE_BUILD_HEAP_MB:-768}"
    fi
    echo "${mem_mb} MB of RAM: NODE_OPTIONS=$NODE_OPTIONS, swap $(awk '/^SwapTotal:/ { print int($2 / 1024) }' /proc/meminfo) MB"
    if ! awk '/^SwapTotal:/ { exit !($2 > 0) }' /proc/meminfo; then
        echo "WARNING: no swap on a box under 2 GB; the build may be killed. install.sh creates /swapfile." >&2
    fi
fi
# SKIP_NPM_CI=1 (set by deploy/live-deploy.sh when neither package.json nor package-lock.json
# changed in this release): reuse node_modules instead of reinstalling it. `npm ci` deletes and
# rewrites the whole tree, which costs a minute and a memory peak of its own on a 1 GB box.
if [ "${SKIP_NPM_CI:-0}" = "1" ] && [ -d "$APP_DIR/node_modules" ]; then
    echo "SKIP_NPM_CI=1 and node_modules is present: packages unchanged, not reinstalling"
else
    npm ci --include=dev --no-audit --no-fund
fi
npm run build

step "migrate (pgsql_migrator)"
artisan migrate --force --database=pgsql_migrator

step "push notification keys (first release only)"
artisan push:vapid --write

step "cache config, routes and views"
artisan config:cache
artisan route:cache
artisan event:cache
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
# release (`SKIP_PULL=1 bash deploy/deploy.sh`), never a `config:clear`.
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

step "nginx: HTTP/2 (one connection per phone instead of six)"
bash "$APP_DIR/deploy/http2.sh" "/etc/nginx/sites-available/goodtechies-hq" || true

step "maintenance mode off"
artisan up
WENT_DOWN=0

step "deployed $(git -C "$APP_DIR" log -1 --format='%h %s (%ci)')"
