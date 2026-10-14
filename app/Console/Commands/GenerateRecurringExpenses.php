<?php

namespace App\Console\Commands;

use App\Services\FinanceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily at 00:15: record every repeating expense that has fallen due (a VPS every month, a
 * domain every year), the way Xero's repeating bills post themselves.
 *
 * ## The command decides nothing
 *
 * Every rule — what is due, the closed-month skip, the duplicate guard, the audit row — lives in
 * `FinanceService::generateDueRecurringExpenses()`, so this command only supplies today's date
 * in the agency's timezone. A missed night is not a missed charge: the service catches up on
 * every date that fell due since, and running it twice on one day records nothing the second
 * time.
 */
#[Signature('hq:generate-recurring-expenses')]
#[Description('Record the repeating expenses that are due (VPS, domain, hosting…).')]
class GenerateRecurringExpenses extends Command
{
    public function handle(FinanceService $finance): int
    {
        $created = $finance->generateDueRecurringExpenses(Carbon::today(config('app.timezone')));

        $this->info(sprintf('%d expense(s) recorded.', $created));

        return self::SUCCESS;
    }
}
