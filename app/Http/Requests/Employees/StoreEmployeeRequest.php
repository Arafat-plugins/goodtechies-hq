<?php

namespace App\Http\Requests\Employees;

use App\Services\EmployeeAdministrationService;
use App\Support\EmploymentType;
use App\Support\RoleName;
use App\Support\TrackingMode;
use App\Support\Weekday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Hiring somebody: the user, the employee record and the working week, validated in one place.
 *
 * ## `email` is required, because it is the login
 *
 * `users.email` is `NOT NULL UNIQUE` and it is the only credential `LoginController` looks
 * anybody up by — there is no username, no employee-number sign-in and no magic link. An employee
 * without an email address would be an account nobody can ever sign in to, so this field is
 * required rather than nullable however optional it looks on a personnel form.
 *
 * ## There is no `password` field, and that is the decision
 *
 * Part H §1 puts email out of scope for the MVP, and `routes/auth.php` has no password-reset
 * route — login and the two-factor challenge are the whole of the guest surface. So there is
 * nothing to mail an invitation to and nothing for an invitation link to point at.
 * `EmployeeAdministrationService::create()` therefore GENERATES the first-sign-in password and
 * hands it back once for the Admin to pass on; it is never stored in plaintext, never audited and
 * never serialized. The new colleague changes it at Profile → Password, which applies
 * `Password::defaults()` (twelve characters, breach-checked), and an ADMIN or ACCOUNTANT hire is
 * walked through TOTP enrolment by the `two-factor` middleware before they reach anything.
 *
 * ## `employee_number` is optional
 *
 * Left blank it continues the seeded `GT-00N` series. Typed it must be unique, because it is what
 * payroll and the client's own paperwork call this person.
 *
 * ## The schedule travels with the hire
 *
 * Part D §21's onboarding row: *"Employee created with role, schedule, tracking_mode"*. The four
 * schedule fields are the same four `UpdateScheduleRequest` validates, in the same shape, and
 * they are written through `ScheduleService` so that one service remains the only writer of
 * `schedules`. An empty `working_days` is accepted for the reason that request gives: somebody on
 * unpaid leave of absence has one, and refusing it would push an Admin into not hiring them.
 *
 * Authorization is `EmployeePolicy::create`, asked in the controller.
 */
class StoreEmployeeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'employee_number' => ['nullable', 'string', 'max:50', Rule::unique('employees', 'employee_number')],
            'phone' => ['nullable', 'string', 'max:50'],

            // MANAGER is deliberately not offered — Part C §1: it "exists in the roles table from
            // Phase 0 but is assigned to nobody and gets no dedicated UI in MVP".
            'role' => ['required', Rule::in(array_map(
                fn (RoleName $role): string => $role->value,
                EmployeeAdministrationService::ASSIGNABLE_ROLES,
            ))],
            'employment_type' => ['required', Rule::in(EmploymentType::values())],
            'joining_date' => ['nullable', 'date'],
            // Their own manager is a cycle of one, which is the only cycle a create can make:
            // the employee does not exist yet, so nothing else can point back at it.
            'manager_id' => ['nullable', 'integer', Rule::exists('employees', 'id')],

            // How their time is MEASURED — not where it happens, which is the schedule's
            // `office_or_remote`. Two fields because they are two facts (see ScheduleService).
            'tracking_mode' => ['required', Rule::in(array_map(
                fn (TrackingMode $mode): string => $mode->value,
                TrackingMode::cases(),
            ))],

            'working_days' => ['present', 'array'],
            'working_days.*' => [Rule::in(Weekday::values())],
            'working_hours_per_day' => ['required', 'numeric', 'min:0.5', 'max:24'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'office_or_remote' => ['required', Rule::in(['office', 'remote'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'employee_number' => 'employee number',
            'employment_type' => 'employment type',
            'joining_date' => 'joining date',
            'manager_id' => 'manager',
            'tracking_mode' => 'tracking mode',
            'working_days' => 'working days',
            'working_hours_per_day' => 'hours per day',
            'start_time' => 'start time',
            'office_or_remote' => 'work location',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'An email address is required — it is how they sign in.',
            'email.unique' => 'Somebody already has an account with that email address.',
        ];
    }

    /**
     * The attributes `EmployeeAdministrationService::create()` takes.
     *
     * @return array<string, mixed>
     */
    public function employeeAttributes(): array
    {
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'employee_number' => $validated['employee_number'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'role' => RoleName::from($validated['role']),
            'employment_type' => $validated['employment_type'],
            'joining_date' => $validated['joining_date'] ?? null,
            'manager_id' => $validated['manager_id'] ?? null,
            'tracking_mode' => $validated['tracking_mode'],
            'schedule' => [
                'working_days' => array_values(array_unique($validated['working_days'] ?? [])),
                'working_hours_per_day' => (float) $validated['working_hours_per_day'],
                'start_time' => $validated['start_time'] ?? null,
                'office_or_remote' => $validated['office_or_remote'],
            ],
        ];
    }
}
