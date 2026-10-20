<?php

namespace App\Services;

use App\Models\User;
use App\Support\Permission;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The USD→BDT rate the finance reports convert every USD figure at (D7).
 *
 * In `auto` mode the rate follows the live market rate, refreshed every six hours by
 * `hq:refresh-exchange-rate`; in `manual` mode it is the Admin's own figure and nothing
 * overwrites it. Conversion is integer arithmetic on cents — never floats.
 */
class ExchangeRateService
{
    public const SOURCE = 'https://open.er-api.com/v6/latest/USD';

    public const FALLBACK = '122.00';

    public const MIN = 50;

    public const MAX = 500;

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * The stored rate as a 2-decimal string, or the fallback when it is empty or invalid.
     */
    public function rate(): string
    {
        $stored = $this->settings->get('usd_bdt_rate');

        if (! is_numeric($stored) || (float) $stored < self::MIN || (float) $stored > self::MAX) {
            return self::FALLBACK;
        }

        return self::fromCents(self::toCents((string) $stored));
    }

    /**
     * @return 'auto'|'manual'
     */
    public function mode(): string
    {
        return $this->settings->get('usd_bdt_rate_mode') === 'manual' ? 'manual' : 'auto';
    }

    public function updatedAt(): ?string
    {
        $value = $this->settings->get('usd_bdt_rate_updated_at');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Fetch the live rate and store it — only in `auto` mode. On any failure the old rate stays.
     */
    public function refresh(): bool
    {
        if ($this->mode() !== 'auto') {
            return false;
        }

        try {
            $response = Http::timeout(10)->acceptJson()->get(self::SOURCE);

            if (! $response->successful()) {
                Log::warning('Exchange rate refresh failed: HTTP '.$response->status());

                return false;
            }

            $bdt = $response->json('rates.BDT');

            if (! is_numeric($bdt) || (float) $bdt < self::MIN || (float) $bdt > self::MAX) {
                Log::warning('Exchange rate refresh ignored an out-of-range USD→BDT rate.', ['rate' => $bdt]);

                return false;
            }

            $this->settings->setSystem('usd_bdt_rate', number_format(round((float) $bdt, 2), 2, '.', ''));
            $this->settings->setSystem('usd_bdt_rate_updated_at', now()->toIso8601String());

            return true;
        } catch (Throwable $e) {
            Log::warning('Exchange rate refresh failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * The Admin's own rate: mode `manual`, audited through SettingsService::set.
     *
     * @throws ValidationException
     */
    public function setManual(User $actor, string $rate): void
    {
        if (! is_numeric($rate) || (float) $rate < self::MIN || (float) $rate > self::MAX) {
            throw ValidationException::withMessages([
                'rate' => 'The rate must be between '.self::MIN.' and '.self::MAX.' BDT for 1 USD.',
            ]);
        }

        $this->settings->set('usd_bdt_rate_mode', 'manual', $actor, Permission::FinanceManage);
        $this->settings->set('usd_bdt_rate', self::fromCents(self::toCents($rate)), $actor, Permission::FinanceManage);
        $this->settings->setSystem('usd_bdt_rate_updated_at', now()->toIso8601String());
    }

    /**
     * Back to the live rate: mode `auto`, then one refresh straight away.
     */
    public function setAuto(User $actor): void
    {
        $this->settings->set('usd_bdt_rate_mode', 'auto', $actor, Permission::FinanceManage);

        $this->refresh();
    }

    /**
     * An amount in BDT as a 2-decimal string. USD is converted at the stored rate:
     * round(amountCents × rateCents / 100) cents. An unknown currency is treated as BDT.
     */
    public function toBdt(string $amount, string $currency): string
    {
        $cents = self::toCents($amount);

        if (strtoupper($currency) !== 'USD') {
            return self::fromCents($cents);
        }

        return self::fromCents(self::mulDivRound($cents, self::toCents($this->rate()), 100));
    }

    /**
     * Same as toBdt(), in cents.
     */
    public function toBdtCents(string $amount, string $currency): int
    {
        return self::toCents($this->toBdt($amount, $currency));
    }

    /**
     * A decimal string to integer cents, rounding half away from zero past the second decimal.
     */
    public static function toCents(string $amount): int
    {
        $amount = trim($amount);

        if ($amount === '' || ! is_numeric($amount)) {
            return 0;
        }

        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');

        if (str_contains($amount, 'e') || str_contains($amount, 'E')) {
            $amount = number_format((float) $amount, 3, '.', '');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');

        $cents = (int) ($whole === '' ? '0' : $whole) * 100 + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return $negative ? -$cents : $cents;
    }

    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * round($a × $b / $divisor), half away from zero, in integers.
     */
    private static function mulDivRound(int $a, int $b, int $divisor): int
    {
        $product = $a * $b;
        $negative = $product < 0;
        $product = abs($product);

        $result = intdiv($product + intdiv($divisor, 2), $divisor);

        return $negative ? -$result : $result;
    }
}
