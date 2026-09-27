<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Support\PayrollStatus;
use App\Support\RoleName;
use App\Support\UserStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A salary per employee, a raise to prove the history works, and September 2026's payroll draft
 * (master prompt Part D §14, Phase 9).
 *
 * ## It writes the rows directly, and NOT through `PayrollService`
 *
 * `MeetingSeeder` goes through its service; this one deliberately does not, for the reason
 * `FinanceSeeder` does not: **the audit log**. `PayrollService::setSalary()` writes a
 * `salary.changed` row and `approve()` writes `payroll.approved`, because Part C §4 requires
 * them — and an audit log whose first rows were written by the installer is an audit log nobody
 * reads. It is also an invariant that a dozen existing test files rest on: `LoginTest`,
 * `SettingsServiceTest`, `ClientServiceTest` and the rest each call `$this->seed()` and then
 * assert `AuditLog::count()` is 0, so that the row they are about is the only row there is. A
 * seeder that logged five salary decisions would break all of them, in folders this slice does
 * not own, for the sake of demo data.
 *
 * What still holds these rows to the rules is the **database**, which is the argument for
 * putting them there: the unique index refuses a second September, the `CHECK` refuses a
 * negative salary, `payroll_items_one_per_employee_per_period` refuses a second line for
 * anybody, and `net_salary` is computed by PostgreSQL from the columns beside it — from this
 * seeder exactly as from a controller.
 *
 * ## September 2026 is seeded as a DRAFT, and it stays open
 *
 * This is the one decision in the file worth arguing, because the demo would be more impressive
 * the other way.
 *
 * A `locked` or `paid` September would close the month to the whole finance ledger (Part D §13,
 * `FinanceService::assertPeriodIsOpen()`) — and **September 2026 is the month `FinanceSeeder`
 * fills**: Part D §13's acceptance example lives in it (*Maintenance $860, SEO $800, Website
 * $1,250 → $2,910*), the Admin dashboard's operating-result card reads it, and every test in
 * `tests/Feature/Finance` records income and expenses dated in it after calling `$this->seed()`.
 * Seeding a lock would turn the client's demo database into one where the Accountant cannot
 * enter an invoice, and it would do it through a rule with no error on the screen until
 * somebody pressed Save.
 *
 * So the seeded month is a Draft: one line per active employee, at their current salary, with
 * nothing calculated — exactly what `hq:create-payroll-draft` leaves behind on the 1st, which is
 * the state the Accountant actually starts their month in. Walking it to Locked is the
 * acceptance path a person clicks through, and `PayrollLockTest` is what proves the lock works.
 *
 * ## Idempotent
 *
 * Every row is looked up by its natural key before it is written — `(employee, effective_from)`
 * for a salary, the month for the period, `(period, employee)` for a line. The launcher seeds
 * on every start; a reseed that added a second September, or a second line for Tapu, would
 * double the figure this seeder exists to make true and would do it silently.
 */
class PayrollSeeder extends Seeder
{
    /** The month Part D §13's acceptance example and the whole finance demo live in. */
    public const MONTH = '2026-09-01';

    /**
     * Salaries as they were when each person joined, and the one raise since.
     *
     * **Tapu's raise is the point of this list.** He is on 900 from January and 1,100 from July,
     * so the seeded database can answer *"what did September cost?"* and *"what did February
     * cost?"* with two different numbers from the same table — which is the whole reason
     * `employee_salaries` is effective-dated rather than a mutable current row. Re-drafting
     * February after that raise still produces February's 900, and
     * `PayrollCalculationTest` asserts it.
     *
     * @var list<array{email: string, base: string, allowance: string, from: string}>
     */
    private const SALARIES = [
        ['email' => 'shahadat@goodtechies.test', 'base' => '2000.00', 'allowance' => '200.00', 'from' => '2026-01-01'],
        ['email' => 'faruk@goodtechies.test', 'base' => '1800.00', 'allowance' => '200.00', 'from' => '2026-01-01'],
        ['email' => 'tapu@goodtechies.test', 'base' => '900.00', 'allowance' => '100.00', 'from' => '2026-01-01'],
        ['email' => 'tapu@goodtechies.test', 'base' => '1100.00', 'allowance' => '100.00', 'from' => '2026-07-01'],
        ['email' => 'yaseen@goodtechies.test', 'base' => '800.00', 'allowance' => '100.00', 'from' => '2026-01-01'],
        ['email' => 'accountant@goodtechies.test', 'base' => '1000.00', 'allowance' => '100.00', 'from' => '2026-01-01'],
    ];

    public function run(): void
    {
        $admin = User::query()
            ->whereHas('employee.role', fn ($query) => $query->where('name', RoleName::ADMIN->value))
            ->orderBy('id')
            ->first();

        if ($admin === null) {
            throw new RuntimeException('PayrollSeeder needs a seeded ADMIN; run TeamSeeder first.');
        }

        $this->seedSalaries($admin);
        $this->seedDraft();
    }

    private function seedSalaries(User $admin): void
    {
        foreach (self::SALARIES as $row) {
            $employee = $this->employeeOf($row['email']);

            if ($employee === null) {
                continue;
            }

            EmployeeSalary::query()->updateOrCreate(
                [
                    'employee_id' => $employee->getKey(),
                    'effective_from' => $row['from'],
                ],
                [
                    'base_salary' => $row['base'],
                    'allowance' => $row['allowance'],
                    // Part D §14 puts salary settings on the Admin screen; the seeded history
                    // says an Admin set them, because that is who would have.
                    'set_by' => $admin->getKey(),
                ],
            );
        }
    }

    /**
     * September 2026's draft: one line per active employee, at the salary in force on the 1st.
     *
     * The figures are read from `employee_salaries` exactly as `PayrollService` reads them, so
     * Tapu's line carries his July figure and not his January one — the seeded database
     * demonstrates the history rule rather than describing it.
     */
    private function seedDraft(): void
    {
        $month = PayrollPeriod::monthKey(self::MONTH);

        $period = PayrollPeriod::query()->firstOrCreate(
            ['month' => $month->toDateString()],
            ['status' => PayrollStatus::Draft],
        );

        $employees = Employee::query()
            ->where('employees.status', UserStatus::Active->value)
            ->with('user')
            ->orderBy('employees.id')
            ->get();

        foreach ($employees as $employee) {
            $salary = EmployeeSalary::query()->inForceOn($employee, $month)->first();

            if ($salary === null) {
                continue;
            }

            PayrollItem::query()->firstOrCreate(
                [
                    'payroll_period_id' => $period->getKey(),
                    'employee_id' => $employee->getKey(),
                ],
                [
                    'base_salary' => $salary->base_salary,
                    'allowance' => $salary->allowance,
                    // Nothing else. A Draft is what the 1st leaves behind: the Accountant has
                    // not entered a bonus yet and Calculate has not been pressed, so
                    // `leave_impact` is a real zero rather than an invented figure.
                ],
            );
        }
    }

    private function employeeOf(string $email): ?Employee
    {
        return Employee::query()
            ->whereHas('user', fn ($query) => $query->where('email', $email))
            ->first();
    }

    /** @return Carbon the month this seeder fills, for anything that needs to name it */
    public static function month(): Carbon
    {
        return PayrollPeriod::monthKey(self::MONTH);
    }
}
