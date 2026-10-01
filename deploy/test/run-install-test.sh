#!/usr/bin/env bash
# Proves deploy/install.sh and deploy/deploy.sh on a fresh Ubuntu 24.04 container.
#
#   deploy/test/run-install-test.sh
#
# Environment:
#   TEST_DOMAIN     DOMAIN for install.sh (default: hq.test). An IPv4 address tests the http
#                   IP mode, including a real sign-in over http (deploy/test/http-login-check.sh).
#   INSTALL_ENV     extra variables for install.sh, e.g. "LOW_MEMORY=1 SWAP_SIZE_MB=64"
#   TEST_WORKTREE   1 = overlay the whole working tree (tracked + untracked, not ignored) instead
#                   of deploy/ only, so uncommitted application changes are tested too
#   LOG_FILE        where the transcript goes (default: $TMPDIR/hq-install-test-<timestamp>.log)
#   EXTRA_CA_CERT   PEM bundle to trust inside the container (TLS-intercepting egress proxy)
#   KEEP_CONTAINER  1 = keep the container after the run (it is always kept on failure)
#   HTTPS_PROXY / HTTP_PROXY / NO_PROXY (and lowercase) are passed into the container
#   unless they point at the host loopback, which a bridged container cannot reach.
#
# The container clones the committed HEAD of this checkout from /src; the working-copy
# deploy/ directory (or, with TEST_WORKTREE=1, the whole working tree) is then copied over the
# clone and committed there, so uncommitted changes are tested and the checkout is clean — which
# deploy.sh now insists on. A bare repository in the container then plays GitHub, so the real
# pull path (fetch, fast-forward, the pushed-commit check) and the restricted Actions key are
# exercised too.
#
# From Phase 6 it also proves the realtime kit on the real layout: the polling default, then
# the .env switch to Reverb and a release, then Supervisor, the loopback bind and Nginx's
# websocket proxy, then back to polling. deploy/test/run-reverb-test.sh proves the transport
# itself (a real websocket, a real broadcast) in seconds and without Docker — run that first.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
IMAGE="goodtechies-hq-install-test:ubuntu24"
CONTAINER="hq-install-test-$$"
APP_DIR="/var/www/goodtechies-hq"
DOMAIN="${TEST_DOMAIN:-hq.test}"
INSTALL_ENV="${INSTALL_ENV:-}"
TEST_WORKTREE="${TEST_WORKTREE:-0}"
DB_NAME="goodtechies_hq"
LOG_FILE="${LOG_FILE:-${TMPDIR:-/tmp}/hq-install-test-$(date +%Y%m%d-%H%M%S).log}"
EXTRA_CA_CERT="${EXTRA_CA_CERT:-}"
KEEP_CONTAINER="${KEEP_CONTAINER:-0}"

FAILURES=0
BUILD_DIR=""

mkdir -p "$(dirname "$LOG_FILE")"
exec > >(tee -a "$LOG_FILE") 2>&1

step() {
    echo
    echo "==> $*"
}

cleanup() {
    local status=$?
    [ -z "$BUILD_DIR" ] || rm -rf "$BUILD_DIR"
    if [ "$status" -eq 0 ] && [ "$FAILURES" -eq 0 ] && [ "$KEEP_CONTAINER" != "1" ]; then
        docker rm -f "$CONTAINER" > /dev/null 2>&1 || true
    elif docker inspect "$CONTAINER" > /dev/null 2>&1; then
        echo "container kept for inspection: docker exec -it $CONTAINER bash"
    fi
}
trap cleanup EXIT

in_container() {
    docker exec "${EXEC_ENV[@]}" "$CONTAINER" bash -c "$1"
}

check() {
    local name="$1" expected="$2" actual="$3"
    if [ "$actual" = "$expected" ]; then
        echo "PASS  $name (got: $actual)"
    else
        echo "FAIL  $name (expected: $expected, got: $actual)"
        FAILURES=$((FAILURES + 1))
    fi
}

is_loopback_proxy() {
    [[ "$1" =~ ^([a-z]+://)?(127\.[0-9.]+|localhost|\[::1\])(:|/|$) ]]
}

step "test setup"
BRANCH="$(git -C "$SRC_DIR" rev-parse --abbrev-ref HEAD)"
COMMIT="$(git -C "$SRC_DIR" rev-parse --short HEAD)"
echo "date:      $(date -u '+%Y-%m-%d %H:%M UTC')"
echo "source:    $SRC_DIR (branch $BRANCH, commit $COMMIT)"
echo "image:     $IMAGE (FROM ubuntu:24.04)"
echo "log:       $LOG_FILE"

RUN_ARGS=(--detach --name "$CONTAINER" --hostname hq-install-test -v "$SRC_DIR:/src:ro")
# A git worktree keeps its metadata in the main repository; mount that read-only too.
if [ -f "$SRC_DIR/.git" ]; then
    COMMON_GIT_DIR="$(cd "$SRC_DIR" && cd "$(git rev-parse --git-common-dir)" && pwd)"
    RUN_ARGS+=(-v "$COMMON_GIT_DIR:$COMMON_GIT_DIR:ro")
    echo "worktree:  mounting git metadata $COMMON_GIT_DIR read-only"
fi

EXEC_ENV=()
BUILD_ARGS=()
for var in HTTPS_PROXY https_proxy HTTP_PROXY http_proxy NO_PROXY no_proxy; do
    value="${!var:-}"
    [ -n "$value" ] || continue
    if [[ "$var" =~ ^(NO_PROXY|no_proxy)$ ]]; then
        EXEC_ENV+=(-e "$var=$value")
    elif is_loopback_proxy "$value"; then
        echo "proxy:     $var=$value is the host loopback, not passed (container uses direct egress)"
    else
        EXEC_ENV+=(-e "$var=$value")
        BUILD_ARGS+=(--build-arg "$var=$value")
        echo "proxy:     passing $var"
    fi
done

BUILD_DIR="$(mktemp -d)"
cp "$SCRIPT_DIR/Dockerfile.ubuntu24" "$BUILD_DIR/Dockerfile"
mkdir -p "$BUILD_DIR/extra-ca"
if [ -n "$EXTRA_CA_CERT" ]; then
    cp "$EXTRA_CA_CERT" "$BUILD_DIR/extra-ca/extra-ca.crt"
    EXEC_ENV+=(-e "NODE_EXTRA_CA_CERTS=/etc/ssl/certs/ca-certificates.crt")
    echo "trust:     adding $EXTRA_CA_CERT to the container trust store (NODE_EXTRA_CA_CERTS set)"
fi

step "build image"
docker build --quiet "${BUILD_ARGS[@]}" -t "$IMAGE" "$BUILD_DIR"

step "start container"
docker run "${RUN_ARGS[@]}" "$IMAGE" > /dev/null
in_container 'grep PRETTY_NAME /etc/os-release; test -d /run/systemd/system && echo systemd || echo "no systemd (service fallback)"'

step "clone the committed HEAD and overlay the working copy"
in_container "git config --global --add safe.directory /src \
    && git config --global --add safe.directory '*' \
    && git config --global user.email install-test@localhost \
    && git config --global user.name 'install test' \
    && git clone --branch '$BRANCH' /src '$APP_DIR' \
    && git -C '$APP_DIR' log -1 --format='clone HEAD: %h %s'"
if [ "$TEST_WORKTREE" = "1" ]; then
    in_container "cd /src \
        && git ls-files -z -co --exclude-standard | tar --null -T - --ignore-failed-read -cf - 2> /dev/null | tar -xf - -C '$APP_DIR' \
        && git ls-files -z --deleted | (cd '$APP_DIR' && xargs -0 -r rm -f) \
        && echo 'copied the whole working tree over $APP_DIR (uncommitted changes under test)'"
else
    in_container "cp -a /src/deploy/. '$APP_DIR/deploy/' && echo 'copied /src/deploy over $APP_DIR/deploy (uncommitted deploy kit under test)'"
fi
# Commit the overlay, then make a bare repository that plays GitHub's role from here on.
in_container "cd '$APP_DIR' && git add -A && (git diff --cached --quiet || git commit -q -m 'install test: working-copy overlay') \
    && git clone -q --bare '$APP_DIR' /srv/origin.git \
    && git remote set-url origin /srv/origin.git && git fetch -q origin \
    && git branch -q --set-upstream-to='origin/$BRANCH' \
    && git log -1 --format='test HEAD: %h %s'"

step "run deploy/install.sh"
in_container "cd '$APP_DIR' && $INSTALL_ENV REPO_URL=/srv/origin.git BRANCH='$BRANCH' SKIP_FIREWALL=1 DOMAIN='$DOMAIN' bash deploy/install.sh"

step "run deploy/deploy.sh again (idempotency)"
in_container "cd '$APP_DIR' && SKIP_PULL=1 BRANCH='$BRANCH' bash deploy/deploy.sh"

step "checks"
check "GET /login returns 200" 200 \
    "$(in_container "curl -s -o /dev/null -w '%{http_code}' -H 'Host: $DOMAIN' http://127.0.0.1/login")"
check "GET / returns 302" 302 \
    "$(in_container "curl -s -o /dev/null -w '%{http_code}' -H 'Host: $DOMAIN' http://127.0.0.1/")"
check "users seeded" 5 \
    "$(in_container "sudo -u postgres psql -d $DB_NAME -tAc 'select count(*) from users'")"
# The production seed: reference data, no demo rows (SEED_DEMO=0 from the env template).
db_count() {
    in_container "sudo -u postgres psql -d $DB_NAME -tAc 'select count(*) from $1'"
}
check "SEED_DEMO=0 in .env" 0 "$(in_container "sed -n 's/^SEED_DEMO=//p' $APP_DIR/.env")"
for table in clients projects tasks recurring_tasks meetings income expenses employee_salaries payroll_periods payroll_items attendance_records time_entries; do
    if [ "$(in_container "sudo -u postgres psql -d $DB_NAME -tAc \"select to_regclass('public.$table') is not null\"")" = "t" ]; then
        check "production seed: no demo $table" 0 "$(db_count "$table")"
    else
        echo "SKIP  table $table does not exist"
    fi
done
check "production seed: six leave types" 6 "$(db_count leave_types)"
check "production seed: finance categories present" 1 "$([ "$(db_count finance_categories)" -gt 0 ] && echo 1 || echo 0)"
check "production seed: holidays present" 1 "$([ "$(db_count holidays)" -gt 0 ] && echo 1 || echo 0)"
audit_acl="$(in_container "sudo -u postgres psql -d $DB_NAME -c '\\dp audit_logs'")"
echo "$audit_acl"
check "audit_logs grants hq_app ar only" "hq_app=ar/hq_migrator" \
    "$(grep -o 'hq_app=[a-zA-Z]*/hq_migrator' <<<"$audit_acl" || echo missing)"
# queue:restart makes Supervisor start a fresh worker; allow it to pass startsecs.
for _ in $(seq 1 20); do
    queue_status="$(in_container 'supervisorctl status hq-queue' || true)"
    grep -q RUNNING <<<"$queue_status" && break
    sleep 1
done
echo "$queue_status"
check "hq-queue RUNNING" RUNNING "$(awk '{print $2}' <<<"$queue_status")"
about="$(in_container "cd '$APP_DIR' && php artisan about --only=environment")"
echo "$about"
check "artisan about shows production" production \
    "$(grep -oE 'Environment \.+ [a-z]+' <<<"$about" | awk '{print $NF}' | head -n 1)"
check "PHP served through the goodtechies-hq pool" 1 \
    "$(in_container "ls /run/php/php8.3-fpm-goodtechies-hq.sock > /dev/null 2>&1 && echo 1 || echo 0")"

if [[ "$DOMAIN" =~ ^[0-9.]+$ ]]; then
    step "IP mode: plain http, no HSTS, and people can sign in"
    check "no HSTS header over http" 0 \
        "$(in_container "curl -s -D - -o /dev/null -H 'Host: $DOMAIN' http://127.0.0.1/login | grep -ci strict-transport-security || true")"
    if in_container "cd '$APP_DIR' && bash /src/deploy/test/http-login-check.sh '$DOMAIN' '$APP_DIR'"; then
        check "sign-in over http (Employee, Admin with 2FA enrolment)" PASS PASS
    else
        check "sign-in over http (Employee, Admin with 2FA enrolment)" PASS FAIL
    fi
fi

if [[ " $INSTALL_ENV " == *" LOW_MEMORY=1 "* ]]; then
    step "low-memory profile"
    check "PHP-FPM pool is ondemand" "pm = ondemand" \
        "$(in_container "grep -m1 '^pm = ' /etc/php/8.3/fpm/pool.d/goodtechies-hq.conf")"
    check "PostgreSQL shared_buffers" 128MB "$(in_container "sudo -u postgres psql -tAc 'show shared_buffers'")"
    check "PostgreSQL max_connections" 40 "$(in_container "sudo -u postgres psql -tAc 'show max_connections'")"
    check "Redis maxmemory (96 MB)" 100663296 "$(in_container "redis-cli config get maxmemory | tail -n 1")"
    check "Redis maxmemory-policy" volatile-lru "$(in_container "redis-cli config get maxmemory-policy | tail -n 1")"
    check "vm.swappiness persisted" "vm.swappiness=10" "$(in_container "cat /etc/sysctl.d/99-goodtechies-hq.conf")"
fi

step "release path: a push, a refused dirty checkout, the restricted Actions key"
# A "push": a new commit lands on the bare origin from another clone.
pushed_sha="$(in_container "rm -rf /tmp/pusher && git clone -q /srv/origin.git /tmp/pusher \
    && cd /tmp/pusher && git checkout -q '$BRANCH' && echo '<!-- install test -->' >> README.md \
    && git commit -q -am 'install test: a pushed change' && git push -q origin '$BRANCH' && git rev-parse HEAD")"
echo "pushed $pushed_sha to the test origin"
in_container "cd '$APP_DIR' && SSH_ORIGINAL_COMMAND='deploy $pushed_sha' BRANCH='$BRANCH' bash deploy/deploy.sh"
check "deploy.sh released exactly the pushed commit" "$pushed_sha" "$(in_container "git -C '$APP_DIR' rev-parse HEAD")"
check "GET /login after the release" 200 \
    "$(in_container "curl -s -o /dev/null -w '%{http_code}' -H 'Host: $DOMAIN' http://127.0.0.1/login")"

in_container "echo '# local edit' >> '$APP_DIR/README.md'"
if in_container "cd '$APP_DIR' && BRANCH='$BRANCH' bash deploy/deploy.sh" > /dev/null 2>&1; then
    check "deploy.sh refuses a checkout with local changes" refused released
else
    check "deploy.sh refuses a checkout with local changes" refused refused
fi
check "the site stays up after the refused release" 200 \
    "$(in_container "curl -s -o /dev/null -w '%{http_code}' -H 'Host: $DOMAIN' http://127.0.0.1/login")"
in_container "git -C '$APP_DIR' checkout -- README.md"

if in_container "cd '$APP_DIR' && SSH_ORIGINAL_COMMAND='rm -rf /' bash deploy/deploy.sh" > /dev/null 2>&1; then
    check "deploy.sh refuses any other SSH command" refused ran
else
    check "deploy.sh refuses any other SSH command" refused refused
fi

# The Actions key over a real sshd: it may run the release and nothing else.
in_container "apt-get install -y -qq --no-install-recommends openssh-server openssh-client > /dev/null \
    && mkdir -p /run/sshd && ssh-keygen -A > /dev/null && (pgrep -x sshd > /dev/null || /usr/sbin/sshd)"
# An ssh login does not inherit docker exec's environment; give it the extra CA like the rest.
if [ -n "$EXTRA_CA_CERT" ]; then
    in_container "grep -q '^NODE_EXTRA_CA_CERTS=' /etc/environment || echo 'NODE_EXTRA_CA_CERTS=/etc/ssl/certs/ca-certificates.crt' >> /etc/environment"
fi
in_container "bash /src/deploy/setup-deploy-key.sh" > /dev/null
check "setup-deploy-key.sh made the GitHub key" 1 "$(in_container "test -f /root/.ssh/hq_github && grep -c 'IdentityFile /root/.ssh/hq_github' /root/.ssh/config")"
actions_out="$(in_container "APP_DIR='$APP_DIR' VPS_HOST=127.0.0.1 bash /src/deploy/setup-actions-key.sh")"
check "setup-actions-key.sh installed a restricted key" 1 \
    "$(in_container "grep -c '^restrict,command=\"/bin/bash $APP_DIR/deploy/deploy.sh\" ssh-ed25519 .* goodtechies-hq-actions-deploy\$' /root/.ssh/authorized_keys")"
check "setup-actions-key.sh keeps no private key on the server" 0 \
    "$(in_container "grep -l 'PRIVATE KEY' /root/.ssh/* 2> /dev/null | grep -vc '/root/.ssh/hq_github\$' || true")"
awk '/^---- VPS_SSH_KEY/ { on = 1; next } on && /^----$/ { on = 0 } on' <<<"$actions_out" \
    | docker exec -i "$CONTAINER" bash -c 'umask 077; cat > /tmp/actions_key'
awk '/^---- VPS_KNOWN_HOSTS/ { on = 1; next } on && /^$/ { on = 0 } on' <<<"$actions_out" \
    | docker exec -i "$CONTAINER" bash -c 'cat > /tmp/actions_known_hosts'
ssh_cmd="ssh -i /tmp/actions_key -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/tmp/actions_known_hosts -o BatchMode=yes root@127.0.0.1"
second_sha="$(in_container "cd /tmp/pusher && echo '<!-- second -->' >> README.md && git commit -q -am 'install test: pushed again' && git push -q origin '$BRANCH' && git rev-parse HEAD")"
if in_container "$ssh_cmd 'deploy $second_sha'" > /dev/null 2>&1; then
    check "the Actions key runs the release over ssh" ok ok
else
    check "the Actions key runs the release over ssh" ok failed
fi
check "the Actions key released the pushed commit" "$second_sha" "$(in_container "git -C '$APP_DIR' rev-parse HEAD")"
if in_container "$ssh_cmd 'id; cat /etc/shadow'" 2> /dev/null | grep -q 'uid=0'; then
    check "the Actions key cannot run anything else" refused "ran a shell command"
else
    check "the Actions key cannot run anything else" refused refused
fi

# ---------------------------------------------------------------------------
# Realtime (Phase 6). The install defaults to POLLING, so the first two checks are that the
# kit is present and correctly switched off; the rest turn the socket on the way a person
# would — two lines in .env and a release — and prove it end to end through Nginx.
#
# deploy/test/run-reverb-test.sh proves the same transport without Docker and in seconds; this
# is the half that can only be proved on the real layout: Supervisor starting the process,
# Nginx proxying the upgrade, and the .env switch surviving a release.
# ---------------------------------------------------------------------------

step "realtime: the polling default"
check "hq-reverb is stopped on a polling install" 1 \
    "$(in_container 'supervisorctl status hq-reverb | grep -cE "STOPPED|not started" || true')"
check "install.sh generated the Reverb credentials" 1 \
    "$(in_container "grep -cE '^REVERB_APP_SECRET=.+' $APP_DIR/.env || true")"
# 502 is exactly right here: Nginx is proxying, and there is nothing behind it because the
# deployment is in polling mode.
check "nginx proxies the websocket location" 502 \
    "$(in_container "curl -s -o /dev/null -w '%{http_code}' -H 'Host: $DOMAIN' http://127.0.0.1/app/anything")"
# And the polling build ships no socket client: the dynamic import is eliminated as dead code,
# so ~90 KB of laravel-echo and pusher-js is simply not in the bundle. See resources/js/echo.ts.
check "the polling build ships no socket client" 0 \
    "$(in_container "ls $APP_DIR/public/build/assets/pusher-*.js > /dev/null 2>&1 && echo 1 || echo 0")"

step "realtime: turn the socket on the way a person would"
in_container "cd '$APP_DIR' \
    && sed -i 's/^BROADCAST_CONNECTION=.*/BROADCAST_CONNECTION=reverb/' .env \
    && sed -i 's/^VITE_REALTIME=.*/VITE_REALTIME=reverb/' .env \
    && sed -i 's/^REVERB_HOST=.*/REVERB_HOST=$DOMAIN/' .env \
    && SKIP_PULL=1 bash deploy/deploy.sh"

for _ in $(seq 1 20); do
    reverb_status="$(in_container 'supervisorctl status hq-reverb' || true)"
    grep -q RUNNING <<<"$reverb_status" && break
    sleep 1
done
echo "$reverb_status"
check "hq-reverb RUNNING after the switch" RUNNING "$(awk '{print $2}' <<<"$reverb_status")"

reverb_listen="$(in_container "ss -Hltn 'sport = :8080' | awk '{print \$4}' | tr '\n' ' '")"
echo "reverb listens on: $reverb_listen"
check "Reverb listens on the loopback only" 0 \
    "$(grep -Ecv '^(127\.0\.0\.1|\[::1\]):' <<<"$(tr ' ' '\n' <<<"$reverb_listen" | grep -v '^$')" || true)"

# Through Nginx this time, not straight at the port. Anything other than 502 or 000 means the
# proxy reached Reverb; the exact code is Reverb refusing a request that is not an upgrade.
reverb_key="$(in_container "sed -n 's/^REVERB_APP_KEY=//p' $APP_DIR/.env | tr -d '\"'")"
proxied="$(in_container "curl -s -o /dev/null -w '%{http_code}' -H 'Host: $DOMAIN' http://127.0.0.1/app/$reverb_key")"
check "nginx reaches Reverb after the switch" 1 \
    "$([ "$proxied" != "000" ] && [ "$proxied" != "502" ] && echo 1 || echo 0)"
echo "  (status $proxied through the proxy)"

# The sharpest proof that VITE_REALTIME is a BUILD-time switch and not a runtime one: the
# socket client is dynamically imported, so a polling build eliminates the import as dead code
# and emits no `pusher-*.js` chunk at all. A reverb build emits one.
check "the reverb build emits the socket client chunk" 1 \
    "$(in_container "ls $APP_DIR/public/build/assets/pusher-*.js > /dev/null 2>&1 && echo 1 || echo 0")"

step "realtime: back to polling, and Reverb is stopped again"
in_container "cd '$APP_DIR' \
    && sed -i 's/^BROADCAST_CONNECTION=.*/BROADCAST_CONNECTION=log/' .env \
    && sed -i 's/^VITE_REALTIME=.*/VITE_REALTIME=polling/' .env \
    && SKIP_PULL=1 bash deploy/deploy.sh"
check "hq-reverb stopped again" 1 \
    "$(in_container 'supervisorctl status hq-reverb | grep -cE "STOPPED|not started" || true')"
check "the polling build ships no socket client again" 0 \
    "$(in_container "ls $APP_DIR/public/build/assets/pusher-*.js > /dev/null 2>&1 && echo 1 || echo 0")"

step "shellcheck"
in_container "apt-get install -y -qq --no-install-recommends shellcheck > /dev/null"
if shellcheck_out="$(in_container "shellcheck /src/deploy/install.sh /src/deploy/deploy.sh /src/deploy/setup-deploy-key.sh /src/deploy/setup-actions-key.sh /src/deploy/test/run-install-test.sh /src/deploy/test/run-reverb-test.sh /src/deploy/test/http-login-check.sh" 2>&1)"; then
    shellcheck_out="${shellcheck_out}clean"
fi
echo "$shellcheck_out"
check "shellcheck" clean "$(tail -n 1 <<<"$shellcheck_out")"

step "result"
if [ "$FAILURES" -eq 0 ]; then
    echo "RESULT: PASS (all checks passed)"
else
    echo "RESULT: FAIL ($FAILURES check(s) failed)"
    exit 1
fi
