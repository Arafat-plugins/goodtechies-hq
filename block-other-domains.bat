@echo off
REM ============================================================
REM  goodERP - serve ONLY erp.goodtechies.com on the live server
REM
REM  Double-click once. It:
REM    1. connects to the server (same login as deploy-live.bat)
REM    2. runs deploy/block-other-domains.sh, which adds a "catch-all"
REM       Nginx site that closes the connection for every other name
REM       (host.onepagefolio.com, the bare IP 63.142.251.206, ...)
REM    3. tests erp.goodtechies.com and host.onepagefolio.com from this PC
REM
REM  It never edits the ERP's own Nginx site. If Nginx refuses the change,
REM  or the ERP stops answering, it undoes itself.
REM  Deploys with deploy-live.bat keep the block in place.
REM
REM  Options (type them after the name in a command window):
REM    block-other-domains.bat undo     remove the block again
REM  Safe to run any number of times.
REM ============================================================
setlocal EnableExtensions EnableDelayedExpansion
cd /d "%~dp0"
title goodERP - allow only erp.goodtechies.com

set "SERVER=root@63.142.251.206"
set "ERP=erp.goodtechies.com"
set "OTHER=host.onepagefolio.com"
set "KEY=%USERPROFILE%\.ssh\goodtechies_hq_deploy"
set "REMOTE_SCRIPT=deploy\block-other-domains.sh"
set "SSHOPT=-o ServerAliveInterval=30 -o StrictHostKeyChecking=accept-new"
set "REMOTE_ENV="
for %%a in (%*) do (
    if /i "%%~a"=="undo" set "REMOTE_ENV=UNDO=1 "
)

echo.
echo ============================================================
if defined REMOTE_ENV (
    echo   goodERP - REMOVE the block: every name reaches the ERP again
) else (
    echo   goodERP - allow only %ERP% on the live server
)
echo ============================================================
echo.

where ssh >nul 2>&1
if errorlevel 1 (
    echo [X] ssh is not installed on this PC.
    echo     Windows Settings - Apps - Optional features - Add "OpenSSH Client", then run this again.
    goto :end
)
if not exist "%REMOTE_SCRIPT%" (
    echo [X] %REMOTE_SCRIPT% is missing next to this file.
    goto :end
)

echo [1/3] Connecting to the live server...
if exist "%KEY%" (
    set "SSHOPT=%SSHOPT% -o IdentitiesOnly=yes -i "%KEY%""
    echo     Using the saved login from deploy-live.bat.
) else (
    echo     Paste the server root password when asked - right-click pastes - then press Enter.
    echo     Nothing shows while you paste; that is normal.
)

echo.
echo [2/3] Changing Nginx on the server...
echo.
REM tr removes Windows line endings before bash reads the script.
ssh %SSHOPT% %SERVER% "tr -d '\r' > /root/hq-block-other-domains.sh && %REMOTE_ENV%bash /root/hq-block-other-domains.sh" < "%REMOTE_SCRIPT%"
set "RC=%errorlevel%"

if "%RC%"=="255" (
    echo.
    echo [X] Could not log in to the server - wrong password, or the server is unreachable.
    goto :end
)
if not "%RC%"=="0" goto :summary

echo.
echo [3/3] Testing from this PC...
where curl >nul 2>&1
if errorlevel 1 (
    echo     [warn] curl is not on this PC - open the two addresses in a browser to check.
    goto :summary
)
set "CODE_ERP="
set "CODE_OTHER="
for /f %%c in ('curl -s -o NUL -w "%%{http_code}" --max-time 15 https://%ERP%/') do set "CODE_ERP=%%c"
for /f %%c in ('curl -s -o NUL -w "%%{http_code}" --max-time 15 http://%OTHER%/') do set "CODE_OTHER=%%c"
echo     https://%ERP%   answered: !CODE_ERP!   (200 or 302 = working)
echo     http://%OTHER%   answered: !CODE_OTHER!   (000 = blocked)

:summary
echo.
echo ============================================================
if "%RC%"=="0" (
    if defined REMOTE_ENV (
        echo   DONE. The block is removed.
    ) else (
        echo   DONE. Only %ERP% is served.
        echo   %OTHER% and the bare IP get no answer.
        echo   In a browser, https://%OTHER% may first show a certificate
        echo   warning - past it, the connection is closed. That is expected.
    )
    goto :summary_end
)
if "%RC%"=="1" (
    echo   STOPPED before changing anything. The reason is written above.
    goto :summary_end
)
if "%RC%"=="2" (
    echo   The change did not work, so it was UNDONE. The ERP is as it was.
    echo   The reason is written above.
    goto :summary_end
)
echo   Finished with code %RC%. Read the messages above.

:summary_end
echo ============================================================

:end
echo.
pause
endlocal
