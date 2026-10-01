#!/usr/bin/env bash
# Proves that people can sign in over PLAIN HTTP on an IP install, the way a browser does it.
# Runs inside the install-test container (deploy/test/run-install-test.sh), as root:
#
#   bash deploy/test/http-login-check.sh <host> [app-dir]
#
# curl plays the browser: a cookie jar, the XSRF-TOKEN cookie echoed as X-XSRF-TOKEN, and —
# the point of the test — curl never sends a cookie marked Secure over http, exactly like a
# browser. So if the session cookie were still Secure, every step after the login POST would
# arrive without a session and fail here.
#
# Two people:
#   - an Employee (no 2FA): password -> dashboard.
#   - an Admin, who must enrol 2FA at first sign-in in production: password -> enrolment page
#     (QR code and secret) -> a TOTP code computed from that secret -> recovery codes ->
#     the Admin dashboard.
set -euo pipefail

HOST="${1:?usage: http-login-check.sh <host> [app-dir]}"
APP_DIR="${2:-/var/www/goodtechies-hq}"
BASE="http://127.0.0.1"
FAILURES=0
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

env_get() {
    sed -n "s/^$1=//p" "$APP_DIR/.env" | tail -n 1 | sed -e 's/^"\(.*\)"$/\1/'
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

# req JAR METHOD PATH [curl args...]: prints "<status> <redirect path>"; the body is in JAR.body
# and the response headers in JAR.headers.
req() {
    local jar="$1" method="$2" path="$3" xsrf location status
    shift 3
    xsrf="$(awk '$6 == "XSRF-TOKEN" { print $7 }' "$jar" 2> /dev/null | tail -n 1 || true)"
    # shellcheck disable=SC2016 # PHP/nginx/JS source, expanded by that program and not by bash
    xsrf="$(php -r 'echo urldecode($argv[1] ?? "");' -- "$xsrf")"
    local args=(-s -o "$jar.body" -D "$jar.headers" -b "$jar" -c "$jar" -H "Host: $HOST" -X "$method")
    [ -z "$xsrf" ] || args+=(-H "X-XSRF-TOKEN: $xsrf")
    read -r status location < <(curl "${args[@]}" -w '%{http_code} %{redirect_url}\n' "$@" "$BASE$path")
    location="${location#http://"$HOST"}"
    echo "$status ${location:--}"
}

PASSWORD="$(env_get SEED_PASSWORD)"
[ -n "$PASSWORD" ] || { echo "FAIL  SEED_PASSWORD is not in $APP_DIR/.env"; exit 1; }

echo "== transport settings in .env"
check "APP_URL is http on the IP" "http://$HOST" "$(env_get APP_URL)"
check "SESSION_SECURE_COOKIE is false" "false" "$(env_get SESSION_SECURE_COOKIE)"

echo "== Employee: password sign-in over http"
jar="$WORK_DIR/employee"
check "GET /login" "200 -" "$(req "$jar" GET /login)"
session_cookie="$(grep -i '^set-cookie:' "$jar.headers" | grep -iv 'XSRF-TOKEN' | head -n 1)"
check "the session cookie is not marked Secure" 0 "$(grep -ci '; *secure' <<<"$session_cookie" || true)"
check "POST /login (Employee)" "302 /" \
    "$(req "$jar" POST /login --data-urlencode "email=$(env_get SEED_YASEEN_EMAIL)" --data-urlencode "password=$PASSWORD")"
check "GET / sends the Employee to their shell" "302 /employee/dashboard" "$(req "$jar" GET /)"
check "GET /employee/dashboard with the session" "200 -" "$(req "$jar" GET /employee/dashboard)"

echo "== Admin: password, then 2FA enrolment, over http"
jar="$WORK_DIR/admin"
req "$jar" GET /login > /dev/null
check "POST /login (Admin)" "302 /" \
    "$(req "$jar" POST /login --data-urlencode "email=$(env_get SEED_SHAHADAT_EMAIL)" --data-urlencode "password=$PASSWORD")"
check "GET /admin/dashboard asks for 2FA enrolment first" "302 /two-factor/enrol" "$(req "$jar" GET /admin/dashboard)"
check "GET /two-factor/enrol" "200 -" "$(req "$jar" GET /two-factor/enrol)"
check "the enrolment page carries the QR code" 1 "$(grep -c 'qrSvg' "$jar.body" || true)"
secret="$(grep -oE '(&quot;|")secret(&quot;|"):(&quot;|")[A-Z2-7]+' "$jar.body" | grep -oE '[A-Z2-7]+$' | head -n 1 || true)"
check "the enrolment page carries a base32 secret" 1 "$([ -n "$secret" ] && echo 1 || echo 0)"
# shellcheck disable=SC2016 # PHP/nginx/JS source, expanded by that program and not by bash
code="$(php -r 'require $argv[1] . "/vendor/autoload.php"; echo (new PragmaRX\Google2FA\Google2FA())->getCurrentOtp($argv[2]);' -- "$APP_DIR" "$secret")"
check "POST /two-factor/enrol with a TOTP code" "302 /two-factor/recovery-codes" \
    "$(req "$jar" POST /two-factor/enrol --data-urlencode "code=$code")"
check "GET /two-factor/recovery-codes" "200 -" "$(req "$jar" GET /two-factor/recovery-codes)"
check "GET /admin/dashboard after enrolment" "200 -" "$(req "$jar" GET /admin/dashboard)"

if [ "$FAILURES" -eq 0 ]; then
    echo "HTTP LOGIN: PASS"
else
    echo "HTTP LOGIN: FAIL ($FAILURES)"
    exit 1
fi
