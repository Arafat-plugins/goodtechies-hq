<?php

namespace App\Console\Commands;

use App\Services\ProjectIncomeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily at 00:20: record each project's income from its billing settings — the recurring amount
 * on every due date, and last month's approved hours × the rate once the month has ended.
 *
 * Every rule (what is due, no back-fill, the locked-month skip, one run per billed period, the
 * audit row) lives in `ProjectIncomeService::generate()`; this command only supplies today's
 * date in the agency's timezone. Running it twice on one day records nothing the second time.
 */
#[Signature('hq:generate-project-income')]
#[Description('Record the income that projects have earned from their billing settings.')]
class GenerateProjectIncome extends Command
{
    public function handle(ProjectIncomeService $income): int
    {
        $recorded = $income->generate(Carbon::today(config('app.timezone')));

        $this->info(sprintf('%d income record(s) added from projects.', $recorded));

        return self::SUCCESS;
    }
}
