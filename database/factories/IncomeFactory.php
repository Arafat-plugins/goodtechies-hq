<?php

namespace Database\Factories;

use App\Models\FinanceCategory;
use App\Models\Income;
use App\Models\Project;
use App\Models\User;
use App\Support\FinanceCategoryKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Income>
 */
class IncomeFactory extends Factory
{
    /**
     * $500 of Maintenance today, linked to no project.
     *
     * **Linked to no project on purpose.** Part D §13 makes the project link optional, and a
     * factory whose default invented one would make "optional" the state nobody ever tested —
     * the same reason `MeetingFactory` links to nothing. `->forProject()` is one call away.
     *
     * The category is resolved, not created: a factory that made a fresh *Maintenance* category
     * per row would defeat the `(kind, name)` unique index on the second call and would make
     * every rollup test a one-row rollup. `firstOrCreate` on the kind and the name means a test
     * that has seeded gets the seeded category and a test that has not gets one made here.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => fn (): int => self::category(FinanceCategoryKind::Income, 'Maintenance'),
            'project_id' => null,
            'amount' => '500.00',
            'date' => Carbon::today(),
            'notes' => null,
            'recorded_by' => User::factory(),
        ];
    }

    /**
     * A category of this kind and name, made if it is not there yet.
     *
     * Shared with `ExpenseFactory` through being duplicated rather than through a base class:
     * two four-line methods beat a factory hierarchy, and the two sides are free to diverge.
     */
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
                : self::category(FinanceCategoryKind::Income, $category),
        ]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (): array => ['project_id' => $project->getKey()]);
    }

    public function recordedBy(User $user): static
    {
        return $this->state(fn (): array => ['recorded_by' => $user->getKey()]);
    }

    /** Money is a string here, never a float — see the migration. */
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
