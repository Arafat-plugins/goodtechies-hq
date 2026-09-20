-- GoodTechies HQ: PostgreSQL roles and grants (master prompt Part B §3 rule 3).
--
-- Run once per database as a superuser. The script is idempotent, so running it
-- again on a database that is already set up is safe:
--
--   psql -h 127.0.0.1 -U postgres \
--        -v db=goodtechies_hq \
--        -v migrator_password='…' -v app_password='…' -v ro_password='…' \
--        -f deploy/sql/roles.sql
--
-- Roles:
--   hq_migrator  owns the database, the public schema and every table. Migrations run as this role
--                (`php artisan migrate --database=pgsql_migrator`).
--   hq_app       the runtime role (`pgsql` connection). It gets SELECT, INSERT, UPDATE and DELETE on tables
--                and USAGE, SELECT on sequences. It owns nothing and cannot CREATE in the schema.
--   hq_ro        a read-only role (`pgsql_ro` connection). It gets SELECT on tables, and every transaction
--                is read-only by default.
--
-- Each run sets the role passwords from the -v variables, so a re-run also rotates them.
--
-- audit_logs is append-only: its migration revokes UPDATE, DELETE and TRUNCATE from hq_app.
-- This script re-applies that revoke after its bulk grants, so a re-run never gives those privileges back.

\set ON_ERROR_STOP on

\if :{?db}
\else
    DO $$ BEGIN RAISE EXCEPTION 'roles.sql: missing -v db=<database name>'; END $$;
\endif
\if :{?migrator_password}
\else
    DO $$ BEGIN RAISE EXCEPTION 'roles.sql: missing -v migrator_password=<password>'; END $$;
\endif
\if :{?app_password}
\else
    DO $$ BEGIN RAISE EXCEPTION 'roles.sql: missing -v app_password=<password>'; END $$;
\endif
\if :{?ro_password}
\else
    DO $$ BEGIN RAISE EXCEPTION 'roles.sql: missing -v ro_password=<password>'; END $$;
\endif

-- Create the login roles that do not exist yet.
SELECT format('CREATE ROLE %I LOGIN', r.rolname)
FROM (VALUES ('hq_migrator'), ('hq_app'), ('hq_ro')) AS r (rolname)
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE pg_roles.rolname = r.rolname)
\gexec

-- Set LOGIN and the passwords on every run.
SELECT format('ALTER ROLE %I LOGIN PASSWORD %L', 'hq_migrator', :'migrator_password')
\gexec
SELECT format('ALTER ROLE %I LOGIN PASSWORD %L', 'hq_app', :'app_password')
\gexec
SELECT format('ALTER ROLE %I LOGIN PASSWORD %L', 'hq_ro', :'ro_password')
\gexec

ALTER ROLE hq_ro SET default_transaction_read_only = on;
ALTER ROLE hq_migrator CREATEDB;

ALTER DATABASE :"db" OWNER TO hq_migrator;
GRANT CONNECT ON DATABASE :"db" TO hq_app;
GRANT CONNECT ON DATABASE :"db" TO hq_ro;

\connect :"db"

-- The schema belongs to the migrator. hq_app and hq_ro get USAGE only, never CREATE.
ALTER SCHEMA public OWNER TO hq_migrator;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
REVOKE CREATE ON SCHEMA public FROM hq_app;
REVOKE CREATE ON SCHEMA public FROM hq_ro;
GRANT USAGE ON SCHEMA public TO hq_app;
GRANT USAGE ON SCHEMA public TO hq_ro;

-- Privileges on objects that hq_migrator creates later.
ALTER DEFAULT PRIVILEGES FOR ROLE hq_migrator IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO hq_app;
ALTER DEFAULT PRIVILEGES FOR ROLE hq_migrator IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO hq_app;
ALTER DEFAULT PRIVILEGES FOR ROLE hq_migrator IN SCHEMA public GRANT SELECT ON TABLES TO hq_ro;

-- The same privileges on objects that already exist.
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO hq_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO hq_app;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO hq_ro;

-- Keep audit_logs append-only for hq_app after the bulk grant above.
SELECT 'REVOKE UPDATE, DELETE, TRUNCATE ON TABLE public.audit_logs FROM hq_app'
WHERE to_regclass('public.audit_logs') IS NOT NULL
\gexec
