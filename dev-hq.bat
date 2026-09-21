@echo off
REM ============================================================
REM  GoodTechies HQ - development mode (Windows)
REM
REM  Runs `composer run dev`: Laravel, the queue worker and the
REM  Vite dev server together.
REM
REM  Why this one and not start-hq.bat:
REM  start-hq.bat serves the built assets through PHP's own
REM  built-in web server. That server handles ONE request at a
REM  time on Windows, and a page like the admin dashboard asks
REM  for a dozen JS chunks at once - enough to stall or kill it,
REM  which leaves the HTML rendered and the body empty.
REM  Here, Vite serves every asset on port 5173 and PHP only ever
REM  serves the HTML document, so that pile-up cannot happen.
REM
REM  Edits to .vue files also apply live here, with no rebuild.
REM
REM  Close this with Ctrl+C, NOT the X button. Ctrl+C lets Vite
REM  delete public\hot on the way out. Closing the window strands
REM  that file, and every later page then points its scripts at a
REM  dev server that is gone - a white page. start-hq.bat clears
REM  a stranded one if it happens.
REM ============================================================
setlocal
cd /d "%~dp0"
title GoodTechies HQ - dev mode

if not exist ".env" (
    echo   No .env found. Run setup-local.bat first.
    echo.
    pause
    exit /b 1
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

for /f "tokens=1,* delims==" %%a in ('findstr /b /c:"SEED_PASSWORD=" .env')          do set "SEED_PW=%%b"
for /f "tokens=1,* delims==" %%a in ('findstr /b /c:"SEED_TWO_FACTOR_SECRET=" .env') do set "SEED_2FA=%%b"

echo.
echo ============================================================
echo   GoodTechies HQ - dev mode
echo.
echo   Open  http://localhost:8000
echo   ^(Vite serves assets on 5173 - you do not open that one^)
echo.
echo   No 2FA needed:
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
echo   Wait for Vite to print "ready" before loading the page.
echo   Stop with Ctrl+C in this window - not the X button.
echo ============================================================
echo.

call composer run dev

echo.
echo ============================================================
echo   Dev mode has stopped. If you closed it with Ctrl+C,
echo   public\hot was cleaned up and all is well.
echo ============================================================
echo.
pause
