#!/usr/bin/env bash
# GoodTechies HQ: answer ONLY the ERP's own domain; refuse every other name.
#
# Why: deploy/install.sh removes Nginx's stock default site, which leaves the ERP as the only
# server block. Nginx sends a request for a name it does not know (the VPS provider's free
# hostname, e.g. host.onepagefolio.com, or the bare IP) to the first block it has - so the
# ERP answered every name that points at this server.
#
# This adds /etc/nginx/sites-available/00-catch-all: a `default_server` on 80 and 443 that
# closes the connection (444) for any name no other block claims. The ERP block is not touched.
# deploy-live.bat / live-deploy.sh / http2.sh / a re-run of install.sh leave this file alone.
#
# Run from Windows with block-other-domains.bat, or on the server:
#   bash deploy/block-other-domains.sh            add the block (safe to re-run)
#   UNDO=1 bash deploy/block-other-domains.sh     remove it again
#
# Exit codes: 0 done, 1 stopped before changing anything, 2 change failed and was undone.

ERP_DOMAIN="${ERP_DOMAIN:-erp.goodtechies.com}"
TEST_NAME="${TEST_NAME:-host.onepagefolio.com}"
UNDO="${UNDO:-0}"

CONF=/etc/nginx/sites-available/00-catch-all
LINK=/etc/nginx/sites-enabled/00-catch-all
CERT_DIR=/etc/nginx/catch-all

ok()   { echo "    [ok] $*"; }
warn() { echo "    [warn] $*"; }
stop() { echo "    [X] $*"; echo "    Nothing was changed."; exit 1; }

reload_nginx() { systemctl reload nginx 2> /dev/null || service nginx reload; }

# ERP still answers? Ask Nginx on this box directly, with the real name, over https.
erp_status() {
    curl --noproxy "*" -sk -o /dev/null -w '%{http_code}' --max-time 15 \
        --resolve "$ERP_DOMAIN:443:127.0.0.1" "https://$ERP_DOMAIN/" 2> /dev/null || true
}

undo_change() {
    rm -f "$LINK"
    if nginx -t > /dev/null 2>&1; then reload_nginx; fi
}

[ "$(id -u)" = "0" ] || stop "Run this as root."
command -v nginx > /dev/null 2>&1 || stop "Nginx is not installed on this server."

# ---------- undo ----------
if [ "$UNDO" = "1" ]; then
    echo "Removing the catch-all (every name will reach the ERP again)..."
    rm -f "$LINK" "$CONF"
    nginx -t || { echo "    [X] nginx -t fails for another reason; look above."; exit 2; }
    reload_nginx
    ok "Removed. Nginx reloaded."
    exit 0
fi

# ---------- checks before changing anything ----------
echo "Checking the server..."
SITE_FILE=/etc/nginx/sites-available/goodtechies-hq
[ -f "$SITE_FILE" ] || stop "$SITE_FILE is missing - this is not the server deploy-live.bat deploys to."
grep -qE "server_name[^;]*[[:space:]]${ERP_DOMAIN//./\\.}([[:space:];]|$)" "$SITE_FILE" \
    || stop "$SITE_FILE does not serve $ERP_DOMAIN; refusing to block everything else."
ok "ERP site found for $ERP_DOMAIN"

others="$(grep -lE 'listen[^;#]*default_server' /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf 2> /dev/null \
    | grep -v '00-catch-all' || true)"
if [ -n "$others" ]; then
    echo "    Another site is already the default:"
    echo "$others" | sed 's/^/        /'
    stop "Two defaults cannot exist on one port. Remove default_server from that file first."
fi
ok "No other default site"

before="$(erp_status)"
case "$before" in
    2??|3??) ok "ERP answers now (HTTP $before)" ;;
    *) warn "ERP did not answer cleanly before the change (HTTP ${before:-none}); continuing, the same check runs after." ;;
esac

# ---------- the change ----------
echo "Adding the catch-all..."
mkdir -p "$CERT_DIR"
chmod 700 "$CERT_DIR"
if [ ! -s "$CERT_DIR/cert.pem" ] || [ ! -s "$CERT_DIR/key.pem" ]; then
    # Throw-away certificate: only used to close https connections for unknown names.
    openssl req -x509 -nodes -newkey rsa:2048 -days 3650 -subj "/CN=invalid" \
        -keyout "$CERT_DIR/key.pem" -out "$CERT_DIR/cert.pem" > /dev/null 2>&1 \
        || stop "openssl could not create the placeholder certificate."
    chmod 600 "$CERT_DIR/key.pem"
fi

cat > "$CONF" <<'EOF'
# Written by deploy/block-other-domains.sh.
# Every name this server does not host (the VPS provider's hostname, the bare IP, anything
# else pointed here) lands on this block and the connection is closed without an answer.
# erp.goodtechies.com has its own server block, which Nginx always prefers over a default.
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
    server_name _;

    ssl_certificate     /etc/nginx/catch-all/cert.pem;
    ssl_certificate_key /etc/nginx/catch-all/key.pem;

    access_log off;
    return 444;
}
EOF
if [ ! -e /proc/net/if_inet6 ]; then
    sed -i '/listen \[::\]/d' "$CONF"
    echo "    no IPv6 on this host, IPv4 listeners only"
fi
ln -sfn "$CONF" "$LINK"

if ! nginx -t; then
    undo_change
    echo "    [X] Nginx refused the new config (reason above). It was removed again; the ERP is untouched."
    exit 2
fi
reload_nginx
sleep 1

# ---------- check the result ----------
echo "Checking the result..."
after="$(erp_status)"
case "$after" in
    2??|3??) ok "$ERP_DOMAIN still works (HTTP $after)" ;;
    *)
        case "$before" in
            2??|3??)
                undo_change
                echo "    [X] $ERP_DOMAIN stopped answering (HTTP ${after:-none}), so the change was undone."
                exit 2
                ;;
            *)
                # It did not answer before either, so the catch-all is not the cause: keep it.
                warn "$ERP_DOMAIN gives HTTP ${after:-none}, the same kind of answer as before the change."
                warn "That problem is not caused by this block; open the site and check."
                ;;
        esac
        ;;
esac

curl --noproxy "*" -s -o /dev/null --max-time 10 -H "Host: $TEST_NAME" "http://127.0.0.1/" > /dev/null 2>&1
rc=$?
if [ "$rc" = "52" ]; then
    ok "$TEST_NAME is refused (connection closed)"
else
    warn "$TEST_NAME test gave curl code $rc (52 = refused); check it from the PC."
fi

echo
ok "Done. Only $ERP_DOMAIN is served. Undo any time: block-other-domains.bat undo"
exit 0
