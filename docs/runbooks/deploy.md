# Runbook: release, rollback, maintenance

Every release runs `deploy/deploy.sh` as root on the VPS. Normally GitHub Actions starts it on
every push to `main` (`.github/workflows/deploy.yml`, set up with `deploy/setup-actions-key.sh`;
the step-by-step is in `vps-quickstart.md`). By hand:

```bash
cd /var/www/goodtechies-hq
bash deploy/deploy.sh           # fetches main, fast-forwards, then releases
```

`bash` because the repository is committed from Windows, so the script has no execute bit.

### One click from Windows: `deploy-live.bat` (decision 12-92)

The 1 GB VPS has no swap (`swapon` is refused in its container), and `npm run build` peaks near
0.95 GB, so a plain `deploy.sh` can die with `ENOMEM` (it did on 2026-10-03). The everyday
release is therefore:

1. `push-to-github.bat`
2. `deploy-live.bat` (double-click). It warns about anything not on GitHub, logs in over SSH
   (root password, or a password-free key it offers to set up once in
   `%USERPROFILE%\.ssh\goodtechies_hq_deploy`), sends `deploy/live-deploy.sh` and runs it.

`deploy/live-deploy.sh` does nothing when the server already runs `origin/main` (`FORCE=1`
rebuilds anyway). Otherwise it:

- needs 1.5 GB of free disk;
- dumps the database to `/root/hq-backups` (newest 10 kept);
- copies `public/build`;
- pauses `hq-queue` and `hq-reverb` for the build (always restarted);
- fast-forwards the code;
- runs `SKIP_PULL=1 bash deploy/deploy.sh`, with `SKIP_NPM_CI=1` when `package*.json` did not
  change, and retries it once;
- on a second failure, resets the code to the previous commit, restores the old build,
  re-caches and brings the site up (exit 2);
- finally checks `/login` and pending migrations.

Before the release it also removes junk (decision 12-93): `/swapfile`, which this container
can never switch on (2.1 GB); apt's downloaded packages; the journal beyond 50 MB; app logs
beyond 50 MB; rotated logs older than 14 days. After the build it clears the npm and composer
caches. It never touches the database, `storage/app`, `.env` or any backup.

The database dump is verified (`pg_restore --list`) before anything changes, and
`/root/hq-backups/latest.dump` points at it. The bat copies it to
`%USERPROFILE%\goodtechies-hq-backups` on the PC (newest 10). A release whose new migrations
drop, rename, truncate or delete data in `up()` stops until it is run as
`deploy-live.bat allow-db-changes`. `deploy-live.bat clean` removes junk only.

Logs are in `/root/hq-deploy-logs`. Without a PC at hand, the same script runs from the VPSDime
web console: `bash /var/www/goodtechies-hq/deploy/live-deploy.sh`.

## Automatic deploys

- **Trigger:** a push to `main`, or Actions → Deploy → Run workflow. Runs queue, never overlap,
  and are never cancelled halfway.
- **How:** the runner opens one SSH connection as root with a key that `/root/.ssh/authorized_keys`
  limits to `restrict,command="/bin/bash /var/www/goodtechies-hq/deploy/deploy.sh"` — no shell,
  no forwarding. It asks for `deploy <commit>`; deploy.sh accepts only that and checks that the
  release contains the pushed commit. The host key is pinned (`VPS_KNOWN_HOSTS`).
- **Secrets:** `VPS_HOST`, `VPS_SSH_KEY`, `VPS_KNOWN_HOSTS` (and `VPS_PORT` if ssh is not on 22),
  all printed by `deploy/setup-actions-key.sh`. Re-running that script rotates the key.
- **Failure:** deploy.sh exits non-zero (the run turns red) after bringing the site back up.

## What deploy.sh does

0. **Checks, before anything goes down.** One release at a time (a lock file). The checkout must
   be on `main` (`BRANCH`), with **no local changes**, and `origin/main` must be a fast-forward
   of it; otherwise the release refuses to start and says why. `.env` must not be tracked.
   `SKIP_PULL=1` skips these and deploys whatever is checked out (rollback).
1. `php artisan down --retry=15`. It is skipped on the very first run, before `vendor/` exists. If any later step fails, a trap runs `php artisan up`.
2. `git merge --ff-only origin/main` (the fetched equivalent of `git pull --ff-only`).
3. `composer install --no-dev --optimize-autoloader --no-interaction`
4. `npm ci && npm run build`. Under 2 GB of RAM, Node's heap is capped
   (`NODE_OPTIONS=--max-old-space-size=768`, `NODE_BUILD_HEAP_MB` to change it) and the swap file
   absorbs the peak.
5. `php artisan migrate --force --database=pgsql_migrator`. Migrations always run as the schema owner.
6. `config:cache`, `route:cache`, `view:cache`
7. `chown www-data` on `storage/` and `bootstrap/cache/`
8. `queue:restart`: Supervisor starts a fresh `hq-queue` worker on the new code.
9. `supervisorctl restart hq-reverb`, only when `artisan reverb:start` exists (Phase 6 onwards).
10. Reload `php8.3-fpm` to reset OPcache.
11. `php artisan up`, then print the deployed commit.

A `.env` change needs a release (`SKIP_PULL=1 bash deploy/deploy.sh`), because the config is cached.

A release never touches `.env` or the contents of `storage/` (both are git-ignored; `storage/`
is only re-owned by `www-data`). It does **not** run `hq:repair-task-timer-overruns`: that is
the local machine's one-off repair (`start-hq.bat`). It would be harmless on the VPS — it is
idempotent and finds nothing on a fresh database — but it has nothing to repair there.

## Before a release

- Check that the latest backup finished (`php artisan backup:list`; the weekly restore test is `php artisan hq:verify-backup`).
- Read the release's migrations. **Migrations are forward-only.**

## Rollback

Code-only rollback, when the release added no migration:

```bash
cd /var/www/goodtechies-hq
git log --oneline -5            # find the previous release
git checkout <prev-commit>
SKIP_PULL=1 bash deploy/deploy.sh
```

While HEAD is detached, every automatic deploy fails on purpose ("HEAD is detached … a
rollback?"). Return to the branch to resume: `git checkout main && bash deploy/deploy.sh`.

**Schema rollback:** do not run `migrate:rollback` in production. Instead:

1. `php artisan down`
2. Restore the database from the last backup taken before the release.
3. Check out the previous commit and run `SKIP_PULL=1 bash deploy/deploy.sh`.

## Maintenance mode

```bash
php artisan down --retry=60                   # visitors get 503 with Retry-After
php artisan down --secret="<random-token>"    # you can still browse via /<random-token>
php artisan up
```

## Workers

```bash
supervisorctl status
supervisorctl restart hq-queue
tail -f storage/logs/hq-queue.log
```

`hq-reverb` runs only when `.env` says `BROADCAST_CONNECTION=reverb`; deploy.sh starts or stops it to match (see realtime.md). After install.sh re-renders the conf, run `supervisorctl reread && supervisorctl update`.

## If a release fails

The script exits non-zero and brings the app back up. Read the failing `==>` step, fix it, and run `bash deploy/deploy.sh` again (or re-run the workflow). Every step is safe to repeat.
