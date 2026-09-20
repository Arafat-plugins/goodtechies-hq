# Runbook: first install on the VPS

`deploy/install.sh` turns a fresh Ubuntu 22.04 or 24.04 server into a running GoodTechies HQ.
Run it once, as root. Re-running it is safe: it keeps the checkout, `.env` (passwords, `APP_KEY`)
and the seeded users.

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
| `DOMAIN` | required | Host name served by Nginx; `APP_URL` becomes `https://DOMAIN` |
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

1. **check parameters**: requires root, Ubuntu 22.04/24.04, a bare `DOMAIN` and a safe `DB_NAME`, so no later step fails halfway.
2. **apt base packages**: curl, gnupg, git, unzip, openssl, iproute2 (`ss`), cron (the scheduler).
3. **PHP 8.3**: the lockfile targets PHP 8.3. On 24.04 it comes from the distro; on 22.04 from the `ondrej/php` PPA. The extensions are fpm, cli, pgsql, redis, mbstring, xml, curl, zip, intl, gd and bcmath. PHP's upload limit is raised to 50 MB to match Nginx.
4. **PostgreSQL 16**: the PGDG repository is added only when the distro lacks 16 (22.04).
5. **Redis**: cache, queue and the queue-restart signal.
6. **Nginx, Supervisor, Certbot**: web server, process manager for the queue worker (and later Reverb), and TLS. The stock default site is removed.
7. **Composer**: the official installer, verified against its published SHA-384 before running.
8. **Node.js** (NodeSource): builds the Vite assets on the server.
9. **firewall**: ufw allows only 22, 80 and 443.
10. **localhost check**: fails the install if PostgreSQL or Redis listens beyond 127.0.0.1/::1. Neither service may be reachable from outside.
11. **application code**: clones `REPO_URL` into `APP_DIR`, or skips when the code is already there.
12. **secrets**: each password comes from the parameter, else the existing `.env`, else `openssl rand`. A re-run therefore never breaks the database login.
13. **database** and **roles** (`deploy/sql/roles.sql`): create the database, the three roles, and least-privilege grants. `hq_app` cannot alter the schema or rewrite `audit_logs`.
14. **write .env**: copies `deploy/.env.production.example` and fills `APP_URL`, the `DB_*` passwords and `SEED_PASSWORD`. The file is owned by `www-data` with mode 600. The script warns about the S3 and backup keys that are still blank.
15. **composer install and APP_KEY**: `key:generate` runs only when `APP_KEY` is empty. Rotating the key would break encrypted data and sessions.
16. **ownership**: `storage/` and `bootstrap/cache/` belong to `www-data`. The rest of the code stays root-owned and read-only to PHP.
17. **Nginx site**: renders `deploy/nginx.conf` for `DOMAIN` and `APP_DIR`, then runs `nginx -t` and reloads. On hosts without IPv6, the `[::]:80` listener is dropped.
18. **Supervisor and cron**: `hq-queue` (autostart), `hq-reverb` (autostart=false until Phase 6), and `/etc/cron.d/goodtechies-hq` for `schedule:run` every minute.
19. **first release**: runs `deploy/deploy.sh` with `SKIP_PULL=1` (composer, npm build, migrate, caches). See `deploy.md`.
20. **start Supervisor programs**: `supervisorctl reread/update` starts the worker once `vendor/` exists.
21. **seed the team (once)**: `db:seed` on `pgsql_migrator`, only while `users` is empty. The config cache is cleared around it because the seeders read `SEED_*` with `env()`.
22. **Certbot**: `certbot --nginx --redirect` when `CERTBOT_EMAIL` is set. Certbot adds the 443 server block and renews the certificate on a timer.
23. **summary**: prints the URL, where the secrets are, and the next steps. A generated seed password is printed only here.

### Without systemd

Service calls go through `svc()`. That helper uses `systemctl` when `/run/systemd/system` exists and `service` otherwise, which is how the container test runs.

## After the install

1. **DNS**: point an A record (and AAAA for IPv6) for `DOMAIN` at the server. If Certbot was skipped, run it now:
   ```bash
   certbot --nginx --redirect -m ops@example.com -d hq.example.com
   ```
2. **File storage (S3)**: create the files bucket with **versioning on**. Then fill `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` and, for non-AWS providers, `AWS_ENDPOINT` / `AWS_USE_PATH_STYLE_ENDPOINT` in `/var/www/goodtechies-hq/.env`.
3. **Backups**: create a second bucket in a **different provider account or region** (spec §37), with versioning or replication. Fill `BACKUP_S3_*` and a long `BACKUP_ARCHIVE_PASSWORD`, and store that password outside the server as well. Backups then run daily at 02:00 and `hq:verify-backup` restores the newest one every Sunday at 04:00 (see restore-from-backup.md).
4. **Apply .env changes** with a release, which rebuilds the config cache:
   ```bash
   cd /var/www/goodtechies-hq && SKIP_PULL=1 deploy/deploy.sh
   ```
5. **First sign-in**: each Admin signs in with the seed password, **enrols 2FA** (production has no seeded 2FA secret) and changes the password. Then remove `SEED_PASSWORD` from `.env`.
6. **Check**:
   ```bash
   supervisorctl status          # hq-queue RUNNING, hq-reverb STOPPED
   php artisan about --only=environment
   ```

## Proof

`docs/runbooks/install-log-2026-09-17.md` holds the transcript of `deploy/test/run-install-test.sh`: a fresh Ubuntu 24.04 container runs install.sh, then deploy.sh a second time, then the checks.
