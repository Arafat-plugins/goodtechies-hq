# Local setup

## Requirements
- PHP 8.3+ with `pdo_pgsql` and `redis`, Composer
- Node 20+ and npm
- PostgreSQL 16 (superuser access for the one-time role setup)
- Redis on `127.0.0.1:6379`

## 1. Databases
```bash
createdb -h 127.0.0.1 -U postgres goodtechies_hq
createdb -h 127.0.0.1 -U postgres goodtechies_hq_test
```

## 2. Roles and grants
Run `deploy/sql/roles.sql` once for **each** database, as a superuser. It creates `hq_migrator`
(schema owner), `hq_app` (runtime) and `hq_ro` (read-only), and it is safe to re-run.
```bash
for db in goodtechies_hq goodtechies_hq_test; do
  psql -h 127.0.0.1 -U postgres -v db=$db \
       -v migrator_password='…' -v app_password='…' -v ro_password='…' \
       -f deploy/sql/roles.sql
done
```
Use the same passwords you put in `.env`.

## 3. Environment
`cp .env.example .env`, then `php artisan key:generate`, then fill in:
- `DB_USERNAME`, `DB_PASSWORD`: `hq_app`
- `DB_MIGRATOR_USERNAME`, `DB_MIGRATOR_PASSWORD`: `hq_migrator`
- `DB_RO_USERNAME`, `DB_RO_PASSWORD`: `hq_ro`
- `SEED_PASSWORD`: required, used for every seeded user
- `SEED_TWO_FACTOR_SECRET`: optional base32 secret; in `local` it pre-confirms 2FA for the Admins and the Accountant
- `SEED_SHAHADAT_EMAIL`, `SEED_FARUK_EMAIL`, `SEED_TAPU_EMAIL`, `SEED_YASEEN_EMAIL`, `SEED_ACCOUNTANT_EMAIL`

The test settings are in `phpunit.xml` (database `goodtechies_hq_test`).

## 4. Install, migrate, test
```bash
composer install && npm install
php artisan migrate:fresh --seed --database=pgsql_migrator
php artisan test
```
Always run migrations on `pgsql_migrator`, never on the default `pgsql` (hq_app) connection.
The tests build their schema as `hq_migrator` and run as `hq_app`.

## Seeded logins
| User | Email | Role |
| --- | --- | --- |
| Shahadat Hossain | shahadat@goodtechies.test | ADMIN |
| Faruk Ahmed | faruk@goodtechies.test | ADMIN |
| Tapu | tapu@goodtechies.test | REMOTE_EMPLOYEE |
| Yaseen | yaseen@goodtechies.test | EMPLOYEE |
| Accountant | accountant@goodtechies.test | ACCOUNTANT |

The password is your `SEED_PASSWORD`.
