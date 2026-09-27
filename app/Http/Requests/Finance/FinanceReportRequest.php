<?php

namespace App\Http\Requests\Finance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * The window the Finance dashboard and the Monthly financial report are asking for.
 *
 * Both screens keep their window in the **URL** — `/finance?month=2026-09` and
 * `/finance/report?month=2026-09&months=12` — so a month is a link somebody can send and a
 * bookmark keeps (DESIGN.md §5.10, the same rule the Tasks calendar follows). That makes the
 * window input, and input is validated in a Form Request rather than by a controller deciding
 * what a string means (`ViewTaskCalendarRequest` is the pattern).
 *
 * Both keys are optional and each stands alone:
 *
 *   - **`month`** — `YYYY-MM`. Absent, it is the month we are in.
 *   - **`months`** — how many months the trend covers, **ending with `month`**. Absent, it is
 *     `DEFAULT_TREND_MONTHS`. The dashboard ignores it; it is in the rules here because the
 *     window is one idea and a second Form Request would be a second place to change it.
 *
 * ## Why the trend window is in the URL at all
 *
 * A trend whose length is decided by the controller is a chart nobody can cite. Putting the
 * length beside the month means "the year to September 2026" is an address, and the assertion
 * that *the trend covers the months it claims and no more* has something to be made against.
 *
 * ## Why the bounds are 3 and 24
 *
 * Below three points there is no trend, only two numbers and a line between them — the picture
 * would claim a direction the data cannot support. Above twenty-four the x-axis stops being
 * readable at 360 px and the answer belongs to a report with a date range rather than to a
 * month picker. Neither bound is a database limit: the trend is two range-scanned `GROUP BY`
 * queries whatever `months` says, so this is a legibility rule and is written as one.
 */
class FinanceReportRequest extends FormRequest
{
    /**
     * Twelve months, so the same month a year ago is on the chart.
     *
     * An agency on retainers has a shape that only shows up over a year — a website build
     * lands once, the maintenance lines repeat — and a six-month window would show the build
     * as a permanent step change. Twelve is also the window the client already thinks in:
     * Part D §13's unit is the calendar month and Phase 9's payroll period is one row per
     * month, so a year is twelve of the thing the rest of the application counts.
     */
    public const DEFAULT_TREND_MONTHS = 12;

    public const MIN_TREND_MONTHS = 3;

    public const MAX_TREND_MONTHS = 24;

    public function authorize(): bool
    {
        // The route gate is `can:finance.view`, and `FinanceService::monthlyRollup()` asks the
        // policy again. Authorization lives in policies and middleware, never here.
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'month' => ['nullable', 'string', 'date_format:Y-m'],
            'months' => ['nullable', 'integer', 'min:'.self::MIN_TREND_MONTHS, 'max:'.self::MAX_TREND_MONTHS],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'month.date_format' => 'A finance month looks like 2026-09.',
            'months.integer' => 'The trend covers a whole number of months.',
            'months.min' => 'A trend needs at least '.self::MIN_TREND_MONTHS.' months to have a direction.',
            'months.max' => 'A trend of more than '.self::MAX_TREND_MONTHS.' months is a date-range report, not a month picker.',
        ];
    }

    /**
     * The first day of the month being asked for, in the application's timezone.
     *
     * `startOfMonth()` rather than the parsed date, because everything downstream ranges from
     * it: `Carbon::createFromFormat('Y-m', …)` keeps today's day-of-month, which would make
     * `?month=2026-02` on the 30th an invalid date rather than February.
     */
    public function monthStart(): Carbon
    {
        $value = $this->string('month')->trim()->value();

        if ($value === '') {
            return Carbon::now(config('app.timezone'))->startOfMonth();
        }

        return Carbon::createFromFormat('Y-m', $value)->startOfMonth();
    }

    /** How many months the trend covers, this one included. */
    public function trendMonths(): int
    {
        return (int) ($this->integer('months') ?: self::DEFAULT_TREND_MONTHS);
    }
}
