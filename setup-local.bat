@echo off
REM ============================================================
REM  GoodTechies HQ - local setup + dev server (Windows)
REM  Run this from the repo root. Safe to re-run.
REM  Full reference: docs\runbooks\local-setup.md
REM ============================================================
setlocal enabledelayedexpansion
cd /d "%~dp0"
title GoodTechies HQ - local setup

echo.
echo ============================================================
echo   GoodTechies HQ - local setup
echo ============================================================
echo.

REM ---------- 1. Prerequisites ----------
echo [1/6] Checking prerequisites...
set "MISSING="

where php >nul 2>&1
if errorlevel 1 (
    echo    [X] PHP 8.3+          https://windows.php.net/download/
    set "MISSING=1"
) else (
    for /f "tokens=2 delims= " %%v in ('php -r "echo 'PHP '.PHP_VERSION;"') do echo    [ok] PHP %%v
)

where composer >nul 2>&1
if errorlevel 1 (
    echo    [X] Composer 2        https://getcomposer.org/Composer-Setup.exe
    set "MISSING=1"
) else (
    echo    [ok] Composer
)

where node >nul 2>&1
if errorlevel 1 (
    echo    [X] Node 22           https://nodejs.org/
    set "MISSING=1"
) else (
    for /f "delims=" %%v in ('node -v') do echo    [ok] Node %%v
)

where psql >nul 2>&1
if errorlevel 1 (
    echo    [X] PostgreSQL 16     https://www.postgresql.org/download/windows/
    echo         ^(after installing, add its "bin" folder to PATH^)
    set "MISSING=1"
) else (
    echo    [ok] PostgreSQL client
)

if defined MISSING (
    echo.
    echo   Install the items marked [X] above, open a NEW terminal, and run this again.
    echo.
    pause
    exit /b 1
)

REM ---------- 1b. PHP extensions ----------
set "EXTMISSING="
for %%e in (pdo_pgsql intl zip gd bcmath mbstring openssl fileinfo) do (
    php -r "exit(extension_loaded('%%e')?0:1);" >nul 2>&1
    if errorlevel 1 (
        echo    [X] PHP extension missing: %%e
        set "EXTMISSING=1"
    )
)
if defined EXTMISSING (
    echo.
    echo   Enable the extensions above in your php.ini ^(remove the leading ";"^),
    echo   then run this script again.  php --ini  shows which php.ini is in use.
    echo.
    pause
    exit /b 1
)
echo    [ok] PHP extensions
echo.

REM ---------- 2. .env ----------
echo [2/6] Checking .env...
if not exist ".env" (
    if exist "local.env.txt" (
        copy /y "local.env.txt" ".env" >nul
        echo    [ok] .env created from local.env.txt
    ) else (
        copy /y ".env.example" ".env" >nul
        call php artisan key:generate
        echo    .env created from .env.example - fill in the DB passwords, then re-run.
        pause
        exit /b 1
    )
) else (
    echo    [ok] .env present
)
echo.

REM ---------- 3. Composer ----------
echo [3/6] Installing PHP dependencies ^(this takes a few minutes the first time^)...
call composer install --no-interaction
if errorlevel 1 goto :fail
echo.

REM ---------- 4. npm ----------
echo [4/6] Installing JS dependencies...
call npm install
if errorlevel 1 goto :fail
echo.

REM ---------- 5. Database ----------
echo [5/6] Database setup.
echo    This needs your PostgreSQL superuser ^(postgres^) password once.
echo    It is only passed to psql on this machine - nothing is stored or sent.
echo.
for /f "delims=" %%p in ('powershell -NoProfile -Command "$s = Read-Host -AsSecureString '   postgres password'; [System.Runtime.InteropServices.Marshal]::PtrToStringAuto([System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))"') do set "PGPASSWORD=%%p"
echo.

echo    Creating databases...
createdb -h 127.0.0.1 -U postgres goodtechies_hq 2>nul
createdb -h 127.0.0.1 -U postgres goodtechies_hq_test 2>nul

echo    Creating roles and grants ^(hq_migrator / hq_app / hq_ro^)...
for %%d in (goodtechies_hq goodtechies_hq_test) do (
    psql -h 127.0.0.1 -U postgres -d postgres -q ^
        -v db=%%d ^
        -v migrator_password=hqlocal2026 ^
        -v app_password=hqlocal2026 ^
        -v ro_password=hqlocal2026 ^
        -f deploy/sql/roles.sql
    if errorlevel 1 goto :dbfail
)
set "PGPASSWORD="

echo    Running migrations and seeding as hq_migrator...
call php artisan migrate:fresh --seed --database=pgsql_migrator --force
if errorlevel 1 goto :fail
echo.

REM ---------- 6. Run ----------
echo [6/6] Starting the dev server.
echo.
echo ============================================================
echo   Open  http://localhost:8000
echo.
echo   Seeded logins ^(password: hqlocal2026^)
echo     yaseen@goodtechies.test       Employee        - no 2FA, fastest way in
echo     tapu@goodtechies.test         Remote employee - no 2FA
echo     shahadat@goodtechies.test     Admin           - needs a 2FA code
echo     faruk@goodtechies.test        Admin           - needs a 2FA code
echo     accountant@goodtechies.test   Accountant      - needs a 2FA code
echo.
echo   2FA secret for the admin accounts ^(add to Google Authenticator^):
echo     JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP
echo.
echo   Press Ctrl+C in this window to stop the server.
echo ============================================================
echo.
call composer run dev
goto :eof

:dbfail
set "PGPASSWORD="
echo.
echo   Database step failed. Common causes:
echo     - PostgreSQL service is not running ^(services.msc - postgresql-x64-16^)
echo     - wrong postgres password
echo     - psql not on PATH
echo.
pause
exit /b 1

:fail
echo.
echo   A step failed - the error is above. Fix it and run this script again.
echo.
pause
exit /b 1
