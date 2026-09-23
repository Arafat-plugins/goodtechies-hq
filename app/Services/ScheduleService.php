<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Schedule;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\Weekday;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-employee work schedules (master prompt Part D §20, editor in Phase 4).
 *
 * The table is Phase 0's and it is seeded, so nothing here creates the concept — this is the
 * editor's half: read the schedules for the Work Schedule screen, and write one, audited.
 *
 * ## Why a schedule change is audit-logged
 *
 * `schedules` is not settings. It is the input to two rules that decide what somebody's days
 * are called from the moment it is saved: `AttendanceService::isWorkingDay()` decides which
 * days `hq:mark-absent` will mark, and `start_time` decides who is Late. Moving a start time
 * half an hour earlier quietly turns a fortnight of Present days into Late ones the next time
 * anybody looks at a report, and Phase 9 reads those days. So the change is recorded with old
 * and new values, by the same `AuditLogger` and in the same shape as an attendance edit.
 *
 * ## What it does not do
 *
 * It does not touch `employees.tracking_mode`. `office_or_remote` on the schedule says where
 * the work happens; `tracking_mode` says how it is measured, and it is the one that decides
 * who clocks in. Letting this editor write the second from the first would put a second
 * statement of that rule in the application, so the two stay separate and `tracking_mode` is
 * Phase 12's Employees screen.
 */
class ScheduleService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Every tracked employee with their schedule, for the Work Schedule editor.
     *
     * Ordered by name because it is a list of people. Employees with no schedule are included
     * — a missing schedule is the thing an Admin most needs to see on this screen, and hiding
     * the row would hide it.
     *
     * @return Collection<int, Employee>
     */
    public function forEditor(?User $viewer): Collection
    {
        return Employee::query()
            ->attendanceVisibleTo($viewer)
            ->tracked()
            ->with(['user', 'role', 'schedule'])
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->orderBy('users.name')
            ->select('employees.*')
            ->get();
    }

    /**
     * Save an employee's schedule, audited.
     *
     * `updateOrCreate` because `schedules.employee_id` is unique: an employee has one schedule
     * or none, and "create a second" is not a thing this screen can mean. The working days are
     * stored in `Weekday`'s order rather than the order the checkboxes were ticked, so two
     * saves of the same week produce the same JSON and the audit diff shows a change only when
     * there was one.
     *
     * @param  array{working_days: list<string>, working_hours_per_day: numeric-string|float, start_time: ?string, office_or_remote: string}  $attributes
     */
    public function save(Employee $employee, array $attributes, User $actor): Schedule
    {
        return DB::transaction(function () use ($employee, $attributes, $actor): Schedule {
            $schedule = $employee->schedule()->lockForUpdate()->first();
            $old = $schedule === null ? null : $this->auditValues($schedule);

            $schedule = Schedule::updateOrCreate(
                ['employee_id' => $employee->getKey()],
                [
                    'working_days' => $this->inWeekOrder($attributes['working_days']),
                    'working_hours_per_day' => $attributes['working_hours_per_day'],
                    'start_time' => $attributes['start_time'],
                    'office_or_remote' => $attributes['office_or_remote'],
                ],
            );

            $this->audit->record(
                AuditEvent::ScheduleChanged,
                $schedule,
                $old,
                $this->auditValues($schedule),
                $actor,
            );

            return $schedule;
        });
    }

    /**
     * The payload the editor reads for one employee. Null `schedule` is a real answer and the
     * screen says so — see `forEditor()`.
     *
     * @return array<string, mixed>
     */
    public function rowFor(Employee $employee): array
    {
        $schedule = $employee->schedule;

        return [
            'employee' => [
                'id' => $employee->getKey(),
                'name' => $employee->user?->name ?? 'Unknown',
                'role' => $employee->role?->name,
                'employee_number' => $employee->employee_number,
                'tracking_mode' => $employee->tracking_mode->value,
            ],
            'schedule' => $schedule === null ? null : [
                'working_days' => $this->inWeekOrder(is_array($schedule->working_days) ? $schedule->working_days : []),
                'working_hours_per_day' => (float) $schedule->working_hours_per_day,
                // `H:i`, never the column's `H:i:s`: the editor's control is a `time` input and
                // seconds it cannot show are seconds it would silently drop on the next save.
                'start_time' => $schedule->start_time === null
                    ? null
                    : substr((string) $schedule->start_time, 0, 5),
                'office_or_remote' => $schedule->office_or_remote,
            ],
        ];
    }

    /**
     * @param  list<string>  $days
     * @return list<string>
     */
    private function inWeekOrder(array $days): array
    {
        return array_values(array_filter(
            Weekday::values(),
            fn (string $day): bool => in_array($day, $days, true),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(Schedule $schedule): array
    {
        return [
            'working_days' => $schedule->working_days,
            'working_hours_per_day' => (float) $schedule->working_hours_per_day,
            'start_time' => $schedule->start_time,
            'office_or_remote' => $schedule->office_or_remote,
        ];
    }
}
