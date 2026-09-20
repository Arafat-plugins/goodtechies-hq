# Runbook: release, rollback, maintenance

Every release runs `deploy/deploy.sh` as root on the VPS.

```bash
cd /var/www/goodtechies-hq
deploy/deploy.sh                # pulls the checked-out branch, then releases
```

## What deploy.sh does

1. `php artisan down --retry=15`. It is skipped on the very first run, before `vendor/` exists. If any later step fails, a trap runs `php artisan up`.
2. `git pull --ff-only`. `SKIP_PULL=1` deploys the commit that is already checked out.
3. `composer install --no-dev --optimize-autoloader --no-interaction`
4. `npm ci && npm run build`. The `.npmrc` has `ignore-scripts=true`.
5. `php artisan migrate --force --database=pgsql_migrator`. Migrations always run as the schema owner.
6. `config:cache`, `route:cache`, `view:cache`
7. `chown www-data` on `storage/` and `bootstrap/cache/`
8. `queue:restart`: Supervisor starts a fresh `hq-queue` worker on the new code.
9. `supervisorctl restart hq-reverb`, only when `artisan reverb:start` exists (Phase 6 onwards).
10. Reload `php8.3-fpm` to reset OPcache.
11. `php artisan up`, then print the deployed commit.

A `.env` change needs a release (`SKIP_PULL=1 deploy/deploy.sh`), because the config is cached.

## Before a release

- Check that the latest backup finished (`php artisan backup:list`; the weekly restore test is `php artisan hq:verify-backup`).
- Read the release's migrations. **Migrations are forward-only.**

## Rollback

Code-only rollback, when the release added no migration:

```bash
cd /var/www/goodtechies-hq
git log --oneline -5            # find the previous release
git checkout <prev-commit>
SKIP_PULL=1 deploy/deploy.sh
```

Return to the branch before the next normal release: `git checkout main && deploy/deploy.sh`.

**Schema rollback:** do not run `migrate:rollback` in production. Instead:

1. `php artisan down`
2. Restore the database from the last backup taken before the release.
3. Check out the previous commit and run `SKIP_PULL=1 deploy/deploy.sh`.

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

`hq-reverb` stays `STOPPED` until Phase 6 installs Reverb and sets `autostart=true` in `deploy/supervisor/hq-reverb.conf`. After install.sh re-renders the conf, run `supervisorctl reread && supervisorctl update`.

## If a release fails

The script exits non-zero and brings the app back up. Read the failing `==>` step, fix it, and run `deploy/deploy.sh` again. Every step is safe to repeat.
