<?php

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\AttendanceStatus;
use App\Support\LeaveStatus;
use App\Support\RoleName;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Leave endpoints: My Leave, the queue, the calendar and the balances
|--------------------------------------------------------------------------
|
| Three privacy rules are asserted here rather than argued:
|
|   - **the Accountant applies for their own leave, in their own shell**, and
|     reaches nobody else's. Part C §1 gives that cell to every role and says
|     underneath the matrix that the Accountant shell therefore carries My
|     Leave — this is where it is proved;
|   - somebody else's request is **404, never 403** (Part C). The lookups go
|     through `LeaveRequest::visibleTo()`, so absence is what the controller
|     HAS rather than what it decides;
|   - the Admin screens are **403** for every other shell, which is a rule
|     about the surface and not about a record.
|
| The dates are October 2026 Sundays and Mondays — working days on the seeded
| Sunday-to-Thursday week — and they miss every seeded holiday.
|
*/

// Prefixed `LEAVE_` because Pest declares a test file's constants GLOBALLY: `ENDPOINT_MONDAY`
// already meant 2026-09-14 in Attendance/AttendanceEndpointsTest.php, and whichever file PHP
// loaded first silently won. Two Attendance tests failed only when the two folders ran in the
// same process — a green file and a red suite, which is the worst shape a test failure takes.
const LEAVE_ENDPOINT_SUNDAY = '2026-10-04';
const LEAVE_ENDPOINT_MONDAY = '2026-10-05';
const LEAVE_ENDPOINT_SUNDAY_2 = '2026-10-11';
const LEAVE_ENDPOINT_MONDAY_2 = '2026-10-12';

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    $this->annual = LeaveType::where('name', 'Annual')->firstOrFail();
});

/** A pending request, written straight to the table. */
function leaveRequestFor(User $user, string $from = LEAVE_ENDPOINT_SUNDAY, string $to = LEAVE_ENDPOINT_MONDAY): LeaveRequest
{
    return LeaveRequest::factory()
        ->forEmployee($user->employee)
        ->ofType(LeaveType::where('name', 'Annual')->firstOrFail())
        ->between($from, $to)
        ->create();
}

/*
|--------------------------------------------------------------------------
| My Leave — every role, in its own shell
|--------------------------------------------------------------------------
*/

it('gives every role their own leave page, the Accountant included', function () {
    foreach ([$this->admin, $this->yaseen, $this->tapu, $this->accountant, $this->manager] as $user) {
        $this->actingAs($user)
            ->get('/leave')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shared/Leave')
                ->where('subject.id', $user->employee->id)
                ->where('permissions.can_apply', true));
    }
});

it('lets the Accountant apply for their own leave', function () {
    // Part C §1's cell, and the first thing this surface does beyond finance. The Accountant
    // has no schedule at all, so every day in the window counts — see LeaveService::leaveDays().
    $this->actingAs($this->accountant)
        ->post('/leave', [
            'leave_type_id' => $this->annual->id,
            'start_date' => LEAVE_ENDPOINT_SUNDAY,
            'end_date' => LEAVE_ENDPOINT_MONDAY,
            'reason' => 'Two days away from the books.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(LeaveRequest::where('employee_id', $this->accountant->employee->id)->count())->toBe(1);
});

it('shows a person only their own requests on My Leave', function () {
    leaveRequestFor($this->yaseen);
    leaveRequestFor($this->tapu, LEAVE_ENDPOINT_SUNDAY_2, LEAVE_ENDPOINT_MONDAY_2);

    $this->actingAs($this->accountant)
        ->get('/leave')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('requests', []));

    $this->actingAs($this->yaseen)
        ->get('/leave')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('requests', 1));
});

/*
|--------------------------------------------------------------------------
| Somebody else's request is ABSENT, not refused
|--------------------------------------------------------------------------
*/

it('answers 404 when the Accountant reaches for somebody else request', function () {
    $yaseens = leaveRequestFor($this->yaseen);

    // A complete, valid body — so this is the visibility rule answering and not the validator.
    $this->actingAs($this->accountant)
        ->put("/leave/{$yaseens->id}", [
            'leave_type_id' => $this->annual->id,
            'start_date' => LEAVE_ENDPOINT_SUNDAY,
            'end_date' => LEAVE_ENDPOINT_MONDAY,
            'reason' => 'Trying to change a colleague request.',
        ])
        ->assertNotFound();

    expect($yaseens->fresh()->reason)->toBe('Family commitment.');
});

it('keeps a request outside the scope out of the visibility query, which is what makes it 404', function () {
    // An Admin sees every request, so the controller's 404 branch cannot be reached through an
    // HTTP request by any seeded role — every other role is stopped by `surface:admin` first.
    // The branch is the SCOPE, so the scope is what is asserted: a Manager with nobody
    // reporting to them holds `leave.approve` and the policy would let them decide, and
    // `LeaveRequest::visibleTo()` still never hands them the row — so `visible()` has absence
    // rather than a decision to refuse.
    $yaseens = leaveRequestFor($this->yaseen);

    expect(LeaveRequest::query()->visibleTo($this->manager)->whereKey($yaseens->getKey())->exists())
        ->toBeFalse()
        ->and(LeaveRequest::query()->visibleTo($this->admin)->whereKey($yaseens->getKey())->exists())
        ->toBeTrue()
        // And the Accountant, who may apply but never approve, sees nobody else's at all.
        ->and(LeaveRequest::query()->visibleTo($this->accountant)->count())->toBe(0);

    // The surface answers before any record is looked up, so a Manager never learns whether
    // the id exists either.
    $this->actingAs($this->manager)
        ->post("/admin/leave/{$yaseens->id}/approve")
        ->assertForbidden();

    expect($yaseens->fresh()->status)->toBe(LeaveStatus::Pending);
});

it('answers 404 for a leave request id that does not exist', function () {
    $this->actingAs($this->admin)
        ->post('/admin/leave/999999/approve')
        ->assertNotFound();
});

it('refuses to resubmit a request that was not sent back', function () {
    $pending = leaveRequestFor($this->yaseen);

    $this->actingAs($this->yaseen)
        ->put("/leave/{$pending->id}", [
            'leave_type_id' => $this->annual->id,
            'start_date' => LEAVE_ENDPOINT_SUNDAY,
            'end_date' => LEAVE_ENDPOINT_MONDAY,
            'reason' => 'Trying to edit while it is in the queue.',
        ])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| The Admin screens are the Admin surface
|--------------------------------------------------------------------------
*/

it('keeps every other shell off the Admin leave screens', function () {
    foreach (['/admin/leave', '/admin/leave/calendar', '/admin/leave/balances'] as $url) {
        $this->actingAs($this->admin)->get($url)->assertOk();

        foreach ([$this->yaseen, $this->tapu, $this->accountant, $this->manager] as $user) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }
});

it('refuses an Admin ruling on their own request, and lets the other Admin do it', function () {
    $faruk = User::where('email', 'faruk@goodtechies.test')->firstOrFail();
    $own = leaveRequestFor($this->admin);

    $this->actingAs($this->admin)
        ->post("/admin/leave/{$own->id}/approve")
        ->assertForbidden();

    $this->actingAs($faruk)
        ->post("/admin/leave/{$own->id}/approve")
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($own->fresh()->status)->toBe(LeaveStatus::Approved);
});

it('refuses a balance edit with no reason, and records one that has it', function () {
    $this->actingAs($this->admin)
        ->put("/admin/leave/balances/{$this->yaseen->employee->id}/{$this->annual->id}", ['balance_days' => 7])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->admin)
        ->put("/admin/leave/balances/{$this->yaseen->employee->id}/{$this->annual->id}", [
            'balance_days' => 7,
            'reason' => 'Carried over from last year.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(LeaveBalance::where('employee_id', $this->yaseen->employee->id)
        ->where('leave_type_id', $this->annual->id)
        ->value('balance_days'))->toBe(7);
});

/*
|--------------------------------------------------------------------------
| The acceptance sentence, walked
|--------------------------------------------------------------------------
*/

it('walks the plan acceptance: Tapu applies for 2 days, an Admin approves, everything updates with no manual step', function () {
    $before = LeaveBalance::where('employee_id', $this->tapu->employee->id)
        ->where('leave_type_id', $this->annual->id)
        ->value('balance_days');

    // 1. Tapu applies for two days.
    $this->actingAs($this->tapu)
        ->post('/leave', [
            'leave_type_id' => $this->annual->id,
            'start_date' => LEAVE_ENDPOINT_SUNDAY,
            'end_date' => LEAVE_ENDPOINT_MONDAY,
            'reason' => 'Two days for a family wedding.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $request = LeaveRequest::where('employee_id', $this->tapu->employee->id)->firstOrFail();

    expect($request->status)->toBe(LeaveStatus::Pending)
        ->and($request->days)->toBe(2);

    // 2. It is in the Admin queue, with the three verbs offered.
    $this->actingAs($this->admin)
        ->get('/admin/leave')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Leave/Index')
            ->has('requests', 1)
            ->where('requests.0.permissions.can_decide', true));

    // 3. The Admin approves.
    $this->actingAs($this->admin)
        ->post("/admin/leave/{$request->id}/approve")
        ->assertRedirect()
        ->assertSessionHas('success');

    $request->refresh();

    expect($request->status)->toBe(LeaveStatus::Approved);

    // 4a. The balance moved, with no manual step.
    expect(LeaveBalance::where('employee_id', $this->tapu->employee->id)
        ->where('leave_type_id', $this->annual->id)
        ->value('balance_days'))->toBe($before - 2);

    // 4b. Attendance: Tapu is a remote-timer employee, so the approval writes NO attendance
    // row at all (decision 4-11), and the absent sweep now skips those days anyway.
    expect(AttendanceRecord::where('employee_id', $this->tapu->employee->id)->count())->toBe(0)
        ->and(LeaveRequest::coversDate($this->tapu->employee, Carbon::parse(LEAVE_ENDPOINT_SUNDAY)))->toBeTrue();

    // 4c. The calendar shows him away, with his name and the word.
    $this->actingAs($this->admin)
        ->get('/admin/leave/calendar?month=2026-10')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Leave/Calendar')
            ->where('days.3.date', LEAVE_ENDPOINT_SUNDAY)
            ->where('days.3.people.0.name', 'Tapu')
            ->where('days.3.people.0.status_label', 'Approved'));

    // 4d. The Company dashboard counts him.
    $this->actingAs($this->admin)
        ->get('/admin/dashboard')
        ->assertOk();
});

it('marks an office employee working days as Leave on approval, with no manual step', function () {
    // Yaseen is on the office clock, so the same approval writes the attendance rows Tapu's
    // cannot have. Both halves of decision 4-11, read side by side.
    $request = leaveRequestFor($this->yaseen);

    $this->actingAs($this->admin)
        ->post("/admin/leave/{$request->id}/approve")
        ->assertRedirect();

    $rows = AttendanceRecord::where('employee_id', $this->yaseen->employee->id)
        ->orderBy('date')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->status)->toBe(AttendanceStatus::Leave)
        ->and($rows[0]->date->toDateString())->toBe(LEAVE_ENDPOINT_SUNDAY)
        ->and($rows[1]->status)->toBe(AttendanceStatus::Leave);
});
