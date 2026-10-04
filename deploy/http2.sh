#!/usr/bin/env bash
# Turn on HTTP/2 for goodERP's https server block (2026-10-04, "loading … still slow").
#
# Certbot writes `listen 443 ssl;` with no http2, so every phone opened up to six separate TLS
# connections — six slow handshakes when the server is in Dallas and the team in Bangladesh.
# HTTP/2 carries every request of a page over ONE connection.
#
# Safe to run any number of times: does nothing when http2 is already on or there is no https
# block yet, and puts the file back if `nginx -t` refuses the change.
#
#   bash deploy/http2.sh [/etc/nginx/sites-available/goodtechies-hq]
set -euo pipefail

SITE_FILE="${1:-/etc/nginx/sites-available/goodtechies-hq}"

if [ ! -f "$SITE_FILE" ]; then
    echo "http2: no $SITE_FILE, skipping"
    exit 0
fi

if grep -Eq 'listen[^;#]*443[^;#]*http2|^[[:space:]]*http2[[:space:]]+on;' "$SITE_FILE"; then
    echo "http2: already on"
    exit 0
fi

if ! grep -Eq 'listen[[:space:]]+(\[::\]:)?443[[:space:]]+ssl' "$SITE_FILE"; then
    echo "http2: no https listener yet (run Certbot first), skipping"
    exit 0
fi

backup="$(mktemp)"
cp -p "$SITE_FILE" "$backup"
sed -i -E 's/(listen[[:space:]]+(\[::\]:)?443[[:space:]]+ssl)([[:space:];])/\1 http2\3/' "$SITE_FILE"

if nginx -t > /dev/null 2>&1; then
    systemctl reload nginx 2> /dev/null || service nginx reload
    echo "http2: on"
else
    cp -p "$backup" "$SITE_FILE"
    echo "http2: nginx -t refused the change; the site file is unchanged" >&2
fi

rm -f "$backup"
