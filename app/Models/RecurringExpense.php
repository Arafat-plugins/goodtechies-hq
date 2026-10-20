<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A repeating expense: a cost set up once (a VPS every month, a domain every year). It is never
 * recorded on its own: when it falls due the expenses page shows it, and an Admin records it as an
 * ordinary `Expense` (`FinanceService::recordDueRecurringExpense()`) or skips that occurrence.
 *
 * Due dates are always counted from `start_date` (`dueDateFor()`), never from the previous due
 * date, so a template started on the 31st lands on Feb 28 and then on Mar 31 again rather than
 * drifting to the 28th for ever.
 *
 * @property int $id
 * @property int $category_id
 * @property int|null $project_id
 * @property bool $billable
 * @property string $amount
 * @property string $frequency
 * @property Carbon $start_date
 * @property Carbon|null $ends_on
 * @property int $occurrences
 * @property Carbon $next_due_date
 * @property string|null $notes
 * @property bool $active
 * @property int $created_by
 */
#[Fillable([
    'category_id',
    'project_id',
    'billable',
    'amount',
    'currency',
    'frequency',
    'start_date',
    'ends_on',
    'occurrences',
    'next_due_date',
    'notes',
    'active',
    'created_by',
])]
class RecurringExpense extends Model
{
    /**
     * Months per step, keyed by frequency.
     *
     * @var array<string, int>
     */
    public const FREQUENCIES = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];

    protected $table = 'recurring_expenses';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'start_date' => 'date',
            'ends_on' => 'date',
            'next_due_date' => 'date',
            'billable' => 'boolean',
            'active' => 'boolean',
            'occurrences' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<FinanceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The expense rows this template has recorded.
     *
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * The due date of the n-th occurrence (0 = the start date), counted from the start so that
     * month-end dates never drift.
     */
    public function dueDateFor(int $occurrence): Carbon
    {
        return $this->start_date->copy()->addMonthsNoOverflow($occurrence * self::FREQUENCIES[$this->frequency]);
    }

    /**
     * Everything needed to put this template back, for the audit trail.
     *
     * @return array<string, mixed>
     */
    public function auditValues(): array
    {
        return [
            'id' => (int) $this->getKey(),
            'category_id' => (int) $this->category_id,
            'category_name' => $this->category?->name,
            'project_id' => $this->project_id === null ? null : (int) $this->project_id,
            'project_name' => $this->project?->name,
            'billable' => (bool) $this->billable,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'frequency' => $this->frequency,
            'start_date' => $this->start_date?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'next_due_date' => $this->next_due_date?->toDateString(),
            'notes' => $this->notes,
            'active' => (bool) $this->active,
        ];
    }
}
