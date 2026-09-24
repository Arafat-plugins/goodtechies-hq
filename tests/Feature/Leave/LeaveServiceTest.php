<?php

use App\Events\LeaveApproved;
use App\Events\LeaveCorrectionRequested;
use App\Events\LeaveRejected;
use App\Events\LeaveRequested;
use App\Exceptions\LeaveStateException;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveService;
use App\Support\AttendanceStatus;
use App\Support\AuditEvent;
use App\Support\LeaveStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| LeaveService: applying, deciding, and everything an approval does
|--------------------------------------------------------------------------
|
| The seeded week is Sunday to Thursday (decision 0-6), so Friday and
| Saturday are not working days for anybody. Every date below is chosen
| against that and named, so a reader can check the arithmetic:
|
|   2026-10-04  Sunday     working
|   2026-10-05  Monday     working
|   2026-10-06  Tuesday    working
|   2026-10-08  Thursday   working
|   2026-10-09  Friday     NOT a working day
|   2026-10-10  Saturday   NOT a working day
|
| They are also chosen to miss every seeded holiday, so that the day counts
| here are the schedule's answer and not the holiday calendar's.
|
*/

const LEAVE_SUNDAY = '2026-10-04';
const LEAVE_MONDAY = '2026-10-05';
const LEAVE_TUESDAY = '2026-10-06';
const LEAVE_THURSDAY = '2026-10-08';
const LEAVE_FRIDAY = '2026-10-09';
const LEAVE_SATURDAY = '2026-10-10';

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->otherAdmin = User::where('email', 'faruk@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->annual = LeaveType::where('name', 'Annual')->firstOrFail();
    $this->unpaid = LeaveType::where('name', 'Unpaid')->firstOrFail();
    $this->other = LeaveType::where('name', 'Other')->firstOrFail();

    $this->leave = app(LeaveService::class);
});

/*
|--------------------------------------------------------------------------
| Counting days
|--------------------------------------------------------------------------
*/

it('counts only the working days of the employee own schedule', function () {
    // Thursday to Saturday: one working day on a Sunday-to-Thursday week.
    $request = $this->leave->apply(
        $this->yaseen,
        $this->yaseen->employee,
        $this->annual,
        LEAVE_THURSDAY,
        LEAVE_SATURDAY,
        'Wedding in the family.',
    );

    expect($request->days)->toBe(1)
        ->and($request->start_date->toDateString())->toBe(LEAVE_THURSDAY)
        ->and($request->end_date->toDateString())->toBe(LEAVE_SATURDAY);
});

it('refuses a range that holds no working days at all', function () {
    expect(fn () => $this->leave->apply(
        $this->yaseen,
        $this->yaseen->employee,
        $this->annual,
        LEAVE_FRIDAY,
        LEAVE_SATURDAY,
        'A long weekend, apparently.',
    ))->toThrow(LeaveStateException::class, 'no working days');
});

it('counts every day for an employee with no schedule, so the Accountant can apply', function () {
    // Part C §1 gives "Apply for own leave" to every role including the ACCOUNTANT, who has no
    // schedule row at all. Reading `isWorkingDay()`'s "no schedule works no days" here would
    // make every request they ever filed a refusal for zero days.
    expect($this->accountant->employee->schedule)->toBeNull();

    $request = $this->leave->apply(
        $this->accountant,
        $this->accountant->employee,
        $this->annual,
        LEAVE_THURSDAY,
        LEAVE_SATURDAY,
        'Three days away from the books.',
    );

    expect($request->days)->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Overlap
|--------------------------------------------------------------------------
*/

it('refuses a request that overlaps a pending one', function () {
    $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->annual, LEAVE_SUNDAY, LEAVE_TUESDAY, 'Family commitment.');

    expect(fn () => $this->leave->apply(
        $this->yaseen,
        $this->yaseen->employee,
        $this->annual,
        LEAVE_TUESDAY,
        LEAVE_THURSDAY,
        'Overlapping on the Tuesday.',
    ))->toThrow(LeaveStateException::class, 'overlap');
});

it('refuses a request that overlaps an approved one', function () {
    $first = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->annual, LEAVE_SUNDAY, LEAVE_MONDAY, 'Family commitment.');
    $this->leave->approve($this->admin, $first);

    expect(fn () => $this->leave->apply(
        $this->yaseen,
        $this->yaseen->employee,
        $this->annual,
        LEAVE_MONDAY,
        LEAVE_TUESDAY,
        'Overlapping on the Monday.',
    ))->toThrow(LeaveStateException::class, 'overlap');
});

it('allows a second request over the same days once the first is rejected', function () {
    $first = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->annual, LEAVE_SUNDAY, LEAVE_MONDAY, 'Family commitment.');
    $this->leave->reject($this->admin, $first, 'Too much on that week.');

    $second = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->annual, LEAVE_SUNDAY, LEAVE_MONDAY, 'Asking again, differently.');

    expect($second->status)->toBe(LeaveStatus::Pending);
});

it('refuses two overlapping holding rows at the database, not only in the service', function () {
    // The service's check is only there to produce a readable sentence; the promise is the
    // EXCLUDE constraint. This writes the second row straight past the service.
    LeaveRequest::factory()
        ->forEmployee($this->yaseen->employee)
        ->ofType($this->annual)
        ->between(LEAVE_SUNDAY, LEAVE_MONDAY)
        ->create();

    // Nothing is asserted after this: Postgres aborts the enclosing transaction on a constraint
    // violation, and RefreshDatabase wraps the whole test in one. The throw IS the assertion —
    // it is the database refusing what the service only asks politely about.
    expect(fn () => LeaveRequest::factory()
        ->forEmployee($this->yaseen->employee)
        ->ofType($this->annual)
        ->between(LEAVE_MONDAY, LEAVE_TUESDAY)
        ->create())->toThrow(QueryException::class, 'leave_requests_no_overlap');
});

/*
|--------------------------------------------------------------------------
| Balances
|--------------------------------------------------------------------------
*/

it('refuses a capped request that asks for more days than are left', function () {
    $this->leave->setBalance($this->admin, $this->yaseen->employee, $this->annual, 1, 'Opening balance.');

    expect(fn () => $this->leave->apply(
        $this->yaseen,
        $this->yaseen->employee,
        $this->annual,
        LEAVE_SUNDAY,
        LEAVE_TUESDAY,
        'Three working days against one.',
    ))->toThrow(LeaveStateException::class, 'Annual leave asks for 3 days and 1 day is left');
});

it('never refuses an uncapped type for want of a balance', function () {
    // The same window, the same employee, the same (absent) balance — and Unpaid and Other do
    // not refuse, because they have no number to run out of (Part D §9).
    $this->leave->setBalance($this->admin, $this->yaseen->employee, $this->annual, 0, 'Spent.');

    $unpaid = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->unpaid, LEAVE_SUNDAY, LEAVE_MONDAY, 'No days left, still needed.');
    expect($unpaid->days)->toBe(2);

    $this->leave->reject($this->admin, $unpaid, 'Making room for the next assertion.');

    $other = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->other, LEAVE_SUNDAY, LEAVE_MONDAY, 'Also uncapped.');
    expect($other->days)->toBe(2);
});

it('decrements the balance on approval and only on approval', function () {
    $this->leave->setBalance($this->admin, $this->yaseen->employee, $this->annual, 10, 'Opening balance.');

    $request = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->annual, LEAVE_SUNDAY, LEAVE_MONDAY, 'Two days.');

    expect($this->leave->balanceDays($this->yaseen->employee, $this->annual))->toBe(10);

    $this->leave->approve($this->admin, $request);

    expect($this->leave->balanceDays($this->yaseen->employee, $this->annual))->toBe(8);
});

it('spends nothing on an uncapped type', function () {
    $request = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->unpaid, LEAVE_SUNDAY, LEAVE_MONDAY, 'Two unpaid days.');
    $this->leave->approve($this->admin, $request);

    expect(LeaveBalance::where('employee_id', $this->yaseen->employee->id)
        ->where('leave_type_id', $this->unpaid->id)
        ->exists())->toBeFalse();
});

it('refuses to set a balance on a type that has none', function () {
    expect(fn () => $this->leave->setBalance($this->admin, $this->yaseen->employee, $this->unpaid, 5, 'Nope.'))
        ->toThrow(LeaveStateException::class, 'has no balance');
});

it('writes an audit row with old and new values when an Admin edits a balance', function () {
    $this->leave->setBalance($this->admin, $this->yaseen->employee, $this->annual, 12, 'Carried over from last year.');
    $this->leave->setBalance($this->admin, $this->yaseen->employee, $this->annual, 9, 'Correcting my own typo.');

    $rows = AuditLog::where('event', AuditEvent::LeaveBalanceAdjusted->value)
        ->orderBy('id')
        ->get();

    // The seeder writes an opening balance directly, so the FIRST adjustment here is a change
    // and carries an old value.
    expect($rows)->toHaveCount(2)
        ->and($rows[1]->old_value['balance_days'])->toBe(12)
        ->and($rows[1]->new_value['balance_days'])->toBe(9)
        ->and($rows[1]->new_value['reason'])->toBe('Correcting my own typo.')
        ->and($rows[1]->actor_id)->toBe($this->admin->id);
});

/*
|--------------------------------------------------------------------------
| Approval and its side effects
|--------------------------------------------------------------------------
*/

it('marks each working day of an approved window as Leave in attendance', function () {
    $request = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->annual, LEAVE_THURSDAY, LEAVE_SATURDAY.'', 'Thursday to Saturday.');

    $this->leave->approve($this->admin, $request);

    // Thursday is written; Friday and Saturday are not working days and get no row at all.
    expect(AttendanceRecord::where('employee_id', $this->yaseen->employee->id)->count())->toBe(1)
        ->and(AttendanceRecord::where('employee_id', $this->yaseen->employee->id)->first()->status)
        ->toBe(AttendanceStatus::Leave)
        ->and(AttendanceRecord::where('employee_id', $this->yaseen->employee->id)->first()->date->toDateString())
        ->toBe(LEAVE_THURSDAY);
});

it('writes no attendance row at all for a remote-timer employee', function () {
    // Decision 4-11: Tapu can never have an attendance row, and approving his leave must not
    // become the one hand that can write him one.
    $request = $this->leave->apply($this->tapu, $this->tapu->employee, $this->annual, LEAVE_SUNDAY, LEAVE_MONDAY, 'Two days off the timer.');

    $approved = $this->leave->approve($this->admin, $request);

    expect(AttendanceRecord::where('employee_id', $this->tapu->employee->id)->count())->toBe(0)
        ->and($approved->status)->toBe(LeaveStatus::Approved)
        ->and($approved->days)->toBe(2);
});

it('turns an Absent the sweep already wrote into Leave, and leaves a day somebody worked alone', function () {
    $employee = $this->yaseen->employee;

    AttendanceRecord::factory()->forEmployee($employee)->on(Carbon::parse(LEAVE_SUNDAY))->absent()->create();
    AttendanceRecord::factory()->forEmployee($employee)->on(Carbon::parse(LEAVE_MONDAY))->create();

    $request = $this->leave->apply($this->yaseen, $employee, $this->annual, LEAVE_SUNDAY, LEAVE_MONDAY, 'Retro-approved.');
    $this->leave->approve($this->admin, $request);

    $sunday = AttendanceRecord::where('employee_id', $employee->id)->whereDate('date', LEAVE_SUNDAY)->firstOrFail();
    $monday = AttendanceRecord::where('employee_id', $employee->id)->whereDate('date', LEAVE_MONDAY)->firstOrFail();

    expect($sunday->status)->toBe(AttendanceStatus::Leave)
        // Somebody clocked in on the Monday. Leave does not erase the evidence that they were
        // at work — the approval reports what it could not claim instead.
        ->and($monday->status)->toBe(AttendanceStatus::Present);
});

it('stores unpaid_days for an unpaid type and zero for every other', function () {
    $unpaid = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->unpaid, LEAVE_SUNDAY, LEAVE_MONDAY, 'Two unpaid days.');
    $this->leave->approve($this->admin, $unpaid);

    $paid = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->other, LEAVE_TUESDAY, LEAVE_TUESDAY, 'One day of Other.');
    $this->leave->approve($this->admin, $paid);

    expect($unpaid->fresh()->unpaid_days)->toBe(2)
        // Other is uncapped and still PAID. A rule that read "uncapped means unpaid" would have
        // docked a day here and nothing would have said so until a payslip did.
        ->and($paid->fresh()->unpaid_days)->toBe(0);
});

it('audits an approval and a rejection with old and new values', function () {
    $approved = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->annual, LEAVE_SUNDAY, LEAVE_MONDAY, 'Two days.');
    $this->leave->approve($this->admin, $approved, 'Fine by me.');

    $rejected = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->annual, LEAVE_TUESDAY, LEAVE_TUESDAY, 'One more day.');
    $this->leave->reject($this->admin, $rejected, 'Deadline that week.');

    $approval = AuditLog::where('event', AuditEvent::LeaveApproved->value)->firstOrFail();
    $refusal = AuditLog::where('event', AuditEvent::LeaveRejected->value)->firstOrFail();

    expect($approval->old_value['status'])->toBe('pending')
        ->and($approval->new_value['status'])->toBe('approved')
        ->and($approval->new_value['days'])->toBe(2)
        ->and($approval->actor_id)->toBe($this->admin->id)
        ->and($refusal->old_value['status'])->toBe('pending')
        ->and($refusal->new_value['status'])->toBe('rejected')
        ->and($refusal->new_value['decision_note'])->toBe('Deadline that week.');
});

/*
|--------------------------------------------------------------------------
| The status machine
|--------------------------------------------------------------------------
*/

it('refuses a status written outside the transition machine', function () {
    $request = LeaveRequest::factory()->forEmployee($this->yaseen->employee)->ofType($this->annual)->create();

    expect(function () use ($request) {
        $request->status = LeaveStatus::Approved;
        $request->save();
    })->toThrow(LeaveStateException::class, 'without going through the transition machine');

    expect($request->fresh()->status)->toBe(LeaveStatus::Pending);
});

it('refuses a move the machine does not have', function () {
    $request = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->annual, LEAVE_SUNDAY, LEAVE_MONDAY, 'Two days.');
    $this->leave->approve($this->admin, $request);

    expect(fn () => $this->leave->reject($this->admin, $request->fresh(), 'Changed my mind.'))
        ->toThrow(LeaveStateException::class, 'cannot go from Approved to Rejected');
});

it('refuses to let anybody rule on their own request', function () {
    $request = $this->leave->apply($this->admin, $this->admin->employee, $this->annual, LEAVE_SUNDAY, LEAVE_MONDAY, 'My own leave.');

    expect(fn () => $this->leave->approve($this->admin, $request))
        ->toThrow(LeaveStateException::class, 'cannot rule on your own');

    // The other Admin can, which is what makes the rule workable rather than a dead end.
    expect($this->leave->approve($this->otherAdmin, $request->fresh())->status)->toBe(LeaveStatus::Approved);
});

it('sends a request back for correction and takes it back through pending', function () {
    $request = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->annual, LEAVE_SUNDAY, LEAVE_MONDAY, 'Two days, vaguely.');

    $this->leave->requestCorrection($this->admin, $request, 'Which two days exactly?');
    expect($request->fresh()->status)->toBe(LeaveStatus::CorrectionRequested)
        ->and($request->fresh()->decision_note)->toBe('Which two days exactly?');

    // The same request, amended. Not a new row — and the previous decision is cleared as it
    // re-enters the queue.
    $resubmitted = $this->leave->resubmit(
        $this->yaseen,
        $request->fresh(),
        $this->annual,
        LEAVE_TUESDAY,
        LEAVE_THURSDAY,
        'The Tuesday and the Thursday, with the Wednesday between them.',
    );

    expect($resubmitted->id)->toBe($request->id)
        ->and($resubmitted->status)->toBe(LeaveStatus::Pending)
        ->and($resubmitted->days)->toBe(3)
        ->and($resubmitted->decided_at)->toBeNull()
        ->and($resubmitted->approver_id)->toBeNull()
        ->and($resubmitted->decision_note)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Events
|--------------------------------------------------------------------------
*/

it('fires one event per act, and the approval carries what it wrote', function () {
    Event::fake([LeaveRequested::class, LeaveApproved::class, LeaveRejected::class, LeaveCorrectionRequested::class]);

    $request = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->annual, LEAVE_SUNDAY, LEAVE_MONDAY, 'Two days.');
    Event::assertDispatched(LeaveRequested::class, fn (LeaveRequested $event): bool => ! $event->resubmitted);

    $this->leave->approve($this->admin, $request);
    Event::assertDispatched(
        LeaveApproved::class,
        fn (LeaveApproved $event): bool => $event->attendanceDaysWritten === 2,
    );

    $second = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->annual, LEAVE_TUESDAY, LEAVE_TUESDAY, 'One day.');
    $this->leave->requestCorrection($this->admin, $second, 'Which Tuesday?');
    Event::assertDispatched(LeaveCorrectionRequested::class);

    $this->leave->resubmit($this->yaseen, $second->fresh(), $this->annual, LEAVE_TUESDAY, LEAVE_TUESDAY, 'That Tuesday, the sixth.');
    Event::assertDispatched(LeaveRequested::class, fn (LeaveRequested $event): bool => $event->resubmitted);

    $this->leave->reject($this->admin, $second->fresh(), 'On reflection, no.');
    Event::assertDispatched(LeaveRejected::class);
});

/*
|--------------------------------------------------------------------------
| The absent sweep's seam
|--------------------------------------------------------------------------
*/

it('makes an approved leave day covered, and only an approved one', function () {
    $employee = $this->yaseen->employee;
    $day = Carbon::parse(LEAVE_SUNDAY);

    expect(LeaveRequest::coversDate($employee, $day))->toBeFalse();

    $request = $this->leave->apply($this->yaseen, $employee, $this->annual, LEAVE_SUNDAY, LEAVE_MONDAY, 'Two days.');

    // Pending holds a place in the calendar but does not excuse an absence.
    expect(LeaveRequest::coversDate($employee, $day))->toBeFalse();

    $this->leave->approve($this->admin, $request);

    expect(LeaveRequest::coversDate($employee, $day))->toBeTrue()
        ->and(LeaveRequest::coversDate($employee, Carbon::parse(LEAVE_TUESDAY)))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| hq:mark-absent now skips approved leave
|--------------------------------------------------------------------------
|
| The other half of decision 4-12's seam, walked end to end rather than
| faked: the command, its query and everything else in Phase 4 are exactly
| as they were, and the sweep simply stopped marking a day that leave covers.
|
*/

it('skips an approved leave day in the absent sweep, and still marks the day either side', function () {
    // The sweep refuses a date that has not happened yet, so the clock is moved past the leave
    // rather than the leave moved into the past — the dates in this file are chosen against a
    // named weekday and must stay where they are.
    Carbon::setTestNow('2026-10-07 23:55:00');

    $employee = $this->yaseen->employee;

    // Monday is covered by leave; Tuesday is not. Both are working days.
    $request = $this->leave->apply($this->yaseen, $employee, $this->annual, LEAVE_MONDAY, LEAVE_MONDAY, 'One day.');
    $this->leave->approve($this->admin, $request);

    // The approval has already written the Monday as Leave, so the sweep would skip it anyway
    // for having a record. Remove the row, so what is left is the SEAM and nothing else.
    AttendanceRecord::where('employee_id', $employee->id)->delete();

    $this->artisan('hq:mark-absent', ['--as-of' => LEAVE_MONDAY])->assertSuccessful();
    $this->artisan('hq:mark-absent', ['--as-of' => LEAVE_TUESDAY])->assertSuccessful();

    $rows = AttendanceRecord::where('employee_id', $employee->id)->get()->keyBy(
        fn (AttendanceRecord $row): string => $row->date->toDateString(),
    );

    expect($rows->has(LEAVE_MONDAY))->toBeFalse()
        ->and($rows->get(LEAVE_TUESDAY)?->status)->toBe(AttendanceStatus::Absent);

    Carbon::setTestNow();
});

it('does not skip a day covered only by a pending request', function () {
    Carbon::setTestNow('2026-10-07 23:55:00');

    $employee = $this->yaseen->employee;

    $this->leave->apply($this->yaseen, $employee, $this->annual, LEAVE_MONDAY, LEAVE_MONDAY, 'One day, unapproved.');

    $this->artisan('hq:mark-absent', ['--as-of' => LEAVE_MONDAY])->assertSuccessful();

    expect(AttendanceRecord::where('employee_id', $employee->id)->whereDate('date', LEAVE_MONDAY)->first()?->status)
        ->toBe(AttendanceStatus::Absent);

    Carbon::setTestNow();
});
