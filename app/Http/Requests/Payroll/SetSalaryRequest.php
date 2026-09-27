<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Setting somebody's salary from a date (master prompt Part D §14: *"salary settings per
 * employee (base salary, allowances — audit-logged)"*, Phase 9).
 *
 * Validation only. The authorization is `can:payroll.approve` on the route and
 * `EmployeeSalaryPolicy::create()` inside `PayrollService::setSalary()` — this class has no
 * `authorize()` override, because authorization in this repo lives in policies and middleware
 * and nowhere else (AGENTS.md).
 *
 * ## Three fields, and the third one is the feature
 *
 * `effective_from` is not metadata about the change; it **is** the change. Salary history here
 * is effective-dated rows (decision 9-1): `setSalary()` writes a new row per date and
 * `salaryFor()` reads the latest row at or before the day it is asked about, which is what
 * makes September keep recomputing to September's figure after a November raise. So the date
 * is `required` — there is no "now" default to fall through to, because a screen that lets
 * somebody set a salary without saying when it starts is a screen that has quietly decided for
 * them, and the day it decides wrong is the day a locked month moves.
 *
 * **Past dates are allowed, and deliberately.** A backdated correction is ordinary payroll
 * work, and it is safe here in a way it would not be under a mutable current row: a
 * `payroll_items` row holds its own copy of the figures it was drafted with, so a salary
 * backdated into an approved month changes what the *next* draft computes and changes nothing
 * that has already been drafted, approved or paid. Future dates are allowed for the same
 * reason and are the normal case — a raise agreed in October that starts in November.
 *
 * ## Money is a string, and it never becomes a float
 *
 * `decimal:0,2` bounds the places and `salaryAttributes()` hands the value on as the string it
 * arrived as. `employee_salaries.base_salary` is `decimal(12,2)`, binary float cannot hold
 * 0.10, and `(int) (8.6 * 100)` is 859 — the same reason `StoreIncomeRequest` does this
 * (decision 8-4).
 *
 * A **base salary of zero is refused** and an allowance of zero is not. Zero allowance is a
 * real answer — most people have none. Zero base is what an empty field submits, and "this
 * person is paid nothing" is a sentence that should have to be said some other way than by
 * leaving a box blank: it would silently drive a leave impact, a draft and a net of nothing at
 * all for that employee, every month, until somebody noticed.
 */
class SetSalaryRequest extends FormRequest
{
    /** The column is `decimal(12, 2)`, so this is the largest value it can hold. */
    public const MAX_AMOUNT = '9999999999.99';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_salary' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],

            // Zero is allowed here and nowhere else in this request. See the class note.
            'allowance' => ['required', 'numeric', 'decimal:0,2', 'gte:0', 'max:'.self::MAX_AMOUNT],

            // `Y-m-d`, not `date`: a salary starts on a DAY, and accepting "next tuesday" or a
            // timestamp would put a value in `effective_from` whose meaning depended on the
            // server's clock and timezone.
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'base_salary.gt' => 'A base salary has to be more than zero. Leave the allowance at 0 if there is none.',
            'base_salary.decimal' => 'A base salary has at most two decimal places.',
            'allowance.decimal' => 'An allowance has at most two decimal places.',
            'effective_from.required' => 'Say which day this salary starts on — earlier months keep their own figures.',
            'effective_from.date_format' => 'Give the start date as a calendar day.',
        ];
    }

    /**
     * The three values, ready for `PayrollService::setSalary()`.
     *
     * Both figures are cast to `string` and never to `float` — see the class note.
     *
     * @return array{base_salary: string, allowance: string, effective_from: string}
     */
    public function salaryAttributes(): array
    {
        $validated = $this->validated();

        return [
            'base_salary' => (string) $validated['base_salary'],
            'allowance' => (string) $validated['allowance'],
            'effective_from' => (string) $validated['effective_from'],
        ];
    }
}
