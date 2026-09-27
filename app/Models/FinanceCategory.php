<?php

namespace App\Models;

use App\Support\FinanceCategoryKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A name money is filed under, on one side of the ledger (master prompt Part D §13, §20).
 *
 * Seeded by `FinanceSeeder` with Part D §13's two lists and **Admin-editable** thereafter —
 * which is the whole reason this is a table and not an enum. The parallel in this repo is
 * `LeaveType`: a short list that is the agency's policy rather than its data, seeded
 * idempotently, ordered by a `position` column so a category added later does not sort to the
 * bottom of every picker for ever.
 *
 * ## `kind` is immutable once the category is in use, and the database says so
 *
 * `kind` is fillable, because creating a category has to set it. It is **not** among the
 * attributes `FinanceService::updateCategory()` will apply, and beneath that the composite
 * foreign key on `income` and `expenses` carries `ON UPDATE RESTRICT`: a category that anything
 * is filed under cannot change sides at all. Reclassifying a category in use would move money
 * from one side of the ledger to the other without touching a single finance row, and every
 * rollup already reported on would silently change.
 *
 * ## Deleting one is refused while it is in use
 *
 * `ON DELETE RESTRICT`, for the reason in the income migration: a finance record must never
 * lose what it was for. `isInUse()` and `usageCount()` are what let the service say so in a
 * sentence, and what let a screen show the blast radius before the click rather than after —
 * the same job `TagResource::task_count` does.
 *
 * @property int $id
 * @property FinanceCategoryKind $kind
 * @property string $name
 * @property int $position
 */
#[Fillable(['kind', 'name', 'position'])]
class FinanceCategory extends Model
{
    protected $table = 'finance_categories';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => FinanceCategoryKind::class,
        ];
    }

    /**
     * @return HasMany<Income, $this>
     */
    public function income(): HasMany
    {
        return $this->hasMany(Income::class, 'category_id');
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category_id');
    }

    /**
     * One side of the ledger.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOfKind(Builder $query, FinanceCategoryKind $kind): void
    {
        $query->where('kind', $kind->value);
    }

    /**
     * The order every picker offers them in. By `position` then name, never by id — see
     * `LeaveType::scopeInOrder()` for why.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy('name');
    }

    public function isIncome(): bool
    {
        return $this->kind === FinanceCategoryKind::Income;
    }

    /**
     * How many finance records are filed under this category.
     *
     * Reads the one side the category belongs to. The other side cannot hold a row pointing
     * here — the composite foreign key makes that impossible — so counting both would be
     * counting a case the database has ruled out.
     */
    public function usageCount(): int
    {
        return $this->isIncome()
            ? $this->income()->count()
            : $this->expenses()->count();
    }

    public function isInUse(): bool
    {
        return $this->usageCount() > 0;
    }

    /**
     * What an audit row records this category as, before and after.
     *
     * The id travels so a rename can be followed back to the row it happened to, and the
     * `kind` travels so a reader a year later does not have to look the category up to know
     * which side of the ledger the change was on — by then it may not exist.
     *
     * @return array<string, mixed>
     */
    public function auditValues(): array
    {
        return [
            'id' => (int) $this->getKey(),
            'kind' => $this->kind?->value,
            'name' => $this->name,
            'position' => (int) $this->position,
        ];
    }
}
