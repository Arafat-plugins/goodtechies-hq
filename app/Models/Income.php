<?php

namespace App\Models;

use Database\Factories\IncomeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One receipt of money (master prompt Part D §13, §20).
 *
 * The table is `income`, not `incomes`: Part D names it, and the plural of income is income.
 *
 * ## Three things this model refuses to store
 *
 *   1. **Any total.** A month's figure per category is a `SUM` over these rows, computed every
 *      time it is asked (`FinanceService::monthlyRollup()`). A stored total is a second
 *      statement of a fact somebody then has to keep in step — the reasoning that kept
 *      `completed` off `meetings.status` (decision 7-3) and an overdue flag off `tasks`. Here
 *      it would be worse than either: an edit that corrected an amount and missed the rollup
 *      would leave the dashboard and the ledger disagreeing about money.
 *   2. **The category's NAME.** It is one join away and it is editable (Part D §13). A copy
 *      would be a second name for the same category, out of date from the first rename. The
 *      one place the name IS copied is the audit row, and that is the opposite case: an audit
 *      row has to say what was true when it was written, for ever, including after the category
 *      has been renamed or deleted.
 *   3. **`deleted_at`.** Deleting a finance record is a **hard** delete, and the audit row is
 *      what makes it reconstructible. See `auditValues()` below and `FinanceService::delete()`.
 *
 * ## `category_kind` is not fillable and is not on this model at all
 *
 * The column exists (see the migration): it is a constant, set by a DEFAULT, pinned by a CHECK,
 * and referenced by the composite foreign key that stops an expense category being used here.
 * Nothing in PHP writes it, so nothing in PHP can get it wrong.
 *
 * @property int $id
 * @property int $category_id
 * @property int|null $project_id
 * @property string $amount
 * @property Carbon $date
 * @property string|null $notes
 * @property int $recorded_by
 */
#[Fillable([
    'category_id',
    'project_id',
    'amount',
    'date',
    'notes',
    'recorded_by',
])]
// Decision 10-18: `search_vector` is a STORED GENERATED tsvector of this row's own
// searchable text. `select *` loads it (~282 B a row on `tasks`, measured with
// `pg_column_size`), and it belongs in no payload — so it is hidden from every
// `toArray()`, `toJson()` and `dd()`. Hidden, not dropped: search reads the column.
#[Hidden(['search_vector'])]
class Income extends Model
{
    /** @use HasFactory<IncomeFactory> */
    use HasFactory;

    protected $table = 'income';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Exactly as `project_finance` casts the same money. A string in, a string out:
            // never a float, for the reason spelled out in the migration.
            'amount' => 'decimal:2',
            'date' => 'date',
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
     * The optional project link (Part D §13). Null on most rows.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Rows dated inside one calendar month. The whole of the monthly rollup's `WHERE`.
     *
     * A half-open range on `date` rather than `whereYear`/`whereMonth`, so the index on
     * `(date, category_id)` is used instead of a scan with a function on every row.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInMonth(Builder $query, int $year, int $month): void
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();

        $query->whereBetween('date', [$start, $start->copy()->endOfMonth()]);
    }

    /**
     * Everything needed to put this row back, for the audit trail.
     *
     * **This is the promise that makes a hard delete safe.** Part C §4 requires an audit row for
     * *"finance record deleted"* with old and new values; `audit_logs` is the one table in this
     * application the runtime role cannot UPDATE, DELETE or TRUNCATE. So the record of a deleted
     * income lives somewhere strictly more durable than a `deleted_at` column on a table
     * `hq_app` can delete from — and money that vanishes without a trail is the one thing this
     * table cannot allow.
     *
     * Every column is here, including the id and both timestamps, so the row can be re-inserted
     * verbatim. The category's and the project's NAMES travel beside their ids on purpose: an
     * id is only reconstructible while the thing it points at still exists and still means what
     * it meant, and the reader of an audit row is by definition looking at the past. The amount
     * is a string, never a float — the same rule as the column.
     *
     * @return array<string, mixed>
     */
    public function auditValues(): array
    {
        return [
            'id' => (int) $this->getKey(),
            'category_id' => (int) $this->category_id,
            'category_name' => $this->category?->name,
            'category_kind' => $this->category?->kind?->value,
            'project_id' => $this->project_id === null ? null : (int) $this->project_id,
            'project_name' => $this->project?->name,
            'amount' => (string) $this->amount,
            'date' => $this->date?->toDateString(),
            'notes' => $this->notes,
            'recorded_by' => (int) $this->recorded_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
