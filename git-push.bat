@echo off
REM ============================================================
REM  GoodTechies HQ - first push to GitHub
REM  Safe to re-run. Stops before pushing if a secret is staged.
REM ============================================================
setlocal enabledelayedexpansion
cd /d "%~dp0"
title GoodTechies HQ - push to GitHub

set "REPO=https://github.com/Arafat-plugins/goodtechies-hq.git"

echo.
echo ============================================================
echo   Pushing GoodTechies HQ to GitHub
echo   %REPO%
echo ============================================================
echo.

where git >nul 2>&1
if errorlevel 1 (
    echo   [X] Git is not on PATH.  https://git-scm.com/download/win
    pause
    exit /b 1
)

REM ---------- 1. init ----------
if not exist ".git" (
    echo [1/5] Initialising the repository...
    git init -q
    git config user.name "Mamun"
    git config user.email "mamunhqpay@gmail.com"
) else (
    echo [1/5] Repository already initialised.
)
echo.

REM ---------- 2. stage ----------
echo [2/5] Staging files...
git add -A
echo.

REM ---------- 3. secret check ----------
echo [3/5] Checking that no secrets are staged...
set "LEAK="
for %%f in (.env local.env.txt .env.backup .env.production auth.json) do (
    git diff --cached --name-only | findstr /x /c:"%%f" >nul 2>&1
    if not errorlevel 1 (
        echo    [X] %%f is staged - it must NOT go to GitHub.
        set "LEAK=1"
    )
)
git diff --cached --name-only | findstr /i /c:"vendor/" >nul 2>&1
if not errorlevel 1 (
    echo    [!] vendor/ is staged - check .gitignore.
    set "LEAK=1"
)
git diff --cached --name-only | findstr /i /c:"node_modules/" >nul 2>&1
if not errorlevel 1 (
    echo    [!] node_modules/ is staged - check .gitignore.
    set "LEAK=1"
)

if defined LEAK (
    echo.
    echo   Stopped. Nothing has been pushed.
    echo   Unstage with:   git reset
    echo   Then fix .gitignore and run this again.
    echo.
    pause
    exit /b 1
)
echo    [ok] no secrets staged
echo.

REM ---------- 4. commit ----------
echo [4/5] Committing...
git diff --cached --quiet
if not errorlevel 1 (
    echo    Nothing new to commit.
) else (
    git commit -q -m "Phase 0: foundation - auth, 2FA, roles, shells, audit logs, deploy kit"
    echo    [ok] committed
)
git branch -M main
echo.

REM ---------- 5. push ----------
echo [5/5] Pushing to GitHub...
git remote get-url origin >nul 2>&1
if errorlevel 1 (
    git remote add origin %REPO%
) else (
    git remote set-url origin %REPO%
)

echo    A browser window may open for GitHub sign-in. Complete it there.
echo.
git push -u origin main
if errorlevel 1 goto :pushfail

echo.
echo ============================================================
echo   Done. https://github.com/Arafat-plugins/goodtechies-hq
echo ============================================================
echo.
pause
goto :eof

:pushfail
echo.
echo   Push failed. The usual cause: the GitHub repo already has a
echo   commit ^(you ticked "Add a README" when creating it^).
echo.
echo   Fix - run these two, then this script again:
echo     git pull origin main --allow-unrelated-histories
echo     git push -u origin main
echo.
pause
exit /b 1
