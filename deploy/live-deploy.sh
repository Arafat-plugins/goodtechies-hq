#!/usr/bin/env bash
# goodERP: safe one-click release on the 1 GB VPS (decision 12-92).
#
# Started from Windows by deploy-live.bat (repo root), which sends this file over SSH and runs
# it as root. It can also be run by hand on the server, e.g. from the VPSDime web console:
#
#     bash /var/www/goodtechies-hq/deploy/live-deploy.sh            # deploy what is on GitHub
#     FORCE=1 bash /var/www/goodtechies-hq/deploy/live-deploy.sh    # rebuild the same commit
#     CLEAN_ONLY=1 bash /var/www/goodtechies-hq/deploy/live-deploy.sh  # only remove the junk
#     ALLOW_DB_CHANGES=1 ...   # let a release whose migrations drop/rename/delete data go ahead
#
# What it adds around deploy/deploy.sh (which still does the real release: maintenance mode,
# composer, npm build, migrate, caches, Reverb, PHP-FPM reload):
#
#   - nothing happens when the server already runs what is on GitHub;
#   - JUNK first (decision 12-93): the 2 GB /swapfile this container may never switch on, apt's
#     downloaded packages, the systemd journal beyond 50 MB, app logs beyond 50 MB, rotated app
#     logs older than 14 days; after a successful build also the npm and composer download
#     caches. Never touched: the database, uploaded files (storage/app), .env, any backup;
#   - THE DATABASE: a release whose new migrations drop, rename, truncate or delete anything in
#     their up() is refused unless ALLOW_DB_CHANGES=1; every release starts with a pg_dump that
#     is verified with pg_restore --list before anything changes (/root/hq-backups, newest 10
#     kept, `latest.dump` points at the newest; deploy-live.bat also copies it to the PC);
#   - it refuses to start without 1.5 GB of free disk (counted after the junk is gone);
#   - MEMORY: `npm run build` peaks near 0.95 GB and this box has 1 GB and no swap (a container
#     may not swapon). The queue worker and Reverb are paused for the build and always started
#     again; `npm ci` is skipped when package.json / package-lock.json did not change. That is
#     what turned the ENOMEM of 2026-10-03 into a clean build;
#   - the code is fast-forwarded BEFORE deploy.sh runs (SKIP_PULL=1), so deploy.sh is never the
#     file git rewrites while bash is still reading it;
#   - a failed release is retried once; a second failure rolls the code and the built assets back
#     to the version that was live, so the site never stays on new PHP with old assets. Additive
#     migrations that already ran are kept (the old code ignores new columns); the backup taken
#     in step 3 is the way back for anything else.
#
# Exit codes: 0 deployed · 1 stopped before anything changed · 2 release failed and was rolled
#             back · 3 deployed, but the site check failed · 4 nothing to release (already up
#             to date, or CLEAN_ONLY=1).

set -uo pipefail

APP_DIR="${APP_DIR:-/var/www/goodtechies-hq}"
BRANCH=main
DB_NAME=goodtechies_hq
PHP_FPM_SERVICE=php8.3-fpm
BACKUP_DIR="${BACKUP_DIR:-/root/hq-backups}"
KEEP_BACKUPS=10
LOG_DIR="${LOG_DIR:-/root/hq-deploy-logs}"
KEEP_LOGS=20
MIN_FREE_DISK_MB=1500
SITE_URL=https://erp.goodtechies.com/login
PREV_BUILD="${PREV_BUILD:-/root/hq-build-previous}"
LOCK_FILE="${LIVE_DEPLOY_LOCK:-/var/lock/goodtechies-hq-live-deploy.lock}"
SWAP_FILE="${SWAP_FILE:-/swapfile}"
FSTAB_FILE="${FSTAB_FILE:-/etc/fstab}"

STAMP="$(date +%F-%H%M%S)"
mkdir -p "$LOG_DIR"
LOG="$LOG_DIR/deploy-$STAMP.log"
export GIT_PAGER=cat PAGER=cat TERM="${TERM:-dumb}" COMPOSER_ALLOW_SUPERUSER=1

step() { printf '\n==> %s\n' "$*"; }
ok() { printf '    ok  %s\n' "$*"; }
stop_unchanged() {
    printf '\n!!! DEPLOY STOPPED: %s\n    Nothing was changed on the live site.\n    Log: %s\n' "$*" "$LOG"
    exit 1
}
svc_reload_fpm() {
    if [ -d /run/systemd/system ]; then systemctl reload "$PHP_FPM_SERVICE"; else service "$PHP_FPM_SERVICE" reload; fi
}
broadcast_connection() {
    sed -n 's/^BROADCAST_CONNECTION=//p' "$APP_DIR/.env" | tail -n 1 | tr -d "\"'"
}

WORKERS_PAUSED=0
start_workers() {
    [ "$WORKERS_PAUSED" -eq 1 ] || return 0
    supervisorctl start hq-queue > /dev/null 2>&1 || true
    if [ "$(broadcast_connection)" = "reverb" ]; then
        supervisorctl start hq-reverb > /dev/null 2>&1 || true
    fi
    WORKERS_PAUSED=0
}

disk_used_mb() { df -Pm / | awk 'NR == 2 { print $3 }'; }

# Remove what is safe to remove and nothing else. Every line is allowed to fail: junk that cannot
# be removed is not a reason to stop a release.
cleanup_junk() {
    local before after f size_mb
    before="$(disk_used_mb)"

    # The install script made /swapfile for the build, but this container refuses swapon
    # ("Operation not permitted"), so it is 2 GB of disk that can never be used. Removed only
    # when it is NOT active swap.
    if [ -f "$SWAP_FILE" ] && ! grep -q "^${SWAP_FILE}[[:space:]]" /proc/swaps 2> /dev/null; then
        rm -f "$SWAP_FILE" && echo "    removed $SWAP_FILE (swap is not allowed on this server, it only took disk)"
        sed -i "\\#^${SWAP_FILE}[[:space:]]#d" "$FSTAB_FILE" 2> /dev/null || true
    fi

    # Package files apt downloaded to install things; the installed programs stay.
    if command -v apt-get > /dev/null 2>&1; then
        apt-get clean > /dev/null 2>&1 && echo "    cleared apt's downloaded package files"
    fi

    # The system journal: keep the newest 50 MB.
    if command -v journalctl > /dev/null 2>&1; then
        journalctl --vacuum-size=50M > /dev/null 2>&1 && echo "    system journal trimmed to 50 MB"
    fi

    # App logs: a log above 50 MB keeps its newest 20,000 lines (rewritten in place, so a worker
    # that has it open keeps writing to the same file); rotated logs older than 14 days go.
    for f in "$APP_DIR"/storage/logs/*.log; do
        [ -f "$f" ] || continue
        size_mb=$(( $(stat -c %s "$f") / 1048576 ))
        if [ "$size_mb" -gt 50 ]; then
            tail -n 20000 "$f" > "$f.trim" && cat "$f.trim" > "$f"
            rm -f "$f.trim"
            echo "    trimmed $(basename "$f") (${size_mb} MB) to its newest 20,000 lines"
        fi
    done
    find "$APP_DIR/storage/logs" -maxdepth 1 -type f -name '*-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].log' -mtime +14 -delete 2> /dev/null || true

    after="$(disk_used_mb)"
    ok "junk removed: $(( before - after > 0 ? before - after : 0 )) MB freed, $(df -Ph / | awk 'NR == 2 { print $4 }') free now"
}

# Caches that only speed up the NEXT download; cleared after a build that no longer needs them.
cleanup_caches() {
    npm cache clean --force > /dev/null 2>&1 && echo "    npm download cache cleared"
    COMPOSER_ALLOW_SUPERUSER=1 composer clear-cache > /dev/null 2>&1 && echo "    composer download cache cleared"
    return 0
}

main() {
# Whatever happens below, the workers come back.
trap start_workers EXIT

[ "$(id -u)" -eq 0 ] || stop_unchanged "run this as root"
cd "$APP_DIR" 2> /dev/null || stop_unchanged "$APP_DIR does not exist"
[ -f .env ] || stop_unchanged ".env is missing (run deploy/install.sh first)"

exec 8> "$LOCK_FILE"
flock -n 8 || stop_unchanged "another deploy is already running"

if [ "${CLEAN_ONLY:-0}" = "1" ]; then
    step "Remove junk only (no release)"
    cleanup_junk
    cleanup_caches
    exit 4
fi

# ------------------------------------------------------------------------------------------
step "1/10  Compare the live server with GitHub"
git fetch --quiet origin "$BRANCH" || stop_unchanged "could not reach GitHub"
OLD="$(git rev-parse HEAD)"
NEW="$(git rev-parse "origin/$BRANCH")"
echo "    live now : $(git log -1 --format='%h  %s  (%cr)' "$OLD")"
echo "    on GitHub: $(git log -1 --format='%h  %s  (%cr)' "$NEW")"

if [ "$OLD" = "$NEW" ] && [ "${FORCE:-0}" != "1" ]; then
    printf '\nAlready up to date: the live site runs the latest GitHub version. Nothing to do.\n'
    exit 4
fi

[ "$(git symbolic-ref --quiet --short HEAD || true)" = "$BRANCH" ] \
    || stop_unchanged "the server checkout is not on '$BRANCH'"
if [ -n "$(git status --porcelain)" ]; then
    git status --porcelain | head -20
    stop_unchanged "the server has local file changes (listed above); releases ship exactly what is on GitHub"
fi
git merge-base --is-ancestor "$OLD" "$NEW" \
    || stop_unchanged "GitHub does not contain the live commit (was history rewritten?)"

CHANGED="$(git diff --name-only "$OLD" "$NEW")"
echo "    files changed: $(printf '%s' "$CHANGED" | grep -c . || true)"
NEW_MIGRATIONS="$(printf '%s\n' "$CHANGED" | grep '^database/migrations/' || true)"
if [ -n "$NEW_MIGRATIONS" ]; then
    echo "    database changes in this release:"
    printf '%s\n' "$NEW_MIGRATIONS" | sed 's#^database/migrations/#      - #'
fi

# The database is never put at risk silently: a migration whose up() drops, renames, truncates or
# deletes is held until the person says so. down() is not read (it always drops; it only runs on
# a rollback somebody types by hand).
DESTRUCTIVE=""
while IFS= read -r m; do
    [ -n "$m" ] || continue
    hits="$(git show "$NEW:$m" 2> /dev/null \
        | awk '/function[[:space:]]+up[[:space:]]*\(/ { u = 1 } /function[[:space:]]+down[[:space:]]*\(/ { u = 0 } u' \
        | grep -inE 'drop(Column|IfExists|Columns)?[[:space:]]*\(|dropTable|renameColumn|->rename[[:space:]]*\(|DROP[[:space:]]+(TABLE|COLUMN)|TRUNCATE|DELETE[[:space:]]+FROM|->truncate[[:space:]]*\(|->delete[[:space:]]*\(' \
        || true)"
    if [ -n "$hits" ]; then
        DESTRUCTIVE="${DESTRUCTIVE}      ${m#database/migrations/}"$'\n'"$(printf '%s\n' "$hits" | sed 's/^/          line /')"$'\n'
    fi
done <<< "$NEW_MIGRATIONS"
if [ -n "$DESTRUCTIVE" ]; then
    printf '\n    These migrations REMOVE or RENAME database data:\n%s' "$DESTRUCTIVE"
    if [ "${ALLOW_DB_CHANGES:-0}" != "1" ]; then
        stop_unchanged "this release would remove or rename database data. If that is intended, run: deploy-live.bat allow-db-changes (a verified backup is still taken first)"
    fi
    echo "    ALLOW_DB_CHANGES=1: going ahead (the backup below is the way back)"
fi

SKIP_NPM_CI=1
if [ ! -d node_modules ] || printf '%s\n' "$CHANGED" | grep -qxE 'package(-lock)?\.json'; then
    SKIP_NPM_CI=0
fi
[ "$SKIP_NPM_CI" = "1" ] && echo "    packages unchanged: node_modules is reused (saves memory and a minute)"

# ------------------------------------------------------------------------------------------
step "2/10  Remove junk (never the database, uploads, .env or backups)"
cleanup_junk

# ------------------------------------------------------------------------------------------
step "3/10  Check disk and memory"
free_disk_mb="$(df -Pm "$APP_DIR" | awk 'NR == 2 { print $4 }')"
[ "${free_disk_mb:-0}" -ge "$MIN_FREE_DISK_MB" ] \
    || stop_unchanged "only ${free_disk_mb} MB of disk free (need ${MIN_FREE_DISK_MB} MB)"
ok "disk free: ${free_disk_mb} MB"
free -m | awk 'NR <= 2 { print "    " $0 }'

# ------------------------------------------------------------------------------------------
step "4/10  Back up the database and check the backup"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
DUMP="$BACKUP_DIR/${DB_NAME}-${STAMP}-before-$(git rev-parse --short "$NEW").dump"
# shellcheck disable=SC2024 # root writes the file; postgres only produces the bytes
if ! sudo -u postgres pg_dump -Fc "$DB_NAME" > "$DUMP" || [ ! -s "$DUMP" ]; then
    rm -f "$DUMP"
    stop_unchanged "the database backup failed"
fi
# A backup that cannot be read is no backup: pg_restore must be able to list every object in it.
# Run as root, not postgres: the dump sits in root's 700 directory, which postgres cannot open,
# and `--list` reads only the file (no database connection).
if ! pg_restore --list "$DUMP" > /dev/null 2>&1; then
    stop_unchanged "the database backup could not be read back (kept for inspection: $DUMP)"
fi
tables="$(pg_restore --list "$DUMP" 2> /dev/null | grep -c ' TABLE DATA ' || true)"
ln -sfn "$DUMP" "$BACKUP_DIR/latest.dump"
ok "$DUMP ($(du -h "$DUMP" | cut -f1), ${tables} tables, verified)"
# Only now that a verified backup exists are the oldest ones let go. Newest 10 of THIS tool's
# backups are kept; older /root/*.dump files are never touched.
ls -1t "$BACKUP_DIR/${DB_NAME}"-[0-9]*.dump 2> /dev/null | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -f --
ls -1t "$LOG_DIR"/deploy-*.log 2> /dev/null | tail -n +$((KEEP_LOGS + 1)) | xargs -r rm -f --

# ------------------------------------------------------------------------------------------
step "5/10  Keep a copy of the current build (for an automatic rollback)"
rm -rf "$PREV_BUILD"
if [ -d public/build ]; then
    cp -a public/build "$PREV_BUILD" || stop_unchanged "could not copy public/build"
    ok "public/build saved"
else
    echo "    no public/build yet; nothing to keep"
fi

# ------------------------------------------------------------------------------------------
step "6/10  Pause the background workers so the build has the memory"
WORKERS_PAUSED=1
supervisorctl stop hq-queue hq-reverb > /dev/null 2>&1 || true
sync
ok "hq-queue and hq-reverb paused (queued jobs wait in Redis, nothing is lost)"
free -m | awk 'NR <= 2 { print "    " $0 }'

# ------------------------------------------------------------------------------------------
step "7/10  Update the code to $(git rev-parse --short "$NEW")"
if ! git merge --ff-only --quiet "$NEW"; then
    stop_unchanged "git could not fast-forward the code"
fi
ok "code is at $(git log -1 --format='%h %s')"

# ------------------------------------------------------------------------------------------
step "8/10  Release (deploy/deploy.sh: maintenance mode, composer, build, migrate, caches)"
release() {
    SKIP_PULL=1 SKIP_NPM_CI="$1" bash "$APP_DIR/deploy/deploy.sh"
}

RELEASED=0
if release "$SKIP_NPM_CI"; then
    RELEASED=1
else
    echo
    echo "    The first attempt failed. Retrying once..."
    sync
    sleep 5
    # npm ci writes node_modules/.package-lock.json when it finishes; without it the tree is
    # incomplete and has to be installed again.
    retry_skip=0
    [ -f node_modules/.package-lock.json ] && retry_skip=1
    if release "$retry_skip"; then
        RELEASED=1
    fi
fi

if [ "$RELEASED" -ne 1 ]; then
    step "ROLLBACK: putting the previous version back"
    git reset --hard --quiet "$OLD"
    if [ -d "$PREV_BUILD" ]; then
        rm -rf public/build
        cp -a "$PREV_BUILD" public/build
    fi
    if printf '%s\n' "$CHANGED" | grep -qx 'composer.lock'; then
        composer install --no-dev --optimize-autoloader --no-interaction
    fi
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
    php artisan view:cache
    chown -R www-data:www-data storage bootstrap/cache
    svc_reload_fpm || true
    php artisan up || true
    start_workers
    printf '\n!!! The new version could not be released, so the previous version was put back.\n'
    printf '    Live now: %s\n' "$(git log -1 --format='%h %s')"
    printf '    Database backup from before the attempt: %s\n' "$DUMP"
    printf '    Full log: %s\n' "$LOG"
    exit 2
fi

# ------------------------------------------------------------------------------------------
step "9/10  Start the workers again and check the live site"
start_workers
sleep 3
supervisorctl status 2> /dev/null | sed 's/^/    /'
http_code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL" || echo 000)"
pending="$(php artisan migrate:status 2> /dev/null | grep -ci pending || true)"
echo "    site answers: HTTP $http_code   |   migrations still pending: ${pending:-0}"

# ------------------------------------------------------------------------------------------
step "10/10  Clear the download caches the build no longer needs"
cleanup_caches
ok "disk free now: $(df -Ph / | awk 'NR == 2 { print $4 }')"

printf '\n============================================================\n'
if [ "$http_code" = "200" ] && [ "${pending:-0}" = "0" ]; then
    printf ' DEPLOYED  %s\n' "$(git log -1 --format='%h  %s')"
    printf ' Backup    %s\n' "$DUMP"
    printf ' Log       %s\n' "$LOG"
    printf '============================================================\n'
    exit 0
fi
printf ' DEPLOYED, BUT THE CHECK FAILED (HTTP %s, %s pending migrations).\n' "$http_code" "${pending:-0}"
printf ' Open the site and look; the log is %s\n' "$LOG"
printf '============================================================\n'
exit 3
}

# Everything is shown on screen AND kept in the log. `main` runs in the pipeline's subshell, so
# its `exit` codes come back through PIPESTATUS.
main "$@" 2>&1 | tee -a "$LOG"
exit "${PIPESTATUS[0]}"
