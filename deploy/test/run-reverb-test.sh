#!/usr/bin/env bash
# Proves the Phase 6 realtime kit: the Supervisor and Nginx templates, and a REAL Reverb
# process with a real websocket and a real broadcast.
#
#   deploy/test/run-reverb-test.sh
#
# Environment:
#   APP_DIR    application directory (default: the parent of this script's directory)
#   PORT       loopback port for the test Reverb (default: a free one above 18080)
#   LOG_FILE   where Reverb's own output goes (default: $TMPDIR/hq-reverb-test-<pid>.log)
#
# It needs no Docker and no root, which is why it is the one to run first — the full
# deploy/test/run-install-test.sh proves the same things inside a fresh Ubuntu container and
# takes several minutes. See docs/runbooks/realtime.md.
#
# Nothing here touches .env or the database: Reverb is started with its credentials in the
# environment, on a port of its own, and killed on the way out.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
DEPLOY_DIR="$APP_DIR/deploy"
LOG_FILE="${LOG_FILE:-${TMPDIR:-/tmp}/hq-reverb-test-$$.log}"
RENDER_DIR=""
REVERB_PID=""

APP_ID="hqtest"
APP_KEY="hqtestkey"
APP_SECRET="hqtestsecret"

FAILURES=0

step() {
    echo
    echo "==> $*"
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

contains() {
    local name="$1" needle="$2" haystack="$3"
    if grep -qF -- "$needle" <<<"$haystack"; then
        echo "PASS  $name"
    else
        echo "FAIL  $name (missing: $needle)"
        FAILURES=$((FAILURES + 1))
    fi
}

cleanup() {
    if [ -n "$REVERB_PID" ] && kill -0 "$REVERB_PID" 2> /dev/null; then
        kill "$REVERB_PID" 2> /dev/null || true
        wait "$REVERB_PID" 2> /dev/null || true
    fi
    [ -z "$RENDER_DIR" ] || rm -rf "$RENDER_DIR"
}
trap cleanup EXIT

# listen_addrs PORT: the local addresses anything is LISTENING on for PORT, one per line.
#
# `ss` on the VPS (install.sh installs iproute2 and uses the same check in assert_local_only),
# and a /proc fallback everywhere else — a developer's container may have no iproute2 at all,
# and a check that silently finds nothing would turn "Reverb is not running" into a pass.
listen_addrs() {
    local port="$1" want file field_local field_state hex hexport

    if command -v ss > /dev/null 2>&1; then
        ss -Hltn "sport = :$port" | awk '{print $4}'
        return 0
    fi

    # /proc/net/tcp: state 0A is LISTEN, and the local address is hex with the IPv4 bytes
    # reversed. Parsed in bash rather than awk on purpose — Debian and Ubuntu ship **mawk**,
    # which has no `strtonum()`, so an awk version of this silently printed nothing and turned
    # "Reverb is not running" into a pass. (It did.)
    want="$(printf '%04X' "$port")"

    for file in /proc/net/tcp /proc/net/tcp6; do
        [ -r "$file" ] || continue

        while read -r _ field_local _ field_state _; do
            [ "$field_state" = "0A" ] || continue

            hexport="${field_local##*:}"
            [ "${hexport^^}" = "$want" ] || continue

            hex="${field_local%%:*}"

            if [ "${#hex}" -eq 8 ]; then
                printf '%d.%d.%d.%d:%d\n' \
                    "0x${hex:6:2}" "0x${hex:4:2}" "0x${hex:2:2}" "0x${hex:0:2}" "$port"
            elif [ "$hex" = "00000000000000000000000001000000" ]; then
                printf '[::1]:%d\n' "$port"
            else
                printf '[%s]:%d\n' "$hex" "$port"
            fi
        done < <(tail -n +2 "$file")
    done
}

free_port() {
    local candidate
    for candidate in $(seq 18080 18140); do
        [ -z "$(listen_addrs "$candidate")" ] && {
            echo "$candidate"
            return 0
        }
    done
    echo "ERROR: no free port between 18080 and 18140" >&2
    exit 1
}

PORT="${PORT:-$(free_port)}"

step "test setup"
echo "date:    $(date -u '+%Y-%m-%d %H:%M UTC')"
echo "app:     $APP_DIR"
echo "port:    $PORT (loopback)"
echo "log:     $LOG_FILE"

# ---------------------------------------------------------------------------
# 1. The templates, rendered the way install.sh renders them.
# ---------------------------------------------------------------------------

step "render the Supervisor and Nginx templates"
RENDER_DIR="$(mktemp -d)"
for template in supervisor/hq-reverb.conf nginx.conf; do
    [ -f "$DEPLOY_DIR/$template" ] || {
        echo "FAIL  missing $DEPLOY_DIR/$template"
        FAILURES=$((FAILURES + 1))
        continue
    }
    sed -e "s|__APP_DIR__|/var/www/goodtechies-hq|g" -e "s|__DOMAIN__|hq.test|g" \
        "$DEPLOY_DIR/$template" > "$RENDER_DIR/$(basename "$template")"
done

supervisor_conf="$(cat "$RENDER_DIR/hq-reverb.conf")"
nginx_conf="$(cat "$RENDER_DIR/nginx.conf")"

check "no placeholder left in hq-reverb.conf" 0 "$(grep -c '__APP_DIR__' <<<"$supervisor_conf" || true)"
contains "hq-reverb runs reverb:start" "artisan reverb:start" "$supervisor_conf"
contains "hq-reverb binds the loopback" -- "--host=127.0.0.1" "$supervisor_conf"
contains "hq-reverb runs as www-data" "user=www-data" "$supervisor_conf"
# autostart=false IS the polling mode: install.sh and deploy.sh start it when .env asks.
contains "hq-reverb is started by .env, not by Supervisor" "autostart=false" "$supervisor_conf"
contains "install.sh starts hq-reverb when the connection is reverb" "supervisorctl start hq-reverb" "$(cat "$DEPLOY_DIR/install.sh")"
contains "deploy.sh stops hq-reverb in the polling mode" "supervisorctl stop hq-reverb" "$(cat "$DEPLOY_DIR/deploy.sh")"

contains "nginx proxies the websocket" "location ^~ /app/" "$nginx_conf"
contains "nginx proxies the publish API" "location ^~ /apps/" "$nginx_conf"
contains "nginx sends the upgrade header" 'proxy_set_header Upgrade $http_upgrade;' "$nginx_conf"
contains "nginx sends the connection header" 'proxy_set_header Connection "Upgrade";' "$nginx_conf"
contains "nginx proxies to the loopback Reverb" "proxy_pass http://127.0.0.1:8080;" "$nginx_conf"
# The one that bites: a read timeout shorter than Reverb's 60 s ping drops every idle tab.
check "nginx read timeout outlives the 60 s ping" 2 \
    "$(grep -c 'proxy_read_timeout 3600s;' <<<"$nginx_conf" || true)"

step "the environment template carries both halves of the switch"
env_example="$(cat "$DEPLOY_DIR/.env.production.example")"
contains ".env.production.example sets BROADCAST_CONNECTION" "BROADCAST_CONNECTION=" "$env_example"
contains ".env.production.example sets VITE_REALTIME" "VITE_REALTIME=" "$env_example"
contains ".env.production.example carries the Reverb credentials" "REVERB_APP_SECRET=" "$env_example"
contains ".env.production.example binds Reverb to the loopback" "REVERB_SERVER_HOST=127.0.0.1" "$env_example"

# ---------------------------------------------------------------------------
# 2. A real Reverb, on a real port.
# ---------------------------------------------------------------------------

step "start Reverb on 127.0.0.1:$PORT"
# `exec`, so that $! is PHP's own pid and not a wrapping subshell's — otherwise the cleanup
# trap kills the wrapper, PHP keeps the port, and the NEXT run of this script fails with
# EADDRINUSE while looking like a Reverb that would not start. (It did.)
(
    cd "$APP_DIR" || exit 1
    exec env REVERB_APP_ID="$APP_ID" REVERB_APP_KEY="$APP_KEY" REVERB_APP_SECRET="$APP_SECRET" \
        REVERB_HOST=127.0.0.1 REVERB_PORT="$PORT" REVERB_SCHEME=http \
        BROADCAST_CONNECTION=reverb APP_URL="http://127.0.0.1" \
        php artisan reverb:start --host=127.0.0.1 --port="$PORT"
) > "$LOG_FILE" 2>&1 &
REVERB_PID=$!

addrs=""
for _ in $(seq 1 30); do
    addrs="$(listen_addrs "$PORT" || true)"
    [ -n "$addrs" ] && break
    sleep 1
done
echo "listening on: $(tr '\n' ' ' <<<"${addrs:-nothing}")"
check "Reverb is listening" 1 "$([ -n "$addrs" ] && echo 1 || echo 0)"
# The same assertion PostgreSQL and Redis get in install.sh: Nginx is the only public listener.
check "Reverb listens on the loopback only" 0 \
    "$(grep -Ec -v '^(127\.0\.0\.1|\[::1\]):' <<<"$addrs" || true)"

step "the websocket endpoint answers"
# Reverb refuses a plain GET on /app/{key} — that refusal is the proof it is routing. A 502
# would mean nothing is behind the port at all.
ws_status="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/app/$APP_KEY")"
check "GET /app/{key} is answered, not unreachable" 1 \
    "$([ "$ws_status" != "000" ] && [ "$ws_status" != "502" ] && echo 1 || echo 0)"
echo "  (status $ws_status — a request that is not a websocket upgrade is meant to be refused;"
echo "   000 means nothing is on the port, and 502 is what Nginx says when Reverb is stopped)"

step "open a real websocket, publish a real broadcast, and read the frame back"
# `ws` comes with pusher-js. The script subscribes to a PUBLIC channel — channel AUTH is the
# application's business and is asserted in tests/Feature/Realtime/BroadcastAuthTest.php; what
# is under test HERE is the transport: does a frame published through the HTTP API come out of
# the websocket on the other side.
ws_result="$(
    cd "$APP_DIR" && PORT="$PORT" APP_ID="$APP_ID" APP_KEY="$APP_KEY" APP_SECRET="$APP_SECRET" node --input-type=module -e '
import WebSocket from "ws";
import crypto from "node:crypto";

const { PORT, APP_ID, APP_KEY, APP_SECRET } = process.env;
// The Origin header matters: config/reverb.php restricts `allowed_origins` to the APP_URL
// host instead of `*`, so a handshake without one is refused — which is the point of the
// setting and is also why this line is not a detail.
const socket = new WebSocket(`ws://127.0.0.1:${PORT}/app/${APP_KEY}?protocol=7&client=smoke&version=1`, { origin: "http://127.0.0.1" });
const done = (text) => { console.log(text); socket.close(); process.exit(0); };
const fail = (text) => { console.log(text); process.exit(0); };

setTimeout(() => fail("TIMEOUT"), 15000);

socket.on("error", (error) => fail(`ERROR ${error.message}`));

socket.on("message", async (raw) => {
    const frame = JSON.parse(raw.toString());

    if (frame.event === "pusher:connection_established") {
        socket.send(JSON.stringify({ event: "pusher:subscribe", data: { channel: "smoke" } }));

        const body = JSON.stringify({ name: "smoke.event", channel: "smoke", data: JSON.stringify({ ok: true }) });
        const bodyMd5 = crypto.createHash("md5").update(body).digest("hex");
        const params = `auth_key=${APP_KEY}&auth_timestamp=${Math.floor(Date.now() / 1000)}&auth_version=1.0&body_md5=${bodyMd5}`;
        const signature = crypto
            .createHmac("sha256", APP_SECRET)
            .update(`POST\n/apps/${APP_ID}/events\n${params}`)
            .digest("hex");

        const response = await fetch(`http://127.0.0.1:${PORT}/apps/${APP_ID}/events?${params}&auth_signature=${signature}`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body,
        });

        if (!response.ok) {
            fail(`PUBLISH ${response.status}`);
        }

        return;
    }

    if (frame.event === "smoke.event") {
        done("DELIVERED");
    }
});
' 2>&1 | tail -n 1
)"
check "a published event comes back out of the websocket" DELIVERED "$ws_result"

step "Reverb logged no fatal error"
check "reverb:start is still running" 1 "$(kill -0 "$REVERB_PID" 2> /dev/null && echo 1 || echo 0)"

step "result"
if [ "$FAILURES" -eq 0 ]; then
    echo "RESULT: PASS (all checks passed)"
else
    echo "RESULT: FAIL ($FAILURES check(s) failed). Reverb's own log: $LOG_FILE"
    exit 1
fi
