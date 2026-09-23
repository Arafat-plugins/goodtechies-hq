@echo off
REM ============================================================
REM  goodERP - start the app (Windows)
REM  Double-click this. It updates dependencies, builds the
REM  frontend, then serves the app on http://localhost:8000
REM  Everything it prints is also written to start-hq.log, so a
REM  failure can be read after the fact.
REM  First time ever on a machine: run setup-local.bat instead.
REM ============================================================
setlocal
cd /d "%~dp0"
title goodERP - running
set "LOG=start-hq.log"
echo goodERP start-hq.bat  %DATE% %TIME% > "%LOG%"

echo.
echo ============================================================
echo   goodERP
echo ============================================================
echo.

if not exist ".env" (
    echo   No .env found. Run setup-local.bat first.
    echo   No .env found. >> "%LOG%"
    goto :hold
)

REM ---------- Two-factor, off for local development ----------
REM Admins and Accountants normally need a TOTP code. This line switches
REM enforcement off for the server this window starts, so signing in as
REM shahadat or accountant needs only a password.
REM
REM It is set here, not in .env, so your .env keeps its secrets untouched
REM and nothing about this follows the code anywhere. A real environment
REM variable wins over .env, so this beats whatever the file says.
REM
REM It CANNOT weaken production: APP_ENV=production enforces 2FA whatever
REM this is set to, and there is a test that fails if anyone removes that.
REM Nobody is un-enrolled either - every secret and recovery code stays in
REM the database, so deleting this line brings the prompt straight back.
set "AUTH_TWO_FACTOR_ENFORCED=false"

REM ---------- Product name ----------
REM The app reads its name from APP_NAME. Setting it here means it takes effect
REM without editing .env - which this tooling cannot write to anyway, because
REM that file holds your database and seed passwords.
REM
REM To make it permanent (and for deployment), change line 1 of .env to:
REM     APP_NAME="goodERP"
REM ...and then this line does nothing, which is fine.
set "APP_NAME=goodERP"

REM ---------- 1. JS dependencies ----------
echo [1/4] Updating JS dependencies...
echo === npm install === >> "%LOG%"
call npm install >> "%LOG%" 2>&1
if errorlevel 1 (
    echo    [X] npm install failed - see %LOG%
    goto :hold
)
echo    [ok]
echo.

REM ---------- 2. Database reachable? ----------
echo [2/4] Checking the database...
echo === migrate:status === >> "%LOG%"
call php artisan migrate:status >> "%LOG%" 2>&1
if errorlevel 1 (
    echo.
    echo    [X] Could not reach the database, or it was never migrated.
    echo        - is the PostgreSQL service running?
    echo          ^(Win+R, services.msc, start postgresql-x64-16^)
    echo        - never set up on this machine? run setup-local.bat once
    echo        Details are in %LOG%
    goto :hold
)
echo    [ok]

REM ---------- 2b. Apply any new migrations ----------
REM A phase that adds tables (Phase 2 added tasks) leaves this database a
REM version behind the code the moment new files land. Without this the app
REM starts and then 500s the instant you open the new screen, which looks
REM like a broken build rather than a missing table. `migrate` only applies
REM what has not run; it never drops anything and never touches your data.
echo    Applying any new migrations...
echo === migrate === >> "%LOG%"
call php artisan migrate --database=pgsql_migrator --force >> "%LOG%" 2>&1
if errorlevel 1 (
    echo    [X] migrations failed - see %LOG%
    goto :hold
)
echo    [ok]

REM ---------- 2c. Top up the demo data ----------
REM Migrations create a phase's TABLES; they never put rows in them. Phase 2
REM added tasks, so without this you get a perfect Tasks screen saying
REM "0 tasks" - which is exactly what happened the first time.
REM
REM Safe to run every single start: every seeder looks a record up before
REM creating it (firstOrCreate / updateOrCreate, no raw creates), so this
REM adds what is missing and changes nothing else. Verified by running it
REM twice over - the counts do not move. It also refreshes the demo dates,
REM so "overdue" stays overdue instead of emptying out after a week.
REM
REM It does NOT wipe anything. That would be migrate:fresh, which this
REM script never runs.
echo    Topping up demo data...
echo === db:seed === >> "%LOG%"
call php artisan db:seed --database=pgsql_migrator --force >> "%LOG%" 2>&1
if errorlevel 1 (
    echo    [X] seeding failed - see %LOG%
    goto :hold
)
echo    [ok]
echo.

REM ---------- 3. Build the frontend ----------
echo [3/4] Building the frontend ^(about 30 seconds^)...
echo === npm run build === >> "%LOG%"
call npm run build >> "%LOG%" 2>&1
if errorlevel 1 (
    echo    [X] build failed - see %LOG%
    goto :hold
)
echo    [ok]

REM ---------- 3b. Remove a stale public\hot ----------
REM `npm run dev` / `composer run dev` writes public\hot while the Vite
REM dev server is up, and deletes it on a clean shutdown. Close that
REM window without Ctrl+C and the file survives - and @vite then points
REM every script tag at a dev server on :5173 that is no longer running,
REM ignoring the build we just made. The page arrives with its title and
REM favicon and a completely empty body. That is exactly what a "white
REM login page" is. This script always serves the built assets, so the
REM file is never wanted here.
if exist "public\hot" (
    del /q "public\hot"
    echo    [ok] removed a stale public\hot ^(left by a dev server^)
    echo removed stale public/hot >> "%LOG%"
)
echo.

REM ---------- 4. Serve ----------
for /f "tokens=1,* delims==" %%a in ('findstr /b /c:"SEED_PASSWORD=" .env')          do set "SEED_PW=%%b"
for /f "tokens=1,* delims==" %%a in ('findstr /b /c:"SEED_TWO_FACTOR_SECRET=" .env') do set "SEED_2FA=%%b"

echo [4/4] Starting the server.
echo.
echo ============================================================
echo   Open  http://localhost:8000
echo.
echo   Fastest way in - no 2FA code needed:
echo     yaseen@goodtechies.test      Employee
echo     tapu@goodtechies.test        Remote employee
echo   Password: %SEED_PW%
echo.
echo   Admin and Accountant - password only, 2FA is off for local dev:
echo     shahadat@goodtechies.test    Admin
echo     accountant@goodtechies.test  Accountant
echo.
echo   If you ever DO want the 2FA prompt back, delete the
echo   AUTH_TWO_FACTOR_ENFORCED line near the top of this file. Then, in
echo   another window, this prints the 6-digit code to type - no
echo   authenticator app needed:
echo     php artisan hq:two-factor-code shahadat@goodtechies.test
echo.
echo   LEAVE THIS WINDOW OPEN. The app only works while it runs.
echo ============================================================
echo.
echo === php artisan serve === >> "%LOG%"
REM Output goes to the log, not the screen: PHP's built-in server prints a
REM line per request, and when it dies the reason is in that stream. This
REM window staying quiet is what "running" looks like.
call php artisan serve --host=127.0.0.1 --port=8000 >> "%LOG%" 2>&1

REM Reached only when the server stops or fails to start.
echo.
echo ============================================================
echo   The server has stopped.
echo   If that was immediate, the reason is just above, and in
echo   %LOG%
echo ============================================================

:hold
echo.
pause
