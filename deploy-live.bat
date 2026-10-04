@echo off
REM ============================================================
REM  goodERP - deploy what is on GitHub to the LIVE server
REM
REM  Double-click it after push-to-github.bat. It:
REM    1. warns you if something on this PC is not on GitHub yet
REM    2. connects to the server (paste the root password, or set up
REM       password-free login once - it offers)
REM    3. runs deploy/live-deploy.sh on the server, which removes junk,
REM       backs up the database and checks the backup, frees memory for
REM       the build, releases, retries once, rolls back by itself if the
REM       release fails, and checks the site
REM    4. copies the new database backup to this PC as well
REM       (%USERPROFILE%\goodtechies-hq-backups, newest 10 kept)
REM
REM  Options (type them after the name in a command window):
REM    deploy-live.bat force             rebuild even when nothing is new
REM    deploy-live.bat clean             only remove junk on the server
REM    deploy-live.bat allow-db-changes  let a release that drops or renames
REM                                      database data go ahead
REM  Safe to run any number of times: with nothing new it does nothing.
REM ============================================================
setlocal EnableExtensions EnableDelayedExpansion
cd /d "%~dp0"
title goodERP - deploy to live

set "SERVER=root@63.142.251.206"
set "SITE=https://erp.goodtechies.com"
set "KEY=%USERPROFILE%\.ssh\goodtechies_hq_deploy"
set "REMOTE_SCRIPT=deploy\live-deploy.sh"
set "SSHOPT=-o ServerAliveInterval=30 -o ServerAliveCountMax=10 -o StrictHostKeyChecking=accept-new"
set "LOCAL_BACKUPS=%USERPROFILE%\goodtechies-hq-backups"
set "KEEP_LOCAL_BACKUPS=10"
set "REMOTE_ENV="
for %%a in (%*) do (
    if /i "%%~a"=="force" set "REMOTE_ENV=!REMOTE_ENV!FORCE=1 "
    if /i "%%~a"=="clean" set "REMOTE_ENV=!REMOTE_ENV!CLEAN_ONLY=1 "
    if /i "%%~a"=="allow-db-changes" set "REMOTE_ENV=!REMOTE_ENV!ALLOW_DB_CHANGES=1 "
)

echo.
echo ============================================================
echo   goodERP - deploy the GitHub version to the live server
echo ============================================================
echo.

REM ---------- tools ----------
where ssh >nul 2>&1
if errorlevel 1 (
    echo [X] ssh is not installed on this PC.
    echo     Windows Settings - Apps - Optional features - Add "OpenSSH Client", then run this again.
    goto :end
)
where git >nul 2>&1
if errorlevel 1 (
    echo [X] git is not installed on this PC.
    goto :end
)
if not exist "%REMOTE_SCRIPT%" (
    echo [X] %REMOTE_SCRIPT% is missing next to this file.
    goto :end
)

REM ---------- 1. is everything on GitHub? ----------
echo [1/4] Checking that your work is on GitHub...
git fetch --quiet origin main
if errorlevel 1 echo     [warn] Could not reach GitHub from this PC - the server will still check GitHub itself.
set "DIRTY=0"
set "AHEAD=0"
for /f %%n in ('git status --porcelain 2^>nul ^| find /c /v ""') do set "DIRTY=%%n"
for /f %%n in ('git rev-list --count origin/main..HEAD 2^>nul') do set "AHEAD=%%n"
if "!DIRTY!!AHEAD!"=="00" (
    echo     [ok] Everything on this PC is on GitHub.
    goto :connect
)
echo.
if not "!DIRTY!"=="0" echo     [warn] !DIRTY! changed file or files on this PC are NOT on GitHub yet.
if not "!AHEAD!"=="0" echo     [warn] !AHEAD! commit or commits on this PC are NOT pushed to GitHub yet.
echo         Only what is on GitHub goes live. Run push-to-github.bat first to include them.
echo.
choice /c YN /m "    Deploy what is on GitHub anyway"
if errorlevel 2 goto :end

:connect
REM ---------- 2. login: key if this PC has one, otherwise the password ----------
echo.
echo [2/4] Connecting to the live server...
if exist "%KEY%" goto :havekey
echo.
echo     This PC can remember the server, so you never type the password again.
choice /c YN /m "    Set up password-free login now (asks for the password one last time)"
if errorlevel 2 goto :nokey
if not exist "%USERPROFILE%\.ssh" mkdir "%USERPROFILE%\.ssh"
ssh-keygen -q -t ed25519 -N "" -C "goodtechies-hq deploy %COMPUTERNAME%" -f "%KEY%"
if errorlevel 1 (
    echo     [X] Could not create the key - continuing with the password.
    goto :nokey
)
echo     Paste the server root password when asked - right-click pastes - then press Enter.
echo     Nothing shows while you paste; that is normal.
type "%KEY%.pub" | ssh %SSHOPT% %SERVER% "umask 077; mkdir -p ~/.ssh; cat >> ~/.ssh/authorized_keys"
if errorlevel 1 (
    echo     [X] The key was not saved on the server - continuing with the password.
    del /q "%KEY%" "%KEY%.pub" >nul 2>&1
    goto :nokey
)
echo     [ok] Done. From now on this PC deploys without a password.

:havekey
set "SSHOPT=%SSHOPT% -o IdentitiesOnly=yes -i "%KEY%""
goto :deploy

:nokey
echo     Paste the server root password when asked - right-click pastes - then press Enter.
echo     Nothing shows while you paste; that is normal.

:deploy
REM ---------- 3. run the safe release on the server ----------
echo.
echo [3/4] Deploying. This takes 2 to 5 minutes.
echo       For about a minute the site shows "back in a moment" - that is normal.
echo.
REM The script is sent from this PC, so the server always runs the newest version of it.
REM tr removes Windows line endings before bash reads it.
ssh %SSHOPT% %SERVER% "tr -d '\r' > /root/hq-live-deploy.sh && %REMOTE_ENV%bash /root/hq-live-deploy.sh" < "%REMOTE_SCRIPT%"
set "RC=%errorlevel%"

REM ---------- 4. a copy of the database backup on this PC ----------
if "%RC%"=="0" goto :copybackup
if "%RC%"=="3" goto :copybackup
goto :summary

:copybackup
echo.
echo [4/4] Copying the database backup to this PC...
if exist "%KEY%" goto :download
choice /c YN /m "    Copy the new database backup to this PC too - asks for the password again"
if errorlevel 2 goto :summary
:download
if not exist "%LOCAL_BACKUPS%" mkdir "%LOCAL_BACKUPS%"
for /f %%t in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd-HHmmss"') do set "NOW=%%t"
scp %SSHOPT% %SERVER%:/root/hq-backups/latest.dump "%LOCAL_BACKUPS%\goodtechies_hq-!NOW!.dump"
if errorlevel 1 (
    echo     [warn] The copy did not download. The backup is still safe on the server in /root/hq-backups.
    goto :summary
)
echo     [ok] Saved: %LOCAL_BACKUPS%\goodtechies_hq-!NOW!.dump
powershell -NoProfile -Command "Get-ChildItem -Path $env:LOCAL_BACKUPS -Filter 'goodtechies_hq-*.dump' | Sort-Object LastWriteTime -Descending | Select-Object -Skip ([int]$env:KEEP_LOCAL_BACKUPS) | Remove-Item -Force" >nul 2>&1

:summary

echo.
echo ============================================================
if "%RC%"=="0" (
    echo   DONE. The live site runs the latest GitHub version.
    echo   Opening %SITE% - press Ctrl+F5 once there.
    start "" "%SITE%"
    goto :summary_end
)
if "%RC%"=="1" (
    echo   STOPPED before changing anything. The live site is untouched.
    echo   The reason is written just above.
    goto :summary_end
)
if "%RC%"=="2" (
    echo   The new version failed to release, so the PREVIOUS version was put back.
    echo   The live site keeps working. The reason is in the messages above.
    goto :summary_end
)
if "%RC%"=="3" (
    echo   Released, but the final check did not pass. Open %SITE% and look.
    goto :summary_end
)
if "%RC%"=="4" (
    echo   Nothing to release - the live site already runs the latest GitHub version.
    echo   Junk on the server was cleaned if you asked for it.
    goto :summary_end
)
if "%RC%"=="255" (
    echo   Could not log in to the server - wrong password, or the server is unreachable.
    echo   If password-free login stopped working, delete this file and run again:
    echo       %KEY%
    goto :summary_end
)
echo   Finished with code %RC%. Read the messages above.

:summary_end
echo ============================================================

:end
echo.
pause
endlocal
