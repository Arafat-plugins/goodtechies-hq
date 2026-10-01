<?php

namespace Database\Seeders;

use App\Models\Expense;
use App\Models\FinanceCategory;
use App\Models\Income;
use App\Models\Project;
use App\Models\User;
use App\Support\FinanceCategoryKind;
use App\Support\RoleName;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The finance category lists, and a September 2026 the Admin can open (master prompt Part D
 * §13, Phase 8).
 *
 * ## The categories are policy, and are written directly
 *
 * Part D §13 gives both lists and Phase 8 calls them *"seeded, Admin-editable"*. They are
 * written with `updateOrCreate` on `(kind, name)` — not through `FinanceService` — for the same
 * reason `LeaveSeeder` writes `leave_types` directly: this is the list arriving, not an Admin
 * changing it, and a seeder that logged thirteen `configuration.changed` rows on every start of
 * `start-hq.bat` would fill the audit log with the news that the software works.
 *
 * `position` is set from the order below, because it is the order every picker offers them in
 * and it must not be the id — a category added later to a database that already has thirteen
 * would otherwise sort to the bottom for ever.
 *
 * ## The money is written directly, and NOT through `FinanceService`
 *
 * `MeetingSeeder` goes through `MeetingService`, and this seeder deliberately does not. The
 * difference is the audit log. `MeetingService` writes `activity_logs`; every write path in
 * `FinanceService` writes `audit_logs`, because Part C §4 requires it — and **an audit log
 * whose first thirteen rows were written by the installer is an audit log nobody reads.**
 *
 * It is also an invariant three existing test files already rest on: `LoginTest`,
 * `SettingsServiceTest` and `EmployeeAdministrationServiceTest` each call `$this->seed()` and
 * then assert `AuditLog::count()` is 0, so that the row they are about is the only row there
 * is. A seeder that logged September's invoices would break all three, in three folders this
 * slice does not own, for the sake of demo data.
 *
 * So the rows are `create()`d here, recorded_by the seeded Accountant. What still holds them to
 * the rules is the database: the composite foreign key refuses an expense category on an income
 * row, and the CHECK refuses a non-positive amount, **from this seeder exactly as from a
 * controller**. That is the argument for putting those two rules in PostgreSQL rather than only
 * in `FinanceService`, and this file is the first caller that proves it.
 *
 * ## September 2026 is Part D §13's acceptance example, and it is EXACT
 *
 * > September 2026 — Maintenance $860, SEO $800, Website $1,250 → **$2,910**
 *
 * The three income lines below add to those three figures and to that total, and they are split
 * across the seeded projects so the sentence is true of real rows rather than of one row per
 * category. **Other** is seeded as a category and left empty, so the running application also
 * demonstrates that a category with no rows this month is absent from the rollup rather than
 * present as a zero.
 *
 * The expenses add to $2,110, so the month's operating result is $800 — a positive number, and
 * a small enough one that the Admin dashboard's net card is visibly doing arithmetic rather
 * than echoing the income total.
 *
 * ## Idempotent
 *
 * Each row is looked up by its date and its note before it is written. The launcher seeds on
 * every start; a reseed that added September's invoices a second time would double the number
 * this seeder exists to make true, and would do it silently.
 */
class FinanceSeeder extends Seeder
{
    /**
     * Part D §13's income list, in picker order.
     *
     * @var list<string>
     */
    public const INCOME_CATEGORIES = ['Maintenance', 'SEO', 'Website', 'Other'];

    /**
     * Part D §13's expense list, in picker order.
     *
     * @var list<string>
     */
    public const EXPENSE_CATEGORIES = [
        'Payroll',
        'Office',
        'Hosting',
        'Software',
        'Marketing',
        'Utilities',
        'Operations',
        'Project Cost',
        'Other',
    ];

    /**
     * September 2026, income. Maintenance 350 + 260 + 250 = 860; SEO 500 + 300 = 800;
     * Website 1250. Total 2910 — Part D §13's number.
     *
     * **The seeded notes name no client, and that is not tidiness.**
     *
     * A note is free text on a finance row, and the Accountant reads every one of them. Part C
     * gives them no client names, so demo data that types one into a note hands them, through
     * the back door, exactly what `AccountantProjectResource` spends four keys refusing at the
     * front — and it does it in a field no forbidden-key walk can see, because the leak is the
     * VALUE and the key is `notes`.
     *
     * It showed up as a split in the Part C guard: one test over the project blocks with no
     * exclusions, and a wider one that had to strip `notes` before it could pass. A guard with
     * an exception carved into it is a guard that gets widened later, so the seeder changed
     * instead. **The linked project is where a row says what it was for** — a real relation,
     * policy-checked, carrying a name the Accountant is entitled to.
     *
     * @var list<array{category: string, amount: string, date: string, notes: string, project: string|null}>
     */
    private const INCOME = [
        [
            'category' => 'SEO',
            'amount' => '500.00',
            'date' => '2026-09-03',
            'notes' => 'September SEO retainer',
            'project' => 'Buffalo Modular — SEO',
        ],
        [
            'category' => 'SEO',
            'amount' => '300.00',
            'date' => '2026-09-03',
            'notes' => 'September SEO retainer',
            'project' => 'Heat Gap — SEO Retainer',
        ],
        [
            'category' => 'Maintenance',
            'amount' => '350.00',
            'date' => '2026-09-05',
            'notes' => 'September maintenance retainer',
            'project' => 'Buffalo Modular — Website Maintenance',
        ],
        [
            'category' => 'Maintenance',
            'amount' => '260.00',
            'date' => '2026-09-05',
            'notes' => 'September maintenance retainer',
            'project' => 'APH — Website Maintenance',
        ],
        [
            'category' => 'Maintenance',
            'amount' => '250.00',
            'date' => '2026-09-08',
            'notes' => 'September maintenance retainer, no project linked',
            'project' => 'abc.com — Monthly Maintenance',
        ],
        [
            'category' => 'Website',
            'amount' => '1250.00',
            'date' => '2026-09-18',
            'notes' => 'Website build, second stage',
            'project' => 'Buffalo Modular — Website Development',
        ],
    ];

    /**
     * September 2026, expenses. 1400 + 180 + 95 + 120 + 200 + 60 + 55 = 2110.
     *
     * The Payroll line is a plain expense row and **not** a payroll run: Phase 9 owns
     * `payroll_periods` and this seeder must not pretend to. It is here because a finance
     * dashboard whose largest outgoing is missing is not a finance dashboard.
     *
     * @var list<array{category: string, amount: string, date: string, notes: string}>
     */
    private const EXPENSES = [
        ['category' => 'Office', 'amount' => '180.00', 'date' => '2026-09-01', 'notes' => 'Office rent share — September'],
        ['category' => 'Hosting', 'amount' => '95.00', 'date' => '2026-09-02', 'notes' => 'Client hosting — September'],
        ['category' => 'Software', 'amount' => '120.00', 'date' => '2026-09-04', 'notes' => 'SEO and design tooling subscriptions'],
        ['category' => 'Utilities', 'amount' => '60.00', 'date' => '2026-09-06', 'notes' => 'Internet and electricity share'],
        ['category' => 'Marketing', 'amount' => '200.00', 'date' => '2026-09-10', 'notes' => 'Google Ads — goodtechies.com'],
        ['category' => 'Operations', 'amount' => '55.00', 'date' => '2026-09-12', 'notes' => 'Bank charges and accounting software'],
        ['category' => 'Payroll', 'amount' => '1400.00', 'date' => '2026-09-28', 'notes' => 'September salaries'],
    ];

    public function run(): void
    {
        $this->seedCategories(FinanceCategoryKind::Income, self::INCOME_CATEGORIES);
        $this->seedCategories(FinanceCategoryKind::Expense, self::EXPENSE_CATEGORIES);

        // The two category lists are reference data and every install gets them; the income
        // and expense rows below are demo data and production does not (DatabaseSeeder::seedsDemo).
        if (! DatabaseSeeder::seedsDemo()) {
            return;
        }

        $accountant = User::query()
            ->whereHas('employee.role', fn ($query) => $query->where('name', RoleName::ACCOUNTANT->value))
            ->first();

        if ($accountant === null) {
            throw new RuntimeException('FinanceSeeder needs the seeded ACCOUNTANT; run TeamSeeder first.');
        }

        $this->seedIncome($accountant);
        $this->seedExpenses($accountant);
    }

    /**
     * @param  list<string>  $names
     */
    private function seedCategories(FinanceCategoryKind $kind, array $names): void
    {
        foreach ($names as $position => $name) {
            FinanceCategory::updateOrCreate(
                ['kind' => $kind->value, 'name' => $name],
                ['position' => $position],
            );
        }
    }

    /**
     * **Idempotence is keyed on `(date, amount)`, not on the note.**
     *
     * It was the note, and that made a seeded row's identity depend on its prose: taking the
     * client's name out of two notes made them equal, two rows silently merged, and September
     * came to $2,350 instead of Part D's $2,910 — a demo ledger quietly short by $560, which
     * the rollup tests caught only because that number is written down in the plan. A note is
     * free text somebody edits; a payment is identified by when it landed and how much it was.
     */
    private function seedIncome(User $accountant): void
    {
        foreach (self::INCOME as $row) {
            if (Income::where('date', $row['date'])->where('amount', $row['amount'])->exists()) {
                continue;
            }

            Income::create([
                'category_id' => $this->categoryId(FinanceCategoryKind::Income, $row['category']),
                'project_id' => $this->projectId($row['project']),
                'amount' => $row['amount'],
                'date' => $row['date'],
                'notes' => $row['notes'],
                'recorded_by' => $accountant->getKey(),
            ]);
        }
    }

    private function seedExpenses(User $accountant): void
    {
        foreach (self::EXPENSES as $row) {
            if (Expense::where('date', $row['date'])->where('amount', $row['amount'])->exists()) {
                continue;
            }

            Expense::create([
                'category_id' => $this->categoryId(FinanceCategoryKind::Expense, $row['category']),
                'amount' => $row['amount'],
                'date' => $row['date'],
                'notes' => $row['notes'],
                'recorded_by' => $accountant->getKey(),
            ]);
        }
    }

    private function categoryId(FinanceCategoryKind $kind, string $name): int
    {
        return (int) FinanceCategory::query()
            ->ofKind($kind)
            ->where('name', $name)
            ->firstOrFail()
            ->getKey();
    }

    /**
     * The demo project by name, or null when this database has no DemoSeeder projects.
     *
     * Null rather than an exception, because the project link is optional by design: a database
     * seeded without the demo clients still gets a correct September rollup, which is what this
     * seeder is really for. The by-project report is the part that would be thin, and that is
     * an honest consequence of having no projects.
     */
    private function projectId(?string $name): ?int
    {
        if ($name === null) {
            return null;
        }

        return Project::query()->where('name', $name)->value('id');
    }
}
