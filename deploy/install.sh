#!/usr/bin/env bash
# GoodTechies HQ: first install on a fresh Ubuntu 22.04 or 24.04 VPS. Run as root:
#
#   # with a domain: https, Let's Encrypt, HSTS
#   DOMAIN=hq.example.com REPO_URL=git@github.com:org/goodtechies-hq.git \
#   CERTBOT_EMAIL=ops@example.com bash install.sh
#
#   # no domain yet: plain http on the server's IP address (no certificate, no HSTS)
#   DOMAIN=203.0.113.10 REPO_URL=git@github.com:org/goodtechies-hq.git bash install.sh
#
# Re-running is safe: packages, repos, roles and templates are re-applied; an
# existing checkout, .env (passwords, APP_KEY) and seeded users are kept. Re-running
# with a host name in DOMAIN (and CERTBOT_EMAIL) is how an IP install moves to https.
# It never touches another site: it adds only its own Nginx site and PHP-FPM pool,
# and stops before changing anything when a panel or a non-Nginx web server is found.
# Parameters and the reasoning behind each step: docs/runbooks/install.md and
# docs/runbooks/vps-quickstart.md.
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/goodtechies-hq}"
REPO_URL="${REPO_URL:-}"
BRANCH="${BRANCH:-main}"
DOMAIN="${DOMAIN:-}"
DB_NAME="${DB_NAME:-goodtechies_hq}"
DB_MIGRATOR_PASSWORD="${DB_MIGRATOR_PASSWORD:-}"
DB_APP_PASSWORD="${DB_APP_PASSWORD:-}"
DB_RO_PASSWORD="${DB_RO_PASSWORD:-}"
SEED_PASSWORD="${SEED_PASSWORD:-}"
# Realtime (Phase 6). REALTIME=reverb installs with the websocket on; anything else installs
# the polling mode, which is what a first install gets and what Part B calls acceptable.
REALTIME="${REALTIME:-polling}"
REVERB_APP_ID="${REVERB_APP_ID:-}"
REVERB_APP_KEY="${REVERB_APP_KEY:-}"
REVERB_APP_SECRET="${REVERB_APP_SECRET:-}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"
SKIP_CERTBOT="${SKIP_CERTBOT:-0}"
SKIP_FIREWALL="${SKIP_FIREWALL:-0}"
# 1 = serve plain http even when DOMAIN is a host name. A bare IPv4 DOMAIN implies it.
HTTP_ONLY="${HTTP_ONLY:-0}"
# 1 = continue past the preflight's stop conditions (a panel, a non-Nginx web server on
# :80/:443, a name clash) and enable ufw although other services listen publicly.
FORCE="${FORCE:-0}"
# Low-memory profile: auto (below 2 GB of RAM), 1 or 0.
LOW_MEMORY="${LOW_MEMORY:-auto}"
SKIP_SWAP="${SKIP_SWAP:-0}"
SWAP_SIZE_MB="${SWAP_SIZE_MB:-2048}"
# Demo data in the seed: empty = whatever .env says (the template says 0).
SEED_DEMO="${SEED_DEMO:-}"
NODE_MAJOR="${NODE_MAJOR:-22}"
# The port the Reverb process listens on, behind Nginx. Loopback only; see deploy/nginx.conf.
REVERB_PORT="${REVERB_PORT:-8080}"

APP_USER="www-data"
PHP_VERSION="8.3"
PG_VERSION="16"
SITE_NAME="goodtechies-hq"
# Our own PHP-FPM pool and socket, so the sizing below never changes another site's pool.
# deploy/nginx.conf passes PHP to this socket.
PHP_FPM_SOCK="/run/php/php$PHP_VERSION-fpm-$SITE_NAME.sock"
SEED_EMAIL_KEYS=(SEED_SHAHADAT_EMAIL SEED_FARUK_EMAIL SEED_TAPU_EMAIL SEED_YASEEN_EMAIL SEED_ACCOUNTANT_EMAIL)

export DEBIAN_FRONTEND=noninteractive
export COMPOSER_ALLOW_SUPERUSER=1

GENERATED_SEED_PASSWORD=""

step() {
    echo
    echo "==> $*"
}

warn() {
    echo "WARNING: $*" >&2
}

die() {
    echo "ERROR: $*" >&2
    exit 1
}

# Run a service action with systemd, or with `service` where systemd is absent (containers).
# "enable" means enable-and-start under systemd and start-unless-running otherwise.
svc() {
    local action="$1" name="$2"
    if [ -d /run/systemd/system ]; then
        if [ "$action" = "enable" ]; then
            systemctl enable --now "$name"
        else
            systemctl "$action" "$name"
        fi
    elif [ "$action" = "enable" ]; then
        service "$name" status > /dev/null 2>&1 || service "$name" start
    else
        service "$name" "$action"
    fi
}

apt_install() {
    apt-get install -y --no-install-recommends "$@"
}

gen_secret() {
    openssl rand -base64 24 | tr -d '/+='
}

as_postgres() {
    runuser -u postgres -- "$@"
}

psql_value() {
    as_postgres psql -XtAq -d "$1" -c "$2"
}

# env_get KEY: print KEY's value from .env without surrounding quotes (empty when absent).
env_get() {
    local file="$APP_DIR/.env"
    [ -f "$file" ] || return 0
    KEY="$1" awk -F= '
        $1 == ENVIRON["KEY"] {
            sub(/^[^=]*=/, "")
            if ($0 ~ /^".*"$/ || $0 ~ /^'\''.*'\''$/) { $0 = substr($0, 2, length($0) - 2) }
            value = $0
        }
        END { printf "%s", value }
    ' "$file"
}

# env_set KEY VALUE: replace KEY's line in .env, or append it. Quotes the value when needed.
env_set() {
    local key="$1" value="$2" file="$APP_DIR/.env"
    if [[ ! "$value" =~ ^[A-Za-z0-9_.:/@+=-]*$ ]]; then
        value="${value//\\/\\\\}"
        value="${value//\"/\\\"}"
        value="${value//\$/\\\$}"
        value="\"$value\""
    fi
    (umask 077 && KEY="$key" VALUE="$value" awk '
        BEGIN { key = ENVIRON["KEY"]; line = key "=" ENVIRON["VALUE"] }
        index($0, key "=") == 1 { print line; found = 1; next }
        { print }
        END { if (!found) print line }
    ' "$file" > "$file.tmp")
    cat "$file.tmp" > "$file"
    rm -f "$file.tmp"
}

# render TEMPLATE DEST: copy a deploy/ template with __APP_DIR__ and __DOMAIN__ substituted.
render() {
    sed -e "s|__APP_DIR__|$APP_DIR|g" -e "s|__DOMAIN__|$DOMAIN|g" "$1" > "$2"
    chmod 644 "$2"
}

# assert_local_only PORT NAME: fail when anything listens on PORT beyond the loopback addresses.
assert_local_only() {
    local port="$1" name="$2" addrs bad
    addrs="$(ss -Hltn "sport = :$port" | awk '{print $4}')"
    [ -n "$addrs" ] || die "$name is not listening on port $port"
    bad="$(grep -Ev '^(127\.0\.0\.1|\[::1\]):' <<<"$addrs" || true)"
    [ -z "$bad" ] || die "$name listens beyond localhost: $(tr '\n' ' ' <<<"$bad")"
    echo "$name listens on: $(tr '\n' ' ' <<<"$addrs")"
}

is_ipv4() {
    [[ "$1" =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]] || return 1
    local octet
    for octet in "${BASH_REMATCH[@]:1}"; do
        [ "$octet" -le 255 ] || return 1
    done
}

# write_if_changed DEST: write stdin to DEST; return 0 when the content changed, 1 when not.
write_if_changed() {
    local dest="$1" tmp
    tmp="$(mktemp)"
    cat > "$tmp"
    if [ -f "$dest" ] && cmp -s "$tmp" "$dest"; then
        rm -f "$tmp"
        return 1
    fi
    install -m 644 "$tmp" "$dest"
    rm -f "$tmp"
    return 0
}

# The stock /etc/nginx/sites-enabled/default: the package's symlink to its unmodified conffile.
# Anything else (edited, replaced, a real file) belongs to somebody and is left alone.
is_stock_nginx_default() {
    local link=/etc/nginx/sites-enabled/default target=/etc/nginx/sites-available/default expected actual
    [ -L "$link" ] || return 1
    [ "$(readlink -f "$link")" = "$(readlink -f "$target")" ] || return 1
    [ -f "$target" ] || return 1
    expected="$(dpkg-query -W -f='${Conffiles}\n' nginx-common 2> /dev/null \
        | awk '$1 == "/etc/nginx/sites-available/default" { print $2 }')"
    actual="$(md5sum < "$target" | awk '{print $1}')"
    [ -n "$expected" ] && [ "$expected" = "$actual" ]
}

remove_stock_nginx_default() {
    if is_stock_nginx_default; then
        rm -f /etc/nginx/sites-enabled/default
        echo "removed the stock sites-enabled/default (unmodified package file)"
    elif [ -e /etc/nginx/sites-enabled/default ]; then
        echo "kept /etc/nginx/sites-enabled/default: it is not the stock package file"
    fi
}

# ss_listeners [FILTER]: "port process[,process] address" per TCP listener. The process list
# is every name in ss's users:(...), e.g. "sshd,systemd" on a socket-activated ssh.
ss_listeners() {
    ss -Hltnp "$@" 2> /dev/null | awk '{
        addr = $4; port = addr; sub(/.*:/, "", port)
        names = ""; rest = $0
        while (match(rest, /\("[^"]+"/)) {
            name = substr(rest, RSTART + 2, RLENGTH - 3)
            if (index("," names ",", "," name ",") == 0) names = names (names == "" ? "" : ",") name
            rest = substr(rest, RSTART + RLENGTH)
        }
        print port, (names == "" ? "?" : names), addr
    }'
}

# listeners PORT: "process address" for every TCP listener on PORT.
listeners() {
    ss_listeners "sport = :$1" | awk '{ print $2, $3 }' | sort -u
}

# public_listeners: "port process" for every TCP port listening beyond the loopback.
public_listeners() {
    ss_listeners | awk '$3 !~ /^(127\.[0-9.]+|\[::1\]):/ { print $1, $2 }' | sort -u -k1,1n
}

# ssh_ports: every port ssh may be on, so enabling ufw can never lock us out: the live sshd
# listeners, `sshd -T` (a socket-activated ssh on Ubuntu 24.04 shows as systemd), and 22.
ssh_ports() {
    {
        public_listeners | awk '$2 ~ /(^|,)sshd(,|$)/ { print $1 }'
        sshd -T 2> /dev/null | awk '$1 == "port" { print $2 }' || true
        echo 22
    } | sort -un
}

# ---------------------------------------------------------------------------

step "check parameters"
[ "$(id -u)" -eq 0 ] || die "run install.sh as root"
[ -r /etc/os-release ] || die "cannot read /etc/os-release"
# shellcheck disable=SC1091
. /etc/os-release
case "${ID:-}:${VERSION_ID:-}" in
    ubuntu:22.04 | ubuntu:24.04) ;;
    *) die "unsupported OS ${PRETTY_NAME:-unknown}; use Ubuntu 22.04 or 24.04" ;;
esac
[ -n "$DOMAIN" ] || die "DOMAIN is required (e.g. DOMAIN=hq.example.com)"
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || die "DOMAIN must be a bare host name or IPv4 address"
[[ "$SWAP_SIZE_MB" =~ ^[0-9]+$ ]] || die "SWAP_SIZE_MB must be a number of megabytes"
[[ "$DB_NAME" =~ ^[a-z_][a-z0-9_]*$ ]] || die "DB_NAME must match ^[a-z_][a-z0-9_]*$"
if [ ! -f "$APP_DIR/artisan" ] && [ -z "$REPO_URL" ]; then
    die "REPO_URL is required because $APP_DIR does not hold the code yet"
fi
# http on a bare IP (or HTTP_ONLY=1): Let's Encrypt does not issue for an IP through this
# path, and a secure cookie is never sent over http, so this mode turns both off on purpose.
if is_ipv4 "$DOMAIN" || [ "$HTTP_ONLY" = "1" ]; then
    SCHEME="http"
    PUBLIC_PORT="80"
else
    SCHEME="https"
    PUBLIC_PORT="443"
fi
MEM_MB="$(awk '/^MemTotal:/ { print int($2 / 1024) }' /proc/meminfo)"
if [ "$LOW_MEMORY" = "auto" ]; then
    if [ "$MEM_MB" -lt 2000 ]; then LOW_MEMORY=1; else LOW_MEMORY=0; fi
fi
echo "Ubuntu $VERSION_ID, APP_DIR=$APP_DIR, DOMAIN=$DOMAIN, DB_NAME=$DB_NAME, BRANCH=$BRANCH"
echo "URL $SCHEME://$DOMAIN, RAM ${MEM_MB} MB, low-memory profile: $([ "$LOW_MEMORY" = "1" ] && echo on || echo off)"

step "preflight: what else runs on this server"
# Nothing is changed in this step. It lists what is already here, and stops the install when
# going on could break another site — unless FORCE=1.
STOP_REASONS=()

panels=()
[ -d /usr/local/cpanel ] && panels+=("cPanel/WHM (/usr/local/cpanel)")
[ -d /usr/local/psa ] && panels+=("Plesk (/usr/local/psa)")
[ -d /usr/local/hestia ] && panels+=("HestiaCP (/usr/local/hestia)")
[ -d /usr/local/vesta ] && panels+=("VestaCP (/usr/local/vesta)")
[ -d /www/server/panel ] && panels+=("aaPanel (/www/server/panel)")
[ -d /usr/local/CyberCP ] && panels+=("CyberPanel (/usr/local/CyberCP)")
[ -d /usr/local/directadmin ] && panels+=("DirectAdmin (/usr/local/directadmin)")
{ [ -x /usr/bin/clpctl ] || [ -d /home/clp ]; } && panels+=("CloudPanel (clpctl)")
[ -d /etc/webmin/virtual-server ] && panels+=("Virtualmin (/etc/webmin/virtual-server)")
if [ "${#panels[@]}" -gt 0 ]; then
    for panel in "${panels[@]}"; do
        echo "panel:       $panel"
    done
    STOP_REASONS+=("a hosting panel manages this server (${panels[*]}); add the site through the panel, or FORCE=1")
else
    echo "panel:       none found"
fi
[ -d /etc/webmin ] && [ ! -d /etc/webmin/virtual-server ] && echo "panel:       Webmin is installed (not a hosting panel; left alone)"

if command -v ss > /dev/null 2>&1; then
    for port in 80 443; do
        while read -r name addr; do
            [ -n "$name" ] || continue
            echo "port $port:    $name on $addr"
            if [ "$name" != "nginx" ]; then
                STOP_REASONS+=("$name already serves port $port; this kit runs Nginx there. Stop it or put this app behind it, or FORCE=1")
            fi
        done < <(listeners "$port")
    done
    other_ports="$(public_listeners | awk -v ssh=" $(ssh_ports | tr '\n' ' ') " \
        '$1 != 80 && $1 != 443 && index(ssh, " " $1 " ") == 0 { printf "%s(%s) ", $1, $2 }')"
    echo "public ports: ${other_ports:-none besides ssh/80/443}"
else
    warn "ss is not installed yet, so the port checks are skipped on this run"
fi
command -v apache2 > /dev/null 2>&1 && echo "apache2:     installed (see the port check above)"

other_sites=()
for site in /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf; do
    [ -e "$site" ] || continue
    case "$(basename "$site")" in
        "$SITE_NAME" | default) continue ;;
    esac
    other_sites+=("$site")
done
if [ "${#other_sites[@]}" -gt 0 ]; then
    for site in "${other_sites[@]}"; do
        names="$(grep -hoE '^\s*server_name\s+[^;]+' "$site" | sed -E 's/^\s*server_name\s+//' | tr '\n' ' ' | sed 's/ *$//' || true)"
        flag=""
        grep -qE 'listen[^;]*default_server' "$site" && flag=" [default_server]"
        echo "nginx site:  $site: ${names:-no server_name}$flag"
        if grep -qE "^\s*server_name[^;]*[[:space:]]${DOMAIN//./\\.}([[:space:];]|$)" "$site"; then
            STOP_REASONS+=("$site already answers for $DOMAIN; two sites cannot share a name, or FORCE=1")
        fi
    done
    echo "             Only /etc/nginx/sites-available/$SITE_NAME is added; those files are not touched."
else
    echo "nginx site:  no other sites"
fi
if [ -e /etc/nginx/sites-enabled/default ]; then
    if is_stock_nginx_default; then
        echo "nginx site:  sites-enabled/default is the stock file and will be removed"
    else
        echo "nginx site:  sites-enabled/default is NOT the stock file and will be kept"
    fi
fi
if [ "$SCHEME" = "http" ]; then
    # How an IP install coexists with other sites: Nginx picks the server block whose
    # server_name equals the Host header FIRST and uses a default_server only when none does.
    # Our block is `server_name <ip>`, so http://<ip> reaches this app even when another
    # site is the default_server, and the other sites keep their own names.
    echo "IP mode:     this site answers Host: $DOMAIN (an exact server_name beats any default_server);"
    echo "             requests for other names keep going to their own sites or to the default_server."
fi

if command -v mysqld > /dev/null 2>&1 || command -v mariadbd > /dev/null 2>&1; then
    echo "MySQL:       installed; left untouched (this app uses PostgreSQL)"
else
    echo "MySQL:       not installed"
fi
if command -v pg_lsclusters > /dev/null 2>&1; then
    while read -r ver cluster port status _; do
        [ -n "$ver" ] || continue
        echo "PostgreSQL:  cluster $ver/$cluster on port $port ($status)"
        if [ "$port" = "5432" ] && [ "$ver" != "$PG_VERSION" ]; then
            STOP_REASONS+=("PostgreSQL $ver already holds port 5432; this app needs PostgreSQL $PG_VERSION there, or FORCE=1")
        fi
    done < <(pg_lsclusters -h 2> /dev/null)
    echo "             the memory settings below go in $PG_VERSION/main's conf.d and apply to every database in that cluster"
fi
if command -v node > /dev/null 2>&1; then
    echo "Node.js:     $(node -v) installed$([ "$(node -p 'process.versions.node.split(".")[0]' 2> /dev/null || echo 0)" -lt "$NODE_MAJOR" ] && echo ", will be upgraded to $NODE_MAJOR (shared by every app on this box)")"
fi
if compgen -G "/etc/php/*/fpm/pool.d/*.conf" > /dev/null; then
    echo "PHP-FPM:     pools $(basename -a /etc/php/*/fpm/pool.d/*.conf | tr '\n' ' ')(ours is $SITE_NAME.conf)"
fi
disk_free_mb="$(df -Pm / | awk 'NR == 2 { print $4 }')"
echo "disk:        ${disk_free_mb} MB free on /"
[ "$disk_free_mb" -ge 3000 ] || warn "less than 3 GB free on /: packages, vendor/ and node_modules/ need about 2 GB"

if [ "${#STOP_REASONS[@]}" -gt 0 ]; then
    for reason in "${STOP_REASONS[@]}"; do
        echo "STOP: $reason" >&2
    done
    if [ "$FORCE" = "1" ]; then
        warn "FORCE=1, continuing anyway"
    else
        die "preflight found ${#STOP_REASONS[@]} reason(s) to stop; nothing was changed"
    fi
fi

step "memory: swap and kernel settings"
if [ "$LOW_MEMORY" != "1" ]; then
    echo "${MEM_MB} MB of RAM, no swap file or tuning needed"
elif [ "$SKIP_SWAP" = "1" ]; then
    echo "SKIP_SWAP=1, skipping the swap file"
elif [ -n "$(swapon --show --noheadings 2> /dev/null)" ]; then
    echo "swap already on: $(swapon --show --noheadings | awk '{print $1, $3}' | tr '\n' ' ')"
else
    if [ ! -f /swapfile ]; then
        fallocate -l "${SWAP_SIZE_MB}M" /swapfile 2> /dev/null \
            || dd if=/dev/zero of=/swapfile bs=1M count="$SWAP_SIZE_MB" status=none
        chmod 600 /swapfile
        mkswap /swapfile > /dev/null
        echo "created /swapfile (${SWAP_SIZE_MB} MB)"
    fi
    if swapon /swapfile; then
        grep -qE '^/swapfile[[:space:]]' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
        echo "swap on: /swapfile, persisted in /etc/fstab"
    else
        warn "swapon /swapfile failed (a container?); continuing without swap"
    fi
fi
if [ "$LOW_MEMORY" = "1" ]; then
    # Swap is for the build and for spikes, not for steady state: prefer RAM until it is short.
    echo "vm.swappiness=10" > /etc/sysctl.d/99-goodtechies-hq.conf
    sysctl -q -w vm.swappiness=10 2> /dev/null || warn "could not set vm.swappiness now (a container?); it applies at boot"
    echo "vm.swappiness=10 (persisted in /etc/sysctl.d/99-goodtechies-hq.conf)"
fi

step "apt base packages"
apt-get update
apt_install ca-certificates curl gnupg lsb-release software-properties-common \
    git unzip openssl iproute2 cron

step "PHP $PHP_VERSION"
if [ "$VERSION_ID" = "22.04" ]; then
    add-apt-repository -y ppa:ondrej/php
    apt-get update
    PHP_REDIS_PACKAGE="php$PHP_VERSION-redis"
else
    PHP_REDIS_PACKAGE="php-redis"
fi
apt_install "php$PHP_VERSION-fpm" "php$PHP_VERSION-cli" "php$PHP_VERSION-pgsql" "$PHP_REDIS_PACKAGE" \
    "php$PHP_VERSION-mbstring" "php$PHP_VERSION-xml" "php$PHP_VERSION-curl" "php$PHP_VERSION-zip" \
    "php$PHP_VERSION-intl" "php$PHP_VERSION-gd" "php$PHP_VERSION-bcmath"
# Match nginx client_max_body_size.
cat > "/etc/php/$PHP_VERSION/fpm/conf.d/99-goodtechies-hq.ini" <<'INI'
upload_max_filesize = 50M
post_max_size = 50M
expose_php = Off
INI
php -r 'echo "PHP ", PHP_VERSION, PHP_EOL;'
# Our own pool. `ondemand` on a small box: no idle workers held in RAM, at most five PHP
# requests at once (about 50 MB each), recycled every 300 requests so a leak cannot grow.
if [ "$LOW_MEMORY" = "1" ]; then
    fpm_pm="pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 10s
pm.max_requests = 300"
else
    fpm_pm="pm = dynamic
pm.max_children = 12
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 5
pm.max_requests = 500"
fi
write_if_changed "/etc/php/$PHP_VERSION/fpm/pool.d/$SITE_NAME.conf" <<POOL || true
; GoodTechies HQ: PHP-FPM pool, written by deploy/install.sh. Other sites' pools are not touched.
[$SITE_NAME]
user = $APP_USER
group = $APP_USER
listen = $PHP_FPM_SOCK
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
$fpm_pm
POOL
echo "PHP-FPM pool $SITE_NAME on $PHP_FPM_SOCK ($(grep -m1 '^pm = ' "/etc/php/$PHP_VERSION/fpm/pool.d/$SITE_NAME.conf"))"
svc enable "php$PHP_VERSION-fpm"
php-fpm"$PHP_VERSION" -t
# A reload, not a restart: it starts the new pool without dropping another site's requests.
svc reload "php$PHP_VERSION-fpm"

step "PostgreSQL $PG_VERSION"
if ! apt-cache show "postgresql-$PG_VERSION" > /dev/null 2>&1; then
    echo "postgresql-$PG_VERSION is not in the distro, adding the PGDG apt repository"
    install -d -m 755 /etc/apt/keyrings
    curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /etc/apt/keyrings/pgdg.asc
    echo "deb [signed-by=/etc/apt/keyrings/pgdg.asc] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list
    apt-get update
fi
apt_install "postgresql-$PG_VERSION" "postgresql-client-$PG_VERSION"
svc enable postgresql
# A drop-in, not an edit of postgresql.conf. The low-memory values fit 1 GB beside PHP, Redis
# and the build; shared_buffers and max_connections need a restart, so it only restarts on change.
if [ "$LOW_MEMORY" = "1" ]; then
    pg_conf="shared_buffers = 128MB
effective_cache_size = 384MB
work_mem = 4MB
maintenance_work_mem = 32MB
max_connections = 40
wal_buffers = 4MB"
else
    pg_conf="shared_buffers = 256MB
effective_cache_size = 1GB
work_mem = 8MB
maintenance_work_mem = 64MB"
fi
if write_if_changed "/etc/postgresql/$PG_VERSION/main/conf.d/90-$SITE_NAME.conf" <<PGCONF; then
# GoodTechies HQ: memory settings, written by deploy/install.sh.
$pg_conf
PGCONF
    svc restart postgresql
    echo "PostgreSQL memory settings applied and the server restarted"
else
    echo "PostgreSQL memory settings unchanged"
fi

step "Redis"
apt_install redis-server
svc enable redis-server
# A memory ceiling. volatile-lru evicts only keys with a TTL (cache entries) and never the
# queue's jobs, which have none.
if [ "$LOW_MEMORY" = "1" ]; then redis_max="96mb"; else redis_max="256mb"; fi
write_if_changed "/etc/redis/$SITE_NAME.conf" <<REDISCONF || true
# GoodTechies HQ: written by deploy/install.sh, included from /etc/redis/redis.conf.
maxmemory $redis_max
maxmemory-policy volatile-lru
REDISCONF
chown redis:redis "/etc/redis/$SITE_NAME.conf" 2> /dev/null || true
grep -qxF "include /etc/redis/$SITE_NAME.conf" /etc/redis/redis.conf \
    || echo "include /etc/redis/$SITE_NAME.conf" >> /etc/redis/redis.conf
# Applied live on every run, so Redis (and anything else using it) is never restarted for this;
# the include above is what makes it survive the next restart.
# (redis-cli exits 0 on an error reply, so the reply itself is checked.)
if [ "$(redis-cli config set maxmemory "$redis_max" 2>&1)" != "OK" ] \
    || [ "$(redis-cli config set maxmemory-policy volatile-lru 2>&1)" != "OK" ]; then
    svc restart redis-server
fi
echo "Redis maxmemory $(redis-cli config get maxmemory | tail -n 1) bytes, policy $(redis-cli config get maxmemory-policy | tail -n 1)"

step "Nginx, Supervisor, Certbot"
apt_install nginx supervisor certbot python3-certbot-nginx
# The stock default site is replaced by ours below (it also fails on hosts without IPv6).
# Only the unmodified package file: a default somebody edited is another site's, and stays.
remove_stock_nginx_default
svc enable nginx
svc enable supervisor
svc enable cron

step "Composer"
if command -v composer > /dev/null 2>&1; then
    echo "composer already installed: $(composer --version 2> /dev/null | head -n 1)"
else
    tmp_dir="$(mktemp -d)"
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o "$tmp_dir/composer-setup.php"
    actual="$(sha384sum "$tmp_dir/composer-setup.php" | awk '{print $1}')"
    [ "$expected" = "$actual" ] || die "Composer installer checksum mismatch"
    php "$tmp_dir/composer-setup.php" --quiet --install-dir=/usr/local/bin --filename=composer
    rm -rf "$tmp_dir"
    composer --version
fi

step "Node.js $NODE_MAJOR (NodeSource)"
current_node_major="$(node -p 'process.versions.node.split(".")[0]' 2> /dev/null || echo 0)"
if [ "$current_node_major" -ge "$NODE_MAJOR" ]; then
    echo "node $(node -v) already installed"
else
    install -d -m 755 /etc/apt/keyrings
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
        | gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main" \
        > /etc/apt/sources.list.d/nodesource.list
    apt-get update
    apt_install nodejs
    echo "node $(node -v), npm $(npm -v)"
fi

step "firewall (ufw)"
if [ "$SKIP_FIREWALL" = "1" ]; then
    echo "SKIP_FIREWALL=1, skipping"
else
    apt_install ufw
    # Whatever port sshd really listens on, so enabling the firewall cannot lock us out.
    for port in $(ssh_ports); do
        ufw allow "$port/tcp"
    done
    ufw allow 80/tcp
    ufw allow 443/tcp
    if ufw status | grep -q '^Status: active'; then
        echo "ufw was already active; rules added, nothing removed"
    else
        allowed=" $(ssh_ports | tr '\n' ' ') 80 443 "
        others="$(public_listeners | awk -v allowed="$allowed" 'index(allowed, " " $1 " ") == 0 { printf "%s(%s) ", $1, $2 }')"
        if [ -n "$others" ] && [ "$FORCE" != "1" ]; then
            warn "ufw NOT enabled: other services listen publicly ($others) and enabling it would cut them off."
            warn "allow them first, then enable it yourself:  ufw allow <port>/tcp && ufw enable   (or re-run with FORCE=1)"
        else
            ufw --force enable
        fi
    fi
    ufw status
fi

step "check that PostgreSQL and Redis listen on localhost only"
assert_local_only 5432 PostgreSQL
assert_local_only 6379 Redis

step "application code in $APP_DIR"
if [ -f "$APP_DIR/artisan" ]; then
    echo "code already present at $APP_DIR ($(git -C "$APP_DIR" rev-parse --short HEAD 2> /dev/null || echo 'not a git checkout')), skipping clone"
else
    install -d -m 755 "$(dirname "$APP_DIR")"
    git clone --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
fi
DEPLOY_DIR="$APP_DIR/deploy"
for template in nginx.conf supervisor/hq-queue.conf supervisor/hq-reverb.conf \
    cron/goodtechies-hq sql/roles.sql deploy.sh; do
    [ -f "$DEPLOY_DIR/$template" ] || die "missing $DEPLOY_DIR/$template"
done
# The env template is the one file a sync tool may refuse (its name looks like a .env). Without
# it the install falls back to .env.example and forces the production keys itself, below.
ENV_TEMPLATE="$DEPLOY_DIR/.env.production.example"
if [ ! -f "$ENV_TEMPLATE" ]; then
    [ -f "$APP_DIR/.env.example" ] || die "missing $ENV_TEMPLATE (and $APP_DIR/.env.example)"
    warn "missing $ENV_TEMPLATE; a new .env will start from .env.example with production keys forced"
    ENV_TEMPLATE="$APP_DIR/.env.example"
fi

step "secrets (explicit value, else the existing .env, else generated)"
[ -n "$DB_MIGRATOR_PASSWORD" ] || DB_MIGRATOR_PASSWORD="$(env_get DB_MIGRATOR_PASSWORD)"
[ -n "$DB_MIGRATOR_PASSWORD" ] || DB_MIGRATOR_PASSWORD="$(gen_secret)"
[ -n "$DB_APP_PASSWORD" ] || DB_APP_PASSWORD="$(env_get DB_PASSWORD)"
[ -n "$DB_APP_PASSWORD" ] || DB_APP_PASSWORD="$(gen_secret)"
[ -n "$DB_RO_PASSWORD" ] || DB_RO_PASSWORD="$(env_get DB_RO_PASSWORD)"
[ -n "$DB_RO_PASSWORD" ] || DB_RO_PASSWORD="$(gen_secret)"
[ -n "$SEED_PASSWORD" ] || SEED_PASSWORD="$(env_get SEED_PASSWORD)"
if [ -z "$SEED_PASSWORD" ]; then
    SEED_PASSWORD="$(gen_secret)"
    GENERATED_SEED_PASSWORD="$SEED_PASSWORD"
fi
# Reverb's application credentials (Phase 6). Generated when blank and kept when not, like
# every other secret here — re-running install.sh must not invalidate the key that is already
# compiled into the built assets. They are generated even on a polling install, so that
# turning realtime on later is one .env line and a release rather than a hunt for a generator.
[ -n "$REVERB_APP_ID" ] || REVERB_APP_ID="$(env_get REVERB_APP_ID)"
[ -n "$REVERB_APP_ID" ] || REVERB_APP_ID="$(gen_secret)"
[ -n "$REVERB_APP_KEY" ] || REVERB_APP_KEY="$(env_get REVERB_APP_KEY)"
[ -n "$REVERB_APP_KEY" ] || REVERB_APP_KEY="$(gen_secret)"
[ -n "$REVERB_APP_SECRET" ] || REVERB_APP_SECRET="$(env_get REVERB_APP_SECRET)"
[ -n "$REVERB_APP_SECRET" ] || REVERB_APP_SECRET="$(gen_secret)"
echo "database, seed and Reverb secrets resolved (not printed)"

step "database $DB_NAME"
if [ "$(psql_value postgres "SELECT 1 FROM pg_database WHERE datname = '$DB_NAME'")" = "1" ]; then
    echo "database $DB_NAME exists"
else
    as_postgres createdb "$DB_NAME"
    echo "created database $DB_NAME"
fi

step "roles and grants (deploy/sql/roles.sql)"
as_postgres psql -X -q -d postgres \
    -v db="$DB_NAME" \
    -v migrator_password="$DB_MIGRATOR_PASSWORD" \
    -v app_password="$DB_APP_PASSWORD" \
    -v ro_password="$DB_RO_PASSWORD" \
    -f - < "$DEPLOY_DIR/sql/roles.sql"
echo "roles hq_migrator, hq_app, hq_ro are set up"

step "write .env"
if [ -f "$APP_DIR/.env" ]; then
    echo ".env exists, updating the managed keys only"
else
    cp "$ENV_TEMPLATE" "$APP_DIR/.env"
    chmod 600 "$APP_DIR/.env"
    # Already so in the production template; forced for the .env.example fallback.
    env_set APP_ENV "production"
    env_set APP_DEBUG "false"
    env_set LOG_LEVEL "warning"
    echo "created .env from ${ENV_TEMPLATE#"$APP_DIR"/}"
fi
chmod 600 "$APP_DIR/.env"
# The transport, on every run, so a re-run with a domain moves an IP install to https (and the
# other way). SESSION_SECURE_COOKIE must follow the scheme: a secure cookie is never sent over
# http, so on an http install nobody could hold a session at all.
env_set APP_URL "$SCHEME://$DOMAIN"
if [ "$SCHEME" = "https" ]; then
    env_set SESSION_SECURE_COOKIE "true"
else
    env_set SESSION_SECURE_COOKIE "false"
fi
env_set DB_DATABASE "$DB_NAME"
env_set DB_PASSWORD "$DB_APP_PASSWORD"
env_set DB_MIGRATOR_PASSWORD "$DB_MIGRATOR_PASSWORD"
env_set DB_RO_PASSWORD "$DB_RO_PASSWORD"
env_set SEED_PASSWORD "$SEED_PASSWORD"
# The sender of password-reset mail; only a blank or placeholder address is replaced.
case "$(env_get MAIL_FROM_ADDRESS)" in
    "" | hello@example.com) env_set MAIL_FROM_ADDRESS "no-reply@${DOMAIN}" ;;
esac

# Realtime. The credentials always; the MODE only when this run was asked for one, so that a
# re-run of install.sh never quietly turns somebody's socket off (or on).
env_set REVERB_APP_ID "$REVERB_APP_ID"
env_set REVERB_APP_KEY "$REVERB_APP_KEY"
env_set REVERB_APP_SECRET "$REVERB_APP_SECRET"
env_set REVERB_SERVER_HOST "127.0.0.1"
env_set REVERB_SERVER_PORT "$REVERB_PORT"
# Where the browser dials the socket: this site, through Nginx's /app location, on the public
# scheme and port. Kept in step with APP_URL on every run (it changes nothing while polling).
env_set REVERB_HOST "$DOMAIN"
env_set REVERB_PORT "$PUBLIC_PORT"
env_set REVERB_SCHEME "$SCHEME"
if [ "$REALTIME" = "reverb" ]; then
    # Both halves together. BROADCAST_CONNECTION is how the server sends and VITE_REALTIME is
    # whether the browser listens; setting one without the other is the failure this script
    # exists to make impossible. VITE_* is compiled into the assets, so deploy.sh's npm build
    # below is what actually applies it.
    env_set BROADCAST_CONNECTION "reverb"
    env_set VITE_REALTIME "reverb"
elif [ -z "$(env_get BROADCAST_CONNECTION)" ]; then
    env_set BROADCAST_CONNECTION "log"
    env_set VITE_REALTIME "polling"
fi
for key in "${SEED_EMAIL_KEYS[@]}"; do
    if [ -n "${!key:-}" ]; then
        env_set "$key" "${!key}"
    fi
done
# Demo data stays out of production unless asked for (database/seeders/DatabaseSeeder.php).
if [ -n "$SEED_DEMO" ]; then
    env_set SEED_DEMO "$SEED_DEMO"
elif [ -z "$(env_get SEED_DEMO)" ]; then
    env_set SEED_DEMO "0"
fi
chown "$APP_USER:$APP_USER" "$APP_DIR/.env"
chmod 600 "$APP_DIR/.env"
blank_keys=""
for key in AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_DEFAULT_REGION AWS_BUCKET \
    BACKUP_S3_KEY BACKUP_S3_SECRET BACKUP_S3_REGION BACKUP_S3_BUCKET BACKUP_ARCHIVE_PASSWORD; do
    [ -n "$(env_get "$key")" ] || blank_keys="$blank_keys $key"
done
if [ -n "$blank_keys" ]; then
    warn "blank in .env, so file storage and backups are not configured yet:$blank_keys (see docs/runbooks/install.md)"
fi

step "composer install and APP_KEY"
composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$APP_DIR"
if [ -z "$(env_get APP_KEY)" ]; then
    php "$APP_DIR/artisan" key:generate --force
else
    echo "APP_KEY already set, keeping it"
fi

step "ownership and permissions"
chown -R "$APP_USER:$APP_USER" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
chmod -R u+rwX,g+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
ls -l "$APP_DIR/.env"

step "Nginx site"
render "$DEPLOY_DIR/nginx.conf" "/etc/nginx/sites-available/$SITE_NAME"
if [ ! -e /proc/net/if_inet6 ]; then
    sed -i '/listen \[::\]/d' "/etc/nginx/sites-available/$SITE_NAME"
    echo "no IPv6 on this host, dropped the [::]:80 listener"
fi
ln -sfn "/etc/nginx/sites-available/$SITE_NAME" "/etc/nginx/sites-enabled/$SITE_NAME"
remove_stock_nginx_default
nginx -t
svc reload nginx

step "Supervisor programs and cron"
render "$DEPLOY_DIR/supervisor/hq-queue.conf" /etc/supervisor/conf.d/hq-queue.conf
render "$DEPLOY_DIR/supervisor/hq-reverb.conf" /etc/supervisor/conf.d/hq-reverb.conf
render "$DEPLOY_DIR/cron/goodtechies-hq" "/etc/cron.d/$SITE_NAME"
echo "installed /etc/supervisor/conf.d/hq-{queue,reverb}.conf and /etc/cron.d/$SITE_NAME"

step "first release (deploy/deploy.sh)"
APP_DIR="$APP_DIR" SKIP_PULL=1 bash "$DEPLOY_DIR/deploy.sh"

step "start Supervisor programs"
supervisorctl reread
supervisorctl update
queue_status="$(supervisorctl status hq-queue || true)"
if ! grep -qE 'RUNNING|STARTING' <<<"$queue_status"; then
    supervisorctl start hq-queue
fi

# Reverb follows .env and nothing else. `autostart=false` in the Supervisor template is the
# polling mode, not an unfinished step — see deploy/supervisor/hq-reverb.conf.
if [ "$(env_get BROADCAST_CONNECTION)" = "reverb" ]; then
    reverb_status="$(supervisorctl status hq-reverb || true)"
    if ! grep -qE 'RUNNING|STARTING' <<<"$reverb_status"; then
        supervisorctl start hq-reverb
    fi
    # The same assertion PostgreSQL and Redis get: nothing this box runs may listen beyond the
    # loopback except Nginx. Supervisor needs a moment to get the process up first.
    for _ in $(seq 1 15); do
        ss -Hltn "sport = :$REVERB_PORT" | grep -q . && break
        sleep 1
    done
    assert_local_only "$REVERB_PORT" Reverb
else
    echo "BROADCAST_CONNECTION is not 'reverb', so the bell polls and hq-reverb stays stopped"
    supervisorctl stop hq-reverb > /dev/null 2>&1 || true
fi

supervisorctl status || true

step "seed the team (once)"
user_count="$(psql_value "$DB_NAME" "SELECT count(*) FROM users")"
if [ "$user_count" -gt 0 ]; then
    echo "users table already has $user_count rows, skipping the seeder"
else
    # The seeders read SEED_* with env(), which a cached config hides.
    php "$APP_DIR/artisan" config:clear
    php "$APP_DIR/artisan" db:seed --force --database=pgsql_migrator
    php "$APP_DIR/artisan" config:cache
    chown -R "$APP_USER:$APP_USER" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
    echo "seeded $(psql_value "$DB_NAME" "SELECT count(*) FROM users") users, $(psql_value "$DB_NAME" "SELECT count(*) FROM projects") projects, $(psql_value "$DB_NAME" "SELECT count(*) FROM tasks") tasks (SEED_DEMO=$(env_get SEED_DEMO))"
fi

step "TLS certificate (Certbot)"
if [ "$SCHEME" = "http" ]; then
    echo "http install ($DOMAIN): no certificate and no HSTS. When a domain points at this server,"
    echo "switch to https with one re-run (it updates .env, rebuilds the assets and runs Certbot):"
    echo "  cd $APP_DIR && DOMAIN=hq.example.com CERTBOT_EMAIL=you@example.com bash deploy/install.sh"
    echo "which runs, for that domain:"
    echo "  certbot --nginx --non-interactive --agree-tos --keep-until-expiring --redirect -m you@example.com -d hq.example.com"
elif [ "$SKIP_CERTBOT" != "1" ] && { [ -n "$CERTBOT_EMAIL" ] || [ -d "/etc/letsencrypt/live/$DOMAIN" ]; }; then
    # Also on a re-run without CERTBOT_EMAIL when a certificate exists: rendering the site above
    # replaced the server block Certbot had converted to 443, and this puts TLS back.
    certbot_args=(--nginx --non-interactive --agree-tos --keep-until-expiring --redirect -d "$DOMAIN")
    [ -z "$CERTBOT_EMAIL" ] || certbot_args+=(-m "$CERTBOT_EMAIL")
    certbot "${certbot_args[@]}"
else
    echo "CERTBOT_EMAIL not set or SKIP_CERTBOT=1, skipping; run later:"
    echo "  certbot --nginx --redirect -m you@example.com -d $DOMAIN"
    echo "Until then nobody can sign in: the session cookie is https-only on a domain install."
fi

step "summary"
cat <<SUMMARY
GoodTechies HQ is installed.

  URL:        $SCHEME://$DOMAIN$([ "$SCHEME" = "https" ] && echo "  (http until the certificate exists)")
  Code:       $APP_DIR ($(git -C "$APP_DIR" rev-parse --short HEAD 2> /dev/null || echo 'unknown'))
  Secrets:    $APP_DIR/.env (www-data, mode 600): APP_KEY, DB_* passwords, SEED_PASSWORD
  Workers:    supervisorctl status   (hq-reverb runs only when BROADCAST_CONNECTION=reverb)
  Realtime:   $(env_get BROADCAST_CONNECTION) / VITE_REALTIME=$(env_get VITE_REALTIME)  — docs/runbooks/realtime.md
  Releases:   automatic on every push to main (deploy/setup-actions-key.sh), or: cd $APP_DIR && bash deploy/deploy.sh
  Memory:     $([ "$LOW_MEMORY" = "1" ] && echo "low-memory profile (PHP-FPM ondemand x5, PostgreSQL 128MB, Redis 96mb)" || echo "standard profile")

Next steps (docs/runbooks/install.md):
  1. DNS: point $DOMAIN at this server, then run Certbot if it was skipped
     (an IP install switches to a domain by re-running install.sh with DOMAIN=<name>).
  2. S3: create the files bucket (versioning on) and fill the AWS_* keys.
  3. Backups: create the backup bucket in a different provider account or region,
     fill the BACKUP_* keys, then run: SKIP_PULL=1 bash deploy/deploy.sh
  4. Sign in as each Admin and enrol 2FA at first login; change the seeded password.
SUMMARY
if [ -n "$GENERATED_SEED_PASSWORD" ]; then
    echo
    echo "Generated seed password (shown once; also in .env as SEED_PASSWORD):"
    echo "  $GENERATED_SEED_PASSWORD"
fi
