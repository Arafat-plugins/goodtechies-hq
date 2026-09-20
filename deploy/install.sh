#!/usr/bin/env bash
# GoodTechies HQ: first install on a fresh Ubuntu 22.04 or 24.04 VPS. Run as root:
#
#   DOMAIN=hq.example.com REPO_URL=git@github.com:org/goodtechies-hq.git \
#   CERTBOT_EMAIL=ops@example.com bash install.sh
#
# Re-running is safe: packages, repos, roles and templates are re-applied; an
# existing checkout, .env (passwords, APP_KEY) and seeded users are kept.
# Parameters and the reasoning behind each step: docs/runbooks/install.md.
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
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"
SKIP_CERTBOT="${SKIP_CERTBOT:-0}"
SKIP_FIREWALL="${SKIP_FIREWALL:-0}"
NODE_MAJOR="${NODE_MAJOR:-22}"

APP_USER="www-data"
PHP_VERSION="8.3"
PG_VERSION="16"
SITE_NAME="goodtechies-hq"
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
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || die "DOMAIN must be a bare host name"
[[ "$DB_NAME" =~ ^[a-z_][a-z0-9_]*$ ]] || die "DB_NAME must match ^[a-z_][a-z0-9_]*$"
if [ ! -f "$APP_DIR/artisan" ] && [ -z "$REPO_URL" ]; then
    die "REPO_URL is required because $APP_DIR does not hold the code yet"
fi
echo "Ubuntu $VERSION_ID, APP_DIR=$APP_DIR, DOMAIN=$DOMAIN, DB_NAME=$DB_NAME, BRANCH=$BRANCH"

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
svc enable "php$PHP_VERSION-fpm"

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

step "Redis"
apt_install redis-server
svc enable redis-server

step "Nginx, Supervisor, Certbot"
apt_install nginx supervisor certbot python3-certbot-nginx
# The stock default site is replaced by ours below (it also fails on hosts without IPv6).
rm -f /etc/nginx/sites-enabled/default
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
    ufw allow 22/tcp
    ufw allow 80/tcp
    ufw allow 443/tcp
    ufw --force enable
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
for template in .env.production.example nginx.conf supervisor/hq-queue.conf supervisor/hq-reverb.conf \
    cron/goodtechies-hq sql/roles.sql deploy.sh; do
    [ -f "$DEPLOY_DIR/$template" ] || die "missing $DEPLOY_DIR/$template"
done

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
echo "database and seed passwords resolved (not printed)"

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
    cp "$DEPLOY_DIR/.env.production.example" "$APP_DIR/.env"
    echo "created .env from deploy/.env.production.example"
fi
chmod 600 "$APP_DIR/.env"
env_set APP_URL "https://$DOMAIN"
env_set DB_DATABASE "$DB_NAME"
env_set DB_PASSWORD "$DB_APP_PASSWORD"
env_set DB_MIGRATOR_PASSWORD "$DB_MIGRATOR_PASSWORD"
env_set DB_RO_PASSWORD "$DB_RO_PASSWORD"
env_set SEED_PASSWORD "$SEED_PASSWORD"
for key in "${SEED_EMAIL_KEYS[@]}"; do
    if [ -n "${!key:-}" ]; then
        env_set "$key" "${!key}"
    fi
done
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
rm -f /etc/nginx/sites-enabled/default
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
    echo "seeded $(psql_value "$DB_NAME" "SELECT count(*) FROM users") users"
fi

step "TLS certificate (Certbot)"
if [ -n "$CERTBOT_EMAIL" ] && [ "$SKIP_CERTBOT" != "1" ]; then
    certbot --nginx --non-interactive --agree-tos --redirect -m "$CERTBOT_EMAIL" -d "$DOMAIN"
else
    echo "CERTBOT_EMAIL not set or SKIP_CERTBOT=1, skipping; run later:"
    echo "  certbot --nginx --redirect -m you@example.com -d $DOMAIN"
fi

step "summary"
cat <<SUMMARY
GoodTechies HQ is installed.

  URL:        https://$DOMAIN  (http until the certificate exists)
  Code:       $APP_DIR ($(git -C "$APP_DIR" rev-parse --short HEAD 2> /dev/null || echo 'unknown'))
  Secrets:    $APP_DIR/.env (www-data, mode 600): APP_KEY, DB_* passwords, SEED_PASSWORD
  Workers:    supervisorctl status   (hq-reverb stays stopped until Phase 6)
  Releases:   cd $APP_DIR && deploy/deploy.sh

Next steps (docs/runbooks/install.md):
  1. DNS: point $DOMAIN at this server, then run Certbot if it was skipped.
  2. S3: create the files bucket (versioning on) and fill the AWS_* keys.
  3. Backups: create the backup bucket in a different provider account or region,
     fill the BACKUP_* keys, then run deploy/deploy.sh.
  4. Sign in as each Admin and enrol 2FA at first login; change the seeded password.
SUMMARY
if [ -n "$GENERATED_SEED_PASSWORD" ]; then
    echo
    echo "Generated seed password (shown once; also in .env as SEED_PASSWORD):"
    echo "  $GENERATED_SEED_PASSWORD"
fi
