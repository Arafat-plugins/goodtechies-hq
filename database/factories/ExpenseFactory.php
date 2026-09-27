<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\User;
use App\Support\FinanceCategoryKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * $120 of Software today. No project state, because Part D §20 gives an expense no project
     * column — see `2026_10_05_0003_create_expenses_table.php` for why *Project Cost* does not
     * imply one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => fn (): int => self::category(FinanceCategoryKind::Expense, 'Software'),
            'amount' => '120.00',
            'date' => Carbon::today(),
            'notes' => null,
            'recorded_by' => User::factory(),
        ];
    }

    /** A category of this kind and name, made if it is not there yet. */
    public static function category(FinanceCategoryKind $kind, string $name): int
    {
        return (int) FinanceCategory::query()->firstOrCreate(
            ['kind' => $kind->value, 'name' => $name],
            ['position' => (int) FinanceCategory::query()->ofKind($kind)->max('position') + 1],
        )->getKey();
    }

    public function inCategory(FinanceCategory|string $category): static
    {
        return $this->state(fn (): array => [
            'category_id' => $category instanceof FinanceCategory
                ? $category->getKey()
                : self::category(FinanceCategoryKind::Expense, $category),
        ]);
    }

    public function recordedBy(User $user): static
    {
        return $this->state(fn (): array => ['recorded_by' => $user->getKey()]);
    }

    public function of(string $amount): static
    {
        return $this->state(fn (): array => ['amount' => $amount]);
    }

    public function on(CarbonInterface|string $date): static
    {
        return $this->state(fn (): array => [
            'date' => $date instanceof CarbonInterface ? Carbon::instance($date) : Carbon::parse($date),
        ]);
    }
}
