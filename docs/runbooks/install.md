# Runbook: first install on the VPS

`deploy/install.sh` turns a fresh Ubuntu 22.04 or 24.04 server into a running GoodTechies HQ.
Run it once, as root. Re-running it is safe: it keeps the checkout, `.env` (passwords, `APP_KEY`)
and the seeded users.

**The client's VPS (IP only, 1 GB RAM, automatic deploys): follow `vps-quickstart.md`.** This
page is the reference behind it.

```bash
apt-get update && apt-get install -y git
git clone --branch main <repo-url> /var/www/goodtechies-hq
cd /var/www/goodtechies-hq
DOMAIN=hq.example.com CERTBOT_EMAIL=ops@example.com bash deploy/install.sh
```

You can also copy `install.sh` to the server on its own and pass `REPO_URL`. The script
clones the repository and then uses the templates in the checkout's `deploy/`.

## Parameters (environment variables)

| Variable | Default | Meaning |
| --- | --- | --- |
| `DOMAIN` | required | Host name served by Nginx; `APP_URL` becomes `https://DOMAIN`. An **IPv4 address** installs plain http on that IP: `APP_URL=http://IP`, `SESSION_SECURE_COOKIE=false`, the socket on `ws://IP:80`, no Certbot, no HSTS |
| `HTTP_ONLY` | `0` | `1` = the http mode above for a host name too |
| `FORCE` | `0` | `1` = continue past the preflight's stop conditions, and enable ufw although other services listen publicly |
| `LOW_MEMORY` | `auto` | `auto` = on below 2 GB of RAM; `1`/`0` force it. Swap file, PHP-FPM `ondemand` × 5, PostgreSQL `shared_buffers=128MB`/`max_connections=40`, Redis `maxmemory 96mb` |
| `SKIP_SWAP`, `SWAP_SIZE_MB` | `0`, `2048` | Skip the swap file, or change its size |
| `SEED_DEMO` | `.env` value (template: `0`) | `1` also seeds the demo clients, projects, tasks, meetings, finance, payroll and attendance |
| `REPO_URL` | required unless `APP_DIR` already holds the code | Git URL to clone |
| `BRANCH` | `main` | Branch to clone |
| `APP_DIR` | `/var/www/goodtechies-hq` | Application directory |
| `DB_NAME` | `goodtechies_hq` | PostgreSQL database |
| `DB_MIGRATOR_PASSWORD`, `DB_APP_PASSWORD`, `DB_RO_PASSWORD` | existing `.env` value, else generated | Passwords of `hq_migrator`, `hq_app`, `hq_ro` |
| `SEED_PASSWORD` | existing `.env` value, else generated and printed once | Initial password of the seeded team |
| `SEED_*_EMAIL` | template value | Real addresses of the five seeded users |
| `CERTBOT_EMAIL` | empty | When set, Certbot requests a certificate |
| `SKIP_CERTBOT` | `0` | `1` skips Certbot even when `CERTBOT_EMAIL` is set |
| `SKIP_FIREWALL` | `0` | `1` skips ufw (containers, or a provider firewall) |
| `NODE_MAJOR` | `22` | Node.js major version from NodeSource (the lockfile needs Node 22 or later) |

The script honours the standard proxy variables (`HTTPS_PROXY`, `HTTP_PROXY`, `NO_PROXY`). It never prompts for input.

## What each step does and why

1. **check parameters**: requires root, Ubuntu 22.04/24.04, a bare `DOMAIN` and a safe `DB_NAME`, so no later step fails halfway. Picks http (IP or `HTTP_ONLY=1`) or https, and the memory profile.
1a. **preflight** (changes nothing): lists other Nginx sites (and which is `default_server`), whatever listens on 80/443, hosting panels, MySQL, PostgreSQL clusters, the Node version and free disk. **Stops** on a panel, a non-Nginx server on 80/443, another site using the same name, or another PostgreSQL version on 5432 — unless `FORCE=1`.
1b. **memory**: under 2 GB, a 2 GB `/swapfile` when there is no swap (in `/etc/fstab`) and `vm.swappiness=10` (in `/etc/sysctl.d/99-goodtechies-hq.conf`).
2. **apt base packages**: curl, gnupg, git, unzip, openssl, iproute2 (`ss`), cron (the scheduler).
3. **PHP 8.3**: the lockfile targets PHP 8.3. On 24.04 it comes from the distro; on 22.04 from the `ondrej/php` PPA. The extensions are fpm, cli, pgsql, redis, mbstring, xml, curl, zip, intl, gd and bcmath. PHP's upload limit is raised to 50 MB to match Nginx. The app gets **its own pool** (`/etc/php/8.3/fpm/pool.d/goodtechies-hq.conf`, socket `php8.3-fpm-goodtechies-hq.sock`), sized for the box; other sites' pools are not touched, and FPM is reloaded, not restarted.
4. **PostgreSQL 16**: the PGDG repository is added only when the distro lacks 16 (22.04). Memory settings go in a drop-in, `conf.d/90-goodtechies-hq.conf`; the server restarts only when it changed.
5. **Redis**: cache, queue and the queue-restart signal. `maxmemory` with `volatile-lru` (evicts cache entries, never queued jobs) in `/etc/redis/goodtechies-hq.conf`, included from `redis.conf`.
6. **Nginx, Supervisor, Certbot**: web server, process manager for the queue worker and Reverb, and TLS. `sites-enabled/default` is removed only when it is the unmodified package file.
7. **Composer**: the official installer, verified against its published SHA-384 before running.
8. **Node.js** (NodeSource): builds the Vite assets on the server.
9. **firewall**: ufw allows ssh (every port sshd is on), 80 and 443, and removes nothing. It is switched on only when nothing else listens publicly (else a warning says what to allow first; `FORCE=1` enables it anyway).
10. **localhost check**: fails the install if PostgreSQL or Redis listens beyond 127.0.0.1/::1. Neither service may be reachable from outside.
11. **application code**: clones `REPO_URL` into `APP_DIR`, or skips when the code is already there.
12. **secrets**: each password comes from the parameter, else the existing `.env`, else `openssl rand`. A re-run therefore never breaks the database login.
13. **database** and **roles** (`deploy/sql/roles.sql`): create the database, the three roles, and least-privilege grants. `hq_app` cannot alter the schema or rewrite `audit_logs`.
14. **write .env**: copies `deploy/.env.production.example` (or, if a sync dropped that file, `.env.example` with `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning` forced) and fills `APP_URL`, `SESSION_SECURE_COOKIE`, `REVERB_HOST/PORT/SCHEME` (all from the http/https mode, on every run), the `DB_*` passwords, `SEED_PASSWORD` and `SEED_DEMO=0`. The file is owned by `www-data` with mode 600. The script warns about the S3 and backup keys that are still blank.
15. **composer install and APP_KEY**: `key:generate` runs only when `APP_KEY` is empty. Rotating the key would break encrypted data and sessions.
16. **ownership**: `storage/` and `bootstrap/cache/` belong to `www-data`. The rest of the code stays root-owned and read-only to PHP.
17. **Nginx site**: renders `deploy/nginx.conf` for `DOMAIN` and `APP_DIR`, then runs `nginx -t` and reloads. On hosts without IPv6, the `[::]:80` listener is dropped. No `default_server`: an exact `server_name` wins over another site's `default_server`, so an IP install answers on its IP without taking over other sites' names.
18. **Supervisor and cron**: `hq-queue` (autostart), `hq-reverb` (started only when `BROADCAST_CONNECTION=reverb`), and `/etc/cron.d/goodtechies-hq` for `schedule:run` every minute.
19. **first release**: runs `deploy/deploy.sh` with `SKIP_PULL=1` (composer, npm build, migrate, caches). See `deploy.md`.
20. **start Supervisor programs**: `supervisorctl reread/update` starts the worker once `vendor/` exists.
21. **seed the team (once)**: `db:seed` on `pgsql_migrator`, only while `users` is empty. The config cache is cleared around it because the seeders read `SEED_*` with `env()`. With `SEED_DEMO=0` (production) it writes roles, permissions, settings, the five team members, the six leave types and their opening balances, the holiday list and the finance category lists — no demo clients, projects, tasks, meetings, income/expenses, salaries, payroll or attendance (`DatabaseSeeder::seedsDemo()`, `tests/Feature/Database/ProductionSeedTest.php`).
22. **Certbot**: `certbot --nginx --redirect --keep-until-expiring` when `CERTBOT_EMAIL` is set, or when a certificate for `DOMAIN` already exists (a re-run re-renders the site and this puts TLS back). Certbot adds the 443 server block and renews the certificate on a timer. Skipped in http mode, which prints the exact switch command instead.
23. **summary**: prints the URL, where the secrets are, and the next steps. A generated seed password is printed only here.

### Without systemd

Service calls go through `svc()`. That helper uses `systemctl` when `/run/systemd/system` exists and `service` otherwise, which is how the container test runs.

## After the install

1. **DNS**: point an A record (and AAAA for IPv6) for `DOMAIN` at the server. If Certbot was skipped, run it now:
   ```bash
   certbot --nginx --redirect -m ops@example.com -d hq.example.com
   ```
   **From an IP install**, re-run install.sh with the domain instead; it also switches `.env` to https and rebuilds the assets:
   ```bash
   cd /var/www/goodtechies-hq && DOMAIN=hq.example.com CERTBOT_EMAIL=ops@example.com bash deploy/install.sh
   ```
2. **File storage (S3)**: create the files bucket with **versioning on**. Then fill `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` and, for non-AWS providers, `AWS_ENDPOINT` / `AWS_USE_PATH_STYLE_ENDPOINT` in `/var/www/goodtechies-hq/.env`.
3. **Backups**: create a second bucket in a **different provider account or region** (spec §37), with versioning or replication. Fill `BACKUP_S3_*` and a long `BACKUP_ARCHIVE_PASSWORD`, and store that password outside the server as well. Backups then run daily at 02:00 and `hq:verify-backup` restores the newest one every Sunday at 04:00 (see restore-from-backup.md).
4. **Apply .env changes** with a release, which rebuilds the config cache:
   ```bash
   cd /var/www/goodtechies-hq && SKIP_PULL=1 bash deploy/deploy.sh
   ```
5. **First sign-in**: each Admin signs in with the seed password, **enrols 2FA** (production has no seeded 2FA secret) and changes the password. Then remove `SEED_PASSWORD` from `.env`.
6. **Check**:
   ```bash
   supervisorctl status          # hq-queue RUNNING, hq-reverb STOPPED
   php artisan about --only=environment
   ```

## Email (password reset)

The "Forgot password?" link on the sign-in page sends its reset link through Laravel's own mailer, so `MAIL_MAILER` in `.env` decides how it leaves the server. Three options:

- **smtp** with the domain's own mailbox (e.g. Hostinger: `smtp.hostinger.com`, port 465, `MAIL_SCHEME=smtps`). Lands best.
- **sendmail**: the server's local MTA. Needs postfix and a PTR record, or Gmail rejects it.
- **log**: writes the mail to `storage/logs` (nothing is sent).

For SMTP, set these keys in `/var/www/goodtechies-hq/.env`:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_USERNAME=no-reply@hq.example.com
MAIL_PASSWORD=the-mailbox-password
MAIL_FROM_ADDRESS="no-reply@hq.example.com"
```

Apply it with a release, then send a test mail:

```bash
cd /var/www/goodtechies-hq && SKIP_PULL=1 bash deploy/deploy.sh
php artisan tinker --execute="Illuminate\Support\Facades\Mail::raw('goodERP test', fn (\$m) => \$m->to('you@example.com')->subject('goodERP mail test'));"
```

The reset email itself is queued, so `hq-queue` must be RUNNING for it to go out.

## Proof

`docs/runbooks/install-log-2026-09-17.md` holds the transcript of `deploy/test/run-install-test.sh`: a fresh Ubuntu 24.04 container runs install.sh, then deploy.sh a second time, then the checks.
