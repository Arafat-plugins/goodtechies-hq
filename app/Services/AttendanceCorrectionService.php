<?php

namespace App\Services;

use App\Exceptions\AttendanceCorrectionException;
use App\Exceptions\AttendanceStateException;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use App\Support\AttendanceCorrectionStatus;
use App\Support\AttendanceStatus;
use App\Support\NotificationType;
use App\Support\Permission;
use App\Support\UserStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Polish 029: an employee asks for a day to be corrected, and an Admin answers.
 *
 * The client's case: clocked in Late by mistake, with no way to say so. The request carries a
 * reason; the people who correct attendance are told; approving it makes the day Present through
 * `AttendanceService::edit()` — the one writer of a corrected day, which audits it — and
 * declining it leaves the day as it was. Either way the employee is told.
 */
class AttendanceCorrectionService
{
    /**
     * The statuses a request can be about: the ones that count against somebody.
     *
     * @var list<AttendanceStatus>
     */
    public const CORRECTABLE = [AttendanceStatus::Late, AttendanceStatus::HalfDay, AttendanceStatus::Absent];

    /** How far back a day can still be questioned. */
    public const WINDOW_DAYS = 31;

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * The employee asks. Refused for a day that is not theirs to question: in the future, too
     * old, not Late / Half day / Absent, or already waiting for an answer.
     *
     * @throws AttendanceCorrectionException
     */
    public function request(Employee $employee, CarbonInterface $date, string $reason, User $actor): AttendanceCorrection
    {
        $date = Carbon::parse($date)->startOfDay();

        if ($date->isFuture()) {
            throw AttendanceCorrectionException::futureDay();
        }

        if ($date->lessThan(Carbon::today()->subDays(self::WINDOW_DAYS))) {
            throw AttendanceCorrectionException::tooOld(self::WINDOW_DAYS);
        }

        $day = $this->attendance->dayFor($employee, $date, $this->record($employee, $date));

        if ($day->status === null || ! in_array($day->status, self::CORRECTABLE, true)) {
            throw AttendanceCorrectionException::notCorrectable();
        }

        $correction = DB::transaction(function () use ($employee, $date, $reason, $day): AttendanceCorrection {
            $pending = AttendanceCorrection::query()
                ->where('employee_id', $employee->getKey())
                ->whereDate('date', $date->toDateString())
                ->where('status', AttendanceCorrectionStatus::Pending->value)
                ->lockForUpdate()
                ->exists();

            if ($pending) {
                throw AttendanceCorrectionException::alreadyPending();
            }

            return AttendanceCorrection::query()->create([
                'employee_id' => $employee->getKey(),
                'date' => $date->toDateString(),
                'current_status' => $day->status?->value,
                'reason' => trim($reason),
                'status' => AttendanceCorrectionStatus::Pending,
            ]);
        });

        $correction->setRelation('employee', $employee);

        $this->notifications->notify(
            NotificationType::AttendanceCorrectionRequested,
            $correction,
            $this->approversFor($employee),
            $this->payload($correction, $employee->user?->name ?? 'Somebody'),
            $actor,
        );

        return $correction;
    }

    /**
     * Approve: the day becomes Present, keeping the times it has. Through the one writer of a
     * corrected day, so the change is audited with the old and new values and the reason.
     *
     * @throws AttendanceCorrectionException
     * @throws AttendanceStateException
     */
    public function approve(AttendanceCorrection $correction, User $actor, ?string $note = null): AttendanceCorrection
    {
        $correction = $this->decide($correction, $actor, AttendanceCorrectionStatus::Approved, $note, function (AttendanceCorrection $correction, User $actor): void {
            $employee = $correction->employee;
            $record = $this->record($employee, $correction->date);

            $this->attendance->edit($employee, $correction->date, [
                'status' => AttendanceStatus::Present,
                'clock_in' => $record?->clock_in,
                'clock_out' => $record?->clock_out,
                'note' => 'Correction approved: '.$correction->reason,
            ], $actor);
        });

        $this->notifications->notify(
            NotificationType::AttendanceCorrectionApproved,
            $correction,
            $this->employeeUser($correction),
            $this->payload($correction, 'Attendance'),
            $actor,
        );

        return $correction;
    }

    /**
     * Decline: the day stays as it is, and the employee is told why when a reason was given.
     *
     * @throws AttendanceCorrectionException
     */
    public function reject(AttendanceCorrection $correction, User $actor, ?string $note = null): AttendanceCorrection
    {
        $correction = $this->decide($correction, $actor, AttendanceCorrectionStatus::Rejected, $note);

        $this->notifications->notify(
            NotificationType::AttendanceCorrectionRejected,
            $correction,
            $this->employeeUser($correction),
            $this->payload($correction, 'Attendance', ['reason' => $correction->decision_note]),
            $actor,
        );

        return $correction;
    }

    /**
     * Pending requests about people this reader manages, oldest first.
     *
     * @return Collection<int, AttendanceCorrection>
     */
    public function pendingFor(User $reader): Collection
    {
        return AttendanceCorrection::query()
            ->where('status', AttendanceCorrectionStatus::Pending->value)
            ->whereIn('employee_id', Employee::query()->attendanceVisibleTo($reader)->select('id'))
            ->with('employee.user')
            ->orderBy('date')
            ->orderBy('id')
            ->get();
    }

    /**
     * The latest request per day for one employee's month, keyed by date.
     *
     * @return array<string, array<string, mixed>>
     */
    public function forMonth(Employee $employee, CarbonInterface $month): array
    {
        $from = Carbon::parse($month)->startOfMonth();

        return AttendanceCorrection::query()
            ->where('employee_id', $employee->getKey())
            ->whereBetween('date', [$from->toDateString(), $from->copy()->endOfMonth()->toDateString()])
            ->with('decider')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (AttendanceCorrection $correction): string => $correction->date->toDateString())
            ->map(fn (AttendanceCorrection $correction): array => $correction->toPayload())
            ->all();
    }

    /**
     * @param  (callable(AttendanceCorrection, User): void)|null  $apply
     */
    private function decide(
        AttendanceCorrection $correction,
        User $actor,
        AttendanceCorrectionStatus $outcome,
        ?string $note,
        ?callable $apply = null,
    ): AttendanceCorrection {
        return DB::transaction(function () use ($correction, $actor, $outcome, $note, $apply): AttendanceCorrection {
            /** @var AttendanceCorrection $locked */
            $locked = AttendanceCorrection::query()->whereKey($correction->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== AttendanceCorrectionStatus::Pending) {
                throw AttendanceCorrectionException::alreadyDecided();
            }

            $locked->load('employee.user');

            if ($apply !== null) {
                $apply($locked, $actor);
            }

            $note = $note === null ? null : trim($note);

            $locked->status = $outcome;
            $locked->decided_by = (int) $actor->getKey();
            $locked->decided_at = now();
            $locked->decision_note = $note === '' ? null : $note;
            $locked->save();

            return $locked;
        });
    }

    private function record(Employee $employee, CarbonInterface $date): ?AttendanceRecord
    {
        return AttendanceRecord::query()
            ->where('employee_id', $employee->getKey())
            ->forDate(Carbon::parse($date))
            ->first();
    }

    /**
     * Everybody who could correct this employee's day: `attendance.manage_others`, narrowed to
     * whose attendance they can see.
     *
     * @return Collection<int, User>
     */
    private function approversFor(Employee $employee): Collection
    {
        return User::query()
            ->where('status', UserStatus::Active->value)
            ->get()
            ->filter(fn (User $user): bool => $user->hasPermission(Permission::AttendanceManageOthers)
                && Employee::query()->attendanceVisibleTo($user)->whereKey($employee->getKey())->exists())
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function employeeUser(AttendanceCorrection $correction): Collection
    {
        $user = $correction->employee?->user;

        return new Collection($user === null ? [] : [$user]);
    }

    /**
     * The date and the status in question, and nothing the reader might later lose the right
     * to see: the employee's reason stays on the request.
     *
     * @param  array<string, mixed>  $extra
     * @return array{title: string, context: array<string, mixed>}
     */
    private function payload(AttendanceCorrection $correction, string $title, array $extra = []): array
    {
        return [
            'title' => $title,
            'context' => array_filter($extra + [
                'date' => $correction->date->toDateString(),
                'current_status' => $correction->current_status,
                'current_label' => AttendanceStatus::tryFrom($correction->current_status)?->label(),
            ], fn (mixed $value): bool => $value !== null),
        ];
    }
}
