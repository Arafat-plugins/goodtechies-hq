# Install transcript, 2026-09-17

| | |
| --- | --- |
| Date | 2026-09-17 10:32 UTC |
| Image | `ubuntu:24.04` (Ubuntu 24.04.5 LTS) via `deploy/test/Dockerfile.ubuntu24`, no systemd |
| Commit | `7245aeb` (branch `phase0-g1`) plus the uncommitted `deploy/` kit copied over the clone |
| Command | `EXTRA_CA_CERT=<proxy CA bundle> deploy/test/run-install-test.sh` |
| Flow | `install.sh` (`REPO_URL=/src SKIP_FIREWALL=1 DOMAIN=hq.test`), then `SKIP_PULL=1 deploy.sh` a second time, then the checks |
| Result | **PASS**: 7/7 checks (login 200, / 302, 5 users, audit_logs `ar`, hq-queue RUNNING, production env, shellcheck) |

Notes:
- The container clones the committed HEAD from `/src`. The working-copy `deploy/` was then copied over the clone, so install.sh found the code already present and skipped its own clone step.
- Egress: the host proxy listens on the host loopback, which a bridged container cannot reach, so it was not passed in. The container used direct egress with the proxy CA installed in its trust store (`NODE_EXTRA_CA_CERTS` set). Every source was reachable: archive.ubuntu.com, NodeSource, getcomposer.org, packagist, registry.npmjs.org.
- The container kernel has no IPv6, so install.sh dropped the `[::]:80` listener.
- Not exercised in a container: systemd (the `service` fallback ran), ufw (`SKIP_FIREWALL=1`), Certbot (no `CERTBOT_EMAIL`).
- Trimmed from 1932 to about 260 lines. apt, composer and npm progress output was removed. The generated seed password is redacted.

```text
==> test setup
date:      2026-09-17 10:32 UTC
source:    /home/claude/hq-g1 (branch phase0-g1, commit 7245aeb)
image:     goodtechies-hq-install-test:ubuntu24 (FROM ubuntu:24.04)
worktree:  mounting git metadata /home/claude/goodtechies-hq/.git read-only
proxy:     HTTPS_PROXY=http://127.0.0.1:39977 is the host loopback, not passed (container uses direct egress)
proxy:     https_proxy=http://127.0.0.1:39977 is the host loopback, not passed (container uses direct egress)
trust:     adding /root/.ccr/ca-bundle.crt to the container trust store (NODE_EXTRA_CA_CERTS set)
==> build image
sha256:c2ab64b104e1a171ac02afd649e84a11a28d5e1e5a03479839cb3b511f96ca08
==> start container
PRETTY_NAME="Ubuntu 24.04.5 LTS"
no systemd (service fallback)
==> clone the committed HEAD and overlay the working-copy deploy/
Cloning into '/var/www/goodtechies-hq'...
done.
copied /src/deploy over /var/www/goodtechies-hq/deploy (uncommitted deploy kit under test)
clone HEAD: 7245aeb Phase 0d2: Admin/Employee/Accountant shells, dashboards, read-only settings
==> run deploy/install.sh
==> check parameters
Ubuntu 24.04, APP_DIR=/var/www/goodtechies-hq, DOMAIN=hq.test, DB_NAME=goodtechies_hq, BRANCH=phase0-g1
==> apt base packages
ca-certificates is already the newest version (20260601~24.04.1).
curl is already the newest version (8.5.0-2ubuntu10.13).
git is already the newest version (1:2.43.0-1ubuntu7.3).
openssl is already the newest version (3.0.13-0ubuntu3.15).
openssl set to manually installed.
Initializing machine ID from random generator.
Run 'dpkg-reconfigure tzdata' if you wish to change it.
Setcap worked! gst-ptp-helper is not suid!
start-stop-daemon: unable to stat /usr/libexec/polkitd (No such file or directory)
Failed to open connection to "system" message bus: Failed to connect to socket /run/dbus/system_bus_socket: No such file or directory
==> PHP 8.3
Moving old data out of the way
PHP 8.3.6
==> PostgreSQL 16
Generating locales (this might take a while)...
Generation complete.
Building PostgreSQL dictionaries from installed myspell/hunspell packages...
/usr/lib/postgresql/16/bin/initdb -D /var/lib/postgresql/16/main --auth-local peer --auth-host scram-sha-256 --no-instructions
fixing permissions on existing directory /var/lib/postgresql/16/main ... ok
 * Starting PostgreSQL 16 database server
   ...done.
==> Redis
Starting redis-server: /etc/init.d/redis-server: 51: ulimit: error setting limit (Operation not permitted)
redis-server.
==> Nginx, Supervisor, Certbot
 * Starting nginx nginx
   ...done.
Starting supervisor: supervisord.
 * Starting periodic command scheduler cron
   ...done.
==> Composer
PHP version 8.3.6 (/usr/bin/php8.3)
Run the "diagnose" command to get more detailed diagnostics output.
Composer version 2.10.3 2026-08-27 13:34:23
==> Node.js 22 (NodeSource)
node v22.23.2, npm 10.9.8
==> firewall (ufw)
SKIP_FIREWALL=1, skipping
==> check that PostgreSQL and Redis listen on localhost only
PostgreSQL listens on: 127.0.0.1:5432 
Redis listens on: 127.0.0.1:6379 
==> application code in /var/www/goodtechies-hq
code already present at /var/www/goodtechies-hq (7245aeb), skipping clone
==> secrets (explicit value, else the existing .env, else generated)
database and seed passwords resolved (not printed)
==> database goodtechies_hq
created database goodtechies_hq
==> roles and grants (deploy/sql/roles.sql)
roles hq_migrator, hq_app, hq_ro are set up
==> write .env
created .env from deploy/.env.production.example
WARNING: blank in .env, so file storage and backups are not configured yet: AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_DEFAULT_REGION AWS_BUCKET BACKUP_S3_KEY BACKUP_S3_SECRET BACKUP_S3_REGION BACKUP_S3_BUCKET BACKUP_ARCHIVE_PASSWORD (see docs/runbooks/install.md)
==> composer install and APP_KEY
Installing dependencies from lock file
Verifying lock file contents can be installed on current platform.
Package operations: 92 installs, 0 updates, 0 removals
Generating optimized autoload files
> Illuminate\Foundation\ComposerScripts::postAutoloadDump
> @php artisan package:discover --ansi
   INFO  Discovering packages.  
61 packages you are using are looking for funding.
Use the `composer fund` command to find out more!
   INFO  Application key set successfully.  
==> ownership and permissions
-rw------- 1 www-data www-data 3332 Sep 17 10:34 /var/www/goodtechies-hq/.env
==> Nginx site
no IPv6 on this host, dropped the [::]:80 listener
nginx: the configuration file /etc/nginx/nginx.conf syntax is ok
nginx: configuration file /etc/nginx/nginx.conf test is successful
 * Reloading nginx configuration nginx
   ...done.
==> Supervisor programs and cron
installed /etc/supervisor/conf.d/hq-{queue,reverb}.conf and /etc/cron.d/goodtechies-hq
==> first release (deploy/deploy.sh)
==> maintenance mode on
   INFO  Application is now in maintenance mode.  
==> update code
SKIP_PULL=1, deploying the checked-out commit
==> composer install
Installing dependencies from lock file
Verifying lock file contents can be installed on current platform.
Nothing to install, update or remove
Generating optimized autoload files
> Illuminate\Foundation\ComposerScripts::postAutoloadDump
> @php artisan package:discover --ansi
   INFO  Discovering packages.  
61 packages you are using are looking for funding.
Use the `composer fund` command to find out more!
==> build frontend assets
added 156 packages in 6s
> build
> vite build
vite v8.3.0 building client environment for production...
transforming...
✓ 3187 modules transformed.
rendering chunks...
computing gzip size...
✓ built in 1.56s
==> migrate (pgsql_migrator)
   INFO  Preparing database.  
  Creating migration table ...................................... 75.76ms DONE
   INFO  Running migrations.  
  0001_01_01_000000_create_users_table .......................... 16.08ms DONE
  0001_01_01_000001_create_cache_table ........................... 9.63ms DONE
  0001_01_01_000002_create_jobs_table ........................... 14.64ms DONE
  2026_09_17_000001_create_roles_table ........................... 4.58ms DONE
  2026_09_17_000002_create_permissions_table ..................... 3.67ms DONE
  2026_09_17_000003_create_role_permissions_table ................ 8.37ms DONE
  2026_09_17_000004_create_user_project_permissions_table ........ 6.96ms DONE
  2026_09_17_000005_create_employees_table ....................... 8.13ms DONE
  2026_09_17_000006_create_schedules_table ....................... 5.58ms DONE
  2026_09_17_000007_create_audit_logs_table ...................... 6.30ms DONE
  2026_09_17_000008_create_activity_logs_table ................... 6.76ms DONE
  2026_09_17_000009_create_settings_table ........................ 4.68ms DONE
  2026_09_17_000010_create_login_history_table ................... 5.01ms DONE
==> cache config, routes and views
   INFO  Configuration cached successfully.  
   INFO  Routes cached successfully.  
   INFO  Blade templates cached successfully.  
==> fix ownership of storage and bootstrap/cache
==> restart queue workers
   INFO  Broadcasting queue restart signal.  
==> restart Reverb if installed
reverb:start not available (Reverb arrives in Phase 6), skipping
==> reload php8.3-fpm
 * Reloading PHP 8.3 FastCGI Process Manager php-fpm8.3
   ...done.
==> maintenance mode off
   INFO  Application is now live.  
==> deployed 7245aeb Phase 0d2: Admin/Employee/Accountant shells, dashboards, read-only settings (2026-09-17 16:14:09 +0600)
==> start Supervisor programs
hq-queue: available
hq-reverb: available
hq-queue: added process group
hq-reverb: added process group
hq-queue                         STARTING  
hq-reverb                        STOPPED   Not started
==> seed the team (once)
   INFO  Configuration cache cleared successfully.  
   INFO  Seeding database.  
  Database\Seeders\RolePermissionSeeder .............................. RUNNING  
  Database\Seeders\RolePermissionSeeder .......................... 437 ms DONE  
  Database\Seeders\SettingsSeeder .................................... RUNNING  
  Database\Seeders\SettingsSeeder ................................. 19 ms DONE  
  Database\Seeders\TeamSeeder ........................................ RUNNING  
  Database\Seeders\TeamSeeder .................................. 1,154 ms DONE  
   INFO  Configuration cached successfully.  
seeded 5 users
==> TLS certificate (Certbot)
CERTBOT_EMAIL not set or SKIP_CERTBOT=1, skipping; run later:
==> summary
GoodTechies HQ is installed.
  URL:        https://hq.test  (http until the certificate exists)
  Code:       /var/www/goodtechies-hq (7245aeb)
  Secrets:    /var/www/goodtechies-hq/.env (www-data, mode 600): APP_KEY, DB_* passwords, SEED_PASSWORD
  Workers:    supervisorctl status   (hq-reverb stays stopped until Phase 6)
  Releases:   cd /var/www/goodtechies-hq && deploy/deploy.sh
Next steps (docs/runbooks/install.md):
  1. DNS: point hq.test at this server, then run Certbot if it was skipped.
  2. S3: create the files bucket (versioning on) and fill the AWS_* keys.
  3. Backups: create the backup bucket in a different provider account or region,
     fill the BACKUP_* keys, then run deploy/deploy.sh.
  4. Sign in as each Admin and enrol 2FA at first login; change the seeded password.
Generated seed password (shown once; also in .env as SEED_PASSWORD):
  <redacted>
==> run deploy/deploy.sh again (idempotency)
==> maintenance mode on
   INFO  Application is now in maintenance mode.  
==> update code
SKIP_PULL=1, deploying the checked-out commit
==> composer install
Installing dependencies from lock file
Verifying lock file contents can be installed on current platform.
Nothing to install, update or remove
Generating optimized autoload files
> Illuminate\Foundation\ComposerScripts::postAutoloadDump
> @php artisan package:discover --ansi
   INFO  Discovering packages.  
61 packages you are using are looking for funding.
Use the `composer fund` command to find out more!
==> build frontend assets
added 156 packages in 5s
> build
> vite build
vite v8.3.0 building client environment for production...
transforming...
✓ 3187 modules transformed.
rendering chunks...
computing gzip size...
✓ built in 1.32s
==> migrate (pgsql_migrator)
   INFO  Nothing to migrate.  
==> cache config, routes and views
   INFO  Configuration cached successfully.  
   INFO  Routes cached successfully.  
   INFO  Blade templates cached successfully.  
==> fix ownership of storage and bootstrap/cache
==> restart queue workers
   INFO  Broadcasting queue restart signal.  
==> restart Reverb if installed
reverb:start not available (Reverb arrives in Phase 6), skipping
==> reload php8.3-fpm
 * Reloading PHP 8.3 FastCGI Process Manager php-fpm8.3
   ...done.
==> maintenance mode off
   INFO  Application is now live.  
==> deployed 7245aeb Phase 0d2: Admin/Employee/Accountant shells, dashboards, read-only settings (2026-09-17 16:14:09 +0600)
==> checks
PASS  GET /login returns 200 (got: 200)
PASS  GET / returns 302 (got: 302)
PASS  users seeded (got: 5)
                                      Access privileges
 Schema |    Name    | Type  |        Access privileges        | Column privileges | Policies 
--------+------------+-------+---------------------------------+-------------------+----------
 public | audit_logs | table | hq_migrator=arwdDxt/hq_migrator+|                   | 
        |            |       | hq_app=ar/hq_migrator          +|                   | 
        |            |       | hq_ro=r/hq_migrator             |                   | 
(1 row)
PASS  audit_logs grants hq_app ar only (got: hq_app=ar/hq_migrator)
hq-queue                         RUNNING   pid 16311, uptime 0:00:06
PASS  hq-queue RUNNING (got: RUNNING)
  Environment ................................................................  
  Application Name ............................................ GoodTechies HQ  
  Laravel Version .................................................... 13.32.0  
  PHP Version .......................................................... 8.3.6  
  Composer Version .................................................... 2.10.3  
  Environment ..................................................... production  
  Debug Mode ............................................................. OFF  
  URL ................................................................ hq.test  
  Maintenance Mode ....................................................... OFF  
  Timezone ........................................................ Asia/Dhaka  
  Locale .................................................................. en  
PASS  artisan about shows production (got: production)
debconf: delaying package configuration, since apt-utils is not installed
clean
PASS  shellcheck (got: clean)
==> result
RESULT: PASS (all checks passed)
```
