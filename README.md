# GoodTechies HQ

An internal agency operating system for GoodTechies — projects, tasks, time, leave,
team communication, finance and payroll in one place, built phase by phase and
self-hosted on the agency's own VPS.

## Stack

| Layer | Choice |
| --- | --- |
| Backend | Laravel 13 (PHP 8.3+) |
| Frontend | Inertia + Vue 3 + TypeScript |
| Styling | Tailwind CSS 4, shadcn-vue components |
| Database | PostgreSQL 16+ (three roles: migrator / app / read-only) |
| Cache & queue | Redis 7 |
| Realtime | Laravel Reverb |
| Tests | Pest |

## Status

**Phase 0 of 12 built — waiting for GATE A.**

Phase 0 covers authentication with 2FA, roles and permissions, the three role
shells, audit and activity logging, CI, the VPS deploy kit, and backups.
`PROGRESS.md` is the live source of truth for what is built and what is next.

## Documentation

| File | What it is |
| --- | --- |
| `PROGRESS.md` | What is built, current phase, open gates, decisions. Read first. |
| `CLAUDE.md` | How work is done in this repo. |
| `AGENTS.md` | Repo map for sub-agents. |
| `DESIGN.md` | Design system and visual rules. |
| `PROJECT_BRIEF.md` | The client brief. |
| `docs/master-prompt-v1.md` | The full plan: stack, privacy rules, domain spec, all 13 phases. |
| `docs/runbooks/local-setup.md` | Local development setup. |
| `docs/runbooks/deploy.md` | Deployment. |
| `docs/runbooks/restore-from-backup.md` | Backup restore procedure. |

## Local setup

Requirements: PHP 8.3+ (`pdo_pgsql`, `pgsql`, `intl`, `zip`, `gd`, `bcmath`,
`mbstring`, `fileinfo`, `curl`, `openssl`), Composer 2, Node 22, PostgreSQL 16+.

On Windows, `setup-local.bat` runs the whole sequence below with prerequisite
checks. Otherwise, follow `docs/runbooks/local-setup.md`:

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate

createdb goodtechies_hq
createdb goodtechies_hq_test
# run deploy/sql/roles.sql once per database as a superuser

php artisan migrate:fresh --seed --database=pgsql_migrator
composer run dev
```

The app serves at http://localhost:8000.

Migrations always run on the `pgsql_migrator` connection — the schema is owned by
`hq_migrator`, while the app itself runs as the lower-privileged `hq_app`.

## Tests

```bash
php artisan test                        # all
php artisan test --group=phase0
php artisan test --group=permissions
vendor/bin/pint --test                  # code style
npx vue-tsc --noEmit                    # type check
```

## License

Proprietary. Built for GoodTechies.
