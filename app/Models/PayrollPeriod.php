<?php

namespace App\Models;

use App\Exceptions\PayrollStateException;
use App\Support\PayrollStatus;
use Carbon\CarbonInterface;
use Database\Factories\PayrollPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One month of payroll (master prompt Part D §14, §20).
 *
 * ## The status is guarded at the model — decision 2-9, a fifth time
 *
 * `payroll_periods.status` is writable on a row that does not exist yet — a period is born
 * `draft` — and after that ONLY through `applyTransition()`, which re-checks
 * `PayrollStatus::TRANSITIONS` itself. Anything else that leaves `status` dirty on an existing
 * row throws.
 *
 * `Task` and `LeaveRequest` carry the same guard, and the argument is stronger here than in
 * either. A period's status does not only describe this row:
 *
 *   - **`locked` and `paid` close the whole month's finance ledger.**
 *     `FinanceService::assertPeriodIsOpen()` reads this column on every income and expense
 *     write in the application (Part D §13). A `->update(['status' => 'locked'])` somewhere
 *     would freeze a month for the Accountant with no audit row and nobody to ask.
 *   - **`approved` makes the period read-only to the Accountant**, and `locked` makes it
 *     read-only to everybody. Those are authorization outcomes computed from this column.
 *   - **The one backward move is a lock reversal**, which Part C §4 requires an audit row and a
 *     stated reason for. A second write path to `status` is a way to un-lock a month without
 *     either.
 *
 * There is deliberately **no `withoutStatusGuard()`**. `Task` has one for the demo seeder, which
 * paints a board showing every status at once; `PayrollSeeder` goes through `PayrollService`,
 * so no such escape hatch exists here and none should be added.
 *
 * ## `locked_at` is maintained beside the status, and the database checks the pair
 *
 * `payroll_periods_lock_is_whole` makes `locked_at IS NOT NULL` exactly when the status closes
 * the month. `applyTransition()` therefore maintains the timestamp itself rather than leaving
 * it to each caller — five callers each remembering is five chances to hit a constraint
 * violation on a screen about money.
 *
 * @property int $id
 * @property Carbon $month
 * @property PayrollStatus $status
 * @property Carbon|null $locked_at
 * @property int|null $lock_reversed_by
 * @property string|null $lock_reversal_reason
 */
#[Fillable([
    'month',
    'status',
])]
class PayrollPeriod extends Model
{
    /** @use HasFactory<PayrollPeriodFactory> */
    use HasFactory;

    /**
     * This instance is inside `applyTransition()`, the one sanctioned status write.
     *
     * Per instance rather than static, so a nested save of some other period cannot inherit
     * this one's permission to move.
     */
    private bool $transitioning = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'status' => PayrollStatus::class,
            'locked_at' => 'datetime',
        ];
    }

    /**
     * The status guard. See the class docblock.
     */
    protected static function booted(): void
    {
        static::saving(function (self $period): void {
            if (! $period->exists || ! $period->isDirty('status')) {
                return;
            }

            if ($period->transitioning) {
                return;
            }

            throw PayrollStateException::statusWrittenOutsideTheMachine();
        });

        static::saved(function (self $period): void {
            $period->transitioning = false;
        });
    }

    /**
     * Move this period's status. The only door in the guard above.
     *
     * It checks the map and maintains `locked_at`, and nothing else: **who** may make the move
     * is `PayrollPeriodPolicy`'s answer and `PayrollService` asks for it before calling this,
     * and the side effects — the audit row, the reversal's reason — are the service's, in the
     * same transaction.
     *
     * @throws PayrollStateException
     */
    public function applyTransition(PayrollStatus $to): static
    {
        $from = $this->status;

        if ($from === null || ! $from->canTransitionTo($to)) {
            throw PayrollStateException::transition($from, $to);
        }

        $this->transitioning = true;
        $this->status = $to;

        // The pair the database checks, kept in step here rather than at five call sites.
        // Entering `locked` stamps it; leaving the closing statuses — which only a reversal
        // does — clears it, because the month is open again.
        $this->locked_at = $to->closesTheMonth()
            ? ($this->locked_at ?? Carbon::now())
            : null;

        return $this;
    }

    /**
     * @return HasMany<PayrollItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lockReverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lock_reversed_by');
    }

    /**
     * The month this date falls in, as this table keys it: the first of that month.
     *
     * One statement of the key, used by the period lookup, the seeder, the draft command and —
     * most importantly — `FinanceService::assertPeriodIsOpen()`, which turns every income and
     * expense date into this before asking whether the month is closed.
     */
    public static function monthKey(CarbonInterface|string $date): Carbon
    {
        return Carbon::parse($date)->startOfMonth()->startOfDay();
    }

    /**
     * The period covering this date, or null. **The finance lock's query.**
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForMonth(Builder $query, CarbonInterface|string $date): void
    {
        $query->whereDate('month', self::monthKey($date)->toDateString());
    }

    /**
     * Periods whose status closes the month to the finance ledger (Part D §13).
     *
     * @param  Builder<$this>  $query
     */
    public function scopeClosingTheMonth(Builder $query): void
    {
        $query->whereIn('status', PayrollStatus::closingValues());
    }

    /**
     * **The sentence `FinanceService` refuses a finance write with.**
     *
     * It lives here, in payroll's own territory, rather than as a factory on
     * `FinanceStateException` — the rule is Part D §13's and the words belong with the table
     * that decides it, while the exception TYPE has to stay `FinanceStateException` because
     * that is what the income and expense controllers catch and turn into a flash message.
     * `assertPeriodIsOpen()` is the only caller.
     *
     * The month and the status are both in it because a person refused an edit needs to know
     * which month is shut and whether it is merely locked (an Admin can reverse that) or
     * already paid (nobody can).
     */
    public static function closedMonthMessage(CarbonInterface|string $month, PayrollStatus $status): string
    {
        return sprintf(
            '%s is a %s payroll period, so its income and expenses can no longer be changed.%s',
            self::monthKey($month)->format('F Y'),
            strtolower($status->label()),
            $status === PayrollStatus::Locked
                ? ' An Admin can reverse the lock if this needs to change.'
                : '',
        );
    }

    public function label(): string
    {
        return $this->month?->format('F Y') ?? '';
    }
}
