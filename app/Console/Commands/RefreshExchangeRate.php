<?php

namespace App\Console\Commands;

use App\Services\ExchangeRateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Every six hours (D7): in `auto` mode, the USD→BDT rate the finance reports convert at is
 * refreshed from the live market rate. In `manual` mode it does nothing; on a failure the old
 * rate stays.
 */
#[Signature('hq:refresh-exchange-rate')]
#[Description('Refresh the USD to BDT exchange rate from the live market rate (auto mode only)')]
class RefreshExchangeRate extends Command
{
    public function handle(ExchangeRateService $rates): int
    {
        if ($rates->refresh()) {
            $this->info('1 USD = '.$rates->rate().' BDT.');
        } else {
            $this->line('Rate unchanged: 1 USD = '.$rates->rate().' BDT ('.$rates->mode().').');
        }

        return self::SUCCESS;
    }
}
