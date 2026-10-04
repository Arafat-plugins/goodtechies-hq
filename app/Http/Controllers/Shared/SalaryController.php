<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\SetSalaryRequest;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Services\PayrollService;
use App\Services\SettingsService;
use App\Support\UserStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * **Salary settings per employee** (master prompt Part D §14: *"salary settings per employee
 * (base salary, allowances — audit-logged)"*, Phase 9).
 *
 * ## Admin only, and the key says so rather than the role
 *
 * The routes carry `can:payroll.approve`, which `RolePermissionSeeder` grants to **ADMIN and to
 * nobody else** — the Accountant holds `payroll.view_own`, `payroll.view_others` and
 * `payroll.draft`, and not this one. So the Accountant gets **403** here, which is the right
 * refusal rather than a 404: Part B §3 rule 1 draws the line at *"a whole route or surface the
 * role may not use returns 403 — a route's existence is not sensitive"*, and this is a whole
 * screen, not somebody's record.
 *
 * That the Accountant is refused is deliberate and is Part D §14's own placement: the sentence
 * *"salary settings per employee"* sits under **Admin → Payroll**, beside Review, Approve, Lock
 * and Reverse lock, while the Accountant's half of the same paragraph is *"fills/adjusts base
 * salary, allowance, bonus, deduction, advance"* — which is adjusting **this month's payroll
 * item**, not setting what somebody is paid from now on. The two are different acts on
 * different tables, and this is the one that changes every future month.
 *
 * ## The screen is a history, not an edit form
 *
 * Decision 9-1, and the promise the whole feature rests on: `employee_salaries` is
 * **effective-dated rows**. `PayrollService::setSalary()` writes a NEW row per change and
 * `salaryFor()` reads the latest row at or before a given day — which is why September keeps
 * recomputing to September's figure after a November raise, and why nothing in this controller
 * updates an existing row. It could not: `setSalary()` is the only writer and it is the
 * service's, not this class's.
 *
 * So the screen says so in three ways rather than trusting anybody to know it: the form's date
 * field is required and labelled *starts on*, each person's recent rows are listed beneath with
 * the day each one began, and the page states in words that earlier months keep their own
 * figures. The complete trail — who changed what, from what, when — is `audit_logs`,
 * ADMIN-only and append-only; `setSalary()` writes the `salary.changed` row inside the same
 * transaction as the salary itself, and **this controller writes no second trail**.
 */
class SalaryController extends Controller
{
    /**
     * How many past salary rows each person's card lists.
     *
     * Enough to answer *"when did this change, and what was it before?"* without opening the
     * audit log, which is the question this list exists for — and few enough that a page of
     * fifteen employees stays a page. Five changes is several years of ordinary salary history
     * for one person.
     */
    private const HISTORY_ROWS = 5;

    public function __construct(
        private readonly PayrollService $payroll,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Everybody who works here, what they are on **today**, and how they got there.
     *
     * **Active employees only.** Part D §14 drafts a month *"for all active employees"* and
     * Part B §3 rule 11 says a departure is `status = inactive` and never a delete — so a
     * leaver keeps their rows and their payslips, and setting a new salary for somebody who no
     * longer works here is not a thing this screen offers. Their history is not lost; it is
     * simply not on a form whose only verb is *set what they are paid from now on*.
     *
     * *Today* is the as-at date for the "current" figure, and it is stated rather than implied:
     * a row whose salary starts next month shows the figure in force now, with the future one
     * in the history beneath it, because the number somebody is being paid this week is the one
     * a screen called *current* has to mean.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', EmployeeSalary::class);

        $asAt = Carbon::today();

        $employees = Employee::query()
            ->where('employees.status', UserStatus::Active->value)
            ->with(['user:id,name', 'role:id,name'])
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->orderBy('users.name')
            ->select('employees.*')
            ->get();

        // One query for every history on the page rather than one per employee: the whole
        // table for these people, newest first, grouped in memory. `employee_salaries` is one
        // row per person per change — a handful of rows per employee, not a feed.
        $history = EmployeeSalary::query()
            ->whereIn('employee_id', $employees->modelKeys())
            ->with('setter:id,name')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->groupBy('employee_id');

        return Inertia::render('Shared/Salaries/Index', [
            'asAt' => $asAt->toDateString(),
            'currency' => (string) $this->settings->get('currency'),
            'employees' => $employees->map(function (Employee $employee) use ($asAt, $history): array {
                $rows = $history->get($employee->getKey()) ?? collect();

                return [
                    'id' => (int) $employee->getKey(),
                    'name' => $employee->user?->name ?? 'Unknown',
                    'role' => $employee->role?->name,

                    // What they are on today. `salaryFor()` is the service's single statement
                    // of "the latest row at or before this day" — never a `first()` on the
                    // list above, which would have picked up a raise that starts next month.
                    'current' => $this->salaryRow($this->payroll->salaryFor($employee, $asAt)),

                    'history' => $rows
                        ->take(self::HISTORY_ROWS)
                        ->map(fn (EmployeeSalary $salary): ?array => $this->salaryRow($salary))
                        ->values()
                        ->all(),

                    'history_total' => $rows->count(),
                ];
            })->values()->all(),
        ]);
    }

    /**
     * Set a salary from a date.
     *
     * One line of work, and every rule in it belongs to `PayrollService::setSalary()`: the
     * policy check, the new row, the `salary.changed` audit entry with the previous figures as
     * `old`, and the transaction that makes a change without its audit row impossible. Nothing
     * is re-checked here and nothing is logged here — a second trail would be a second thing to
     * keep in step, and the one that drifted would be the one somebody read in a dispute.
     *
     * `{employee}` is a model binding, so an id that matches nothing is a 404 from the router.
     * There is no scope to apply on top of it: an Admin may set anybody's salary, which is what
     * `payroll.approve` on the route already said.
     */
    public function update(SetSalaryRequest $request, Employee $employee): RedirectResponse
    {
        $attributes = $request->salaryAttributes();

        $this->payroll->setSalary(
            $request->user(),
            $employee,
            $attributes['base_salary'],
            $attributes['allowance'],
            $attributes['effective_from'],
        );

        return back()->with('success', sprintf(
            "%s's salary is set from %s. Earlier months keep the figures they were drafted with.",
            $employee->user?->name ?? 'The employee',
            Carbon::parse($attributes['effective_from'])->format('j F Y'),
        ));
    }

    /**
     * Delete one salary row (polish 002). The policy and the audit row are
     * `PayrollService::deleteSalary()`'s.
     *
     * `{salary}` is NOT route-model bound: binding runs before the route's `can:` middleware,
     * so a bound id would answer a non-Admin 404 for a missing row and 403 for a present one.
     * Resolving it here keeps every non-Admin at the same 403.
     */
    public function destroy(Request $request, int $salary): RedirectResponse
    {
        $salary = EmployeeSalary::query()->findOrFail($salary);
        $name = $salary->employee?->user?->name ?? 'The employee';
        $from = $salary->effective_from?->format('j F Y');

        $this->payroll->deleteSalary($request->user(), $salary);

        return back()->with('success', sprintf(
            "%s's salary from %s was deleted. Months already drafted keep their figures.",
            $name,
            $from ?? 'that date',
        ));
    }

    /**
     * One `employee_salaries` row as the screen reads it, or null where there is none.
     *
     * Five keys. The two figures are the exact decimal strings PostgreSQL holds — never floats,
     * never pre-formatted with a currency symbol, which is the screen's decision — and
     * `set_by` is a name rather than an id, because *"who set this"* is the question and an id
     * is not an answer anybody can read.
     *
     * **Null is a real state**: a new joiner whose salary nobody has set yet. The screen prints
     * *"No salary set yet"* and still offers the form, which is exactly the case this page
     * exists to fix.
     *
     * @return array{id: int, base_salary: string, allowance: string, effective_from: string|null, set_by: string|null}|null
     */
    private function salaryRow(?EmployeeSalary $salary): ?array
    {
        if ($salary === null) {
            return null;
        }

        return [
            'id' => (int) $salary->getKey(),
            'base_salary' => (string) $salary->base_salary,
            'allowance' => (string) $salary->allowance,
            'effective_from' => $salary->effective_from?->toDateString(),
            'set_by' => $salary->setter?->name,
        ];
    }
}
