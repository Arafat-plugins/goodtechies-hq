@echo off
cd /d "%~dp0"
if exist app\Console\Commands\GenerateProjectIncome.php del /q app\Console\Commands\GenerateProjectIncome.php
if exist app\Console\Commands\GenerateRecurringExpenses.php del /q app\Console\Commands\GenerateRecurringExpenses.php
if exist app\Services\ProjectIncomeService.php del /q app\Services\ProjectIncomeService.php
if exist tests\Feature\Finance\ProjectIncomeTest.php del /q tests\Feature\Finance\ProjectIncomeTest.php
echo Old automatic-income files removed.
pause
