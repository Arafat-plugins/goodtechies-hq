<?php

namespace App\Models;

use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One payment out (master prompt Part D §13, §20).
 *
 * The mirror of `Income`, and every note on that class applies here: no stored total, no copied
 * category name, no `deleted_at`, and a `category_kind` column no PHP ever writes. The one
 * difference is the one Part D §20 draws — **an expense has no project link**. See the
 * migration for why *Project Cost* does not imply one.
 *
 * @property int $id
 * @property int $category_id
 * @property string $amount
 * @property Carbon $date
 * @property string|null $notes
 * @property int $recorded_by
 */
#[Fillable([
    'category_id',
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
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeInMonth(Builder $query, int $year, int $month): void
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();

        $query->whereBetween('date', [$start, $start->copy()->endOfMonth()]);
    }

    /**
     * Everything needed to put this row back. See `Income::auditValues()` for the argument —
     * it is what makes the hard delete safe.
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
            'amount' => (string) $this->amount,
            'date' => $this->date?->toDateString(),
            'notes' => $this->notes,
            'recorded_by' => (int) $this->recorded_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
