<?php

use App\Models\LeaveType;
use App\Models\Notification;
use App\Models\User;
use App\Services\LeaveService;
use App\Support\NotificationTab;
use App\Support\NotificationType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Leave notifications: through the Phase 2 engine, unchanged
|--------------------------------------------------------------------------
|
| Four types, two directions, and not one line of new machinery:
|
|   - `NotificationService` is still the only thing that writes a row;
|   - recipients pass the same two filters — the type's required permission
|     and `view` on the object;
|   - adding a type is a case in `NotificationType` plus a mapping in
|     `NotificationDispatcher`, and a migration rewriting the CHECK.
|
| That last one is decision 3-6's trap, and it is asserted here rather than
| assumed: the constraint is generated from the enum at create time, so a
| database built before this phase would refuse the first leave application
| while every test passed.
|
*/

const NOTIFY_SUNDAY = '2026-10-04';
const NOTIFY_MONDAY = '2026-10-05';

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->faruk = User::where('email', 'faruk@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->annual = LeaveType::where('name', 'Annual')->firstOrFail();
    $this->leave = app(LeaveService::class);
});

it('tells the approvers when somebody applies, and nobody else', function () {
    $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->annual, NOTIFY_SUNDAY, NOTIFY_MONDAY, 'Two days.');

    $rows = Notification::where('type', NotificationType::LeaveRequested->value)->get();

    // Both Admins hold `leave.approve`. Yaseen is the actor and is always dropped; the
    // Accountant may apply but not approve, so they are filtered out by the TYPE's permission
    // without being named anywhere.
    expect($rows->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->admin->id, $this->faruk->id])->sort()->values()->all())
        ->and($rows->first()->summary())->toContain('Yaseen asked for Annual leave')
        ->and($rows->first()->summary())->toContain('4–5 Oct');
});

it('tells the applicant when a decision is made, and tells nobody else', function () {
    $request = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->annual, NOTIFY_SUNDAY, NOTIFY_MONDAY, 'Two days.');

    $this->leave->approve($this->admin, $request);

    $rows = Notification::where('type', NotificationType::LeaveApproved->value)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->user_id)->toBe($this->yaseen->id)
        ->and($rows->first()->summary())->toContain('Your Annual leave is approved');
});

it('gives the Accountant a mailbox of their own leave, and no task rows in it', function () {
    // Phases 2–4 refused the Accountant `/notifications` outright, because every type in the
    // catalogue required a `tasks.*` key. `leave.approved` requires `leave.apply`, which Part
    // C §1 gives to every role — so the catalogue now contains a type they can receive, and
    // `NotificationPolicy::viewAny` stops refusing them. Nothing was carved out for them.
    $request = $this->leave->apply(
        $this->accountant,
        $this->accountant->employee,
        $this->annual,
        NOTIFY_SUNDAY,
        NOTIFY_MONDAY,
        'Two days away from the books.',
    );

    $this->leave->approve($this->admin, $request);

    $this->actingAs($this->accountant)
        ->getJson('/notifications/recent')
        ->assertOk();

    $rows = Notification::where('user_id', $this->accountant->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->type)->toBe(NotificationType::LeaveApproved)
        ->and($rows->first()->type->tab())->toBe(NotificationTab::Leave);
});

it('carries the refusal reason into the sentence', function () {
    $request = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->annual, NOTIFY_SUNDAY, NOTIFY_MONDAY, 'Two days.');

    $this->leave->reject($this->admin, $request, 'Client deadline that week.');

    $row = Notification::where('type', NotificationType::LeaveRejected->value)->firstOrFail();

    expect($row->summary())->toContain('Your Annual leave was turned down')
        ->and($row->summary())->toContain('Client deadline that week.');
});

it('closes the approver own row when they rule, and leaves the other approver alone', function () {
    $request = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->annual, NOTIFY_SUNDAY, NOTIFY_MONDAY, 'Two days.');

    $this->leave->approve($this->admin, $request);

    $shahadat = Notification::where('user_id', $this->admin->id)
        ->where('type', NotificationType::LeaveRequested->value)
        ->firstOrFail();
    $faruk = Notification::where('user_id', $this->faruk->id)
        ->where('type', NotificationType::LeaveRequested->value)
        ->firstOrFail();

    // Decision 2-53: only the ACTOR's own rows resolve. Faruk has not read his, and one Admin
    // ruling has told him nothing.
    expect($shahadat->resolved_at)->not->toBeNull()
        ->and($faruk->resolved_at)->toBeNull();
});

it('groups a resubmission into the approver existing row and says so', function () {
    $request = $this->leave->apply($this->yaseen, $this->yaseen->employee, $this->annual, NOTIFY_SUNDAY, NOTIFY_MONDAY, 'Two days.');
    $this->leave->requestCorrection($this->admin, $request, 'Which two days?');

    $this->leave->resubmit(
        $this->yaseen,
        $request->fresh(),
        $this->annual,
        NOTIFY_SUNDAY,
        NOTIFY_MONDAY,
        'The Sunday and the Monday.',
    );

    $row = Notification::where('user_id', $this->faruk->id)
        ->where('type', NotificationType::LeaveRequested->value)
        ->firstOrFail();

    // One row, count 2, and a plural sentence — without the plural branch the second filing
    // would have said exactly what the first said (the bug decision 2-46 records).
    expect(Notification::where('user_id', $this->faruk->id)->count())->toBe(1)
        ->and($row->count)->toBe(2)
        ->and($row->summary())->toContain('has updated a leave request');
});

it('has rewritten the notifications type CHECK from the enum (decision 3-6)', function () {
    // The trap: the constraint is generated at create time, so it has to be rewritten by a
    // migration whenever a phase adds a type. Written against the DATABASE rather than against
    // the enum, because the enum is the thing that is already right.
    foreach (
        [
            NotificationType::LeaveRequested,
            NotificationType::LeaveApproved,
            NotificationType::LeaveRejected,
            NotificationType::LeaveCorrectionRequested,
        ] as $type
    ) {
        $row = Notification::factory()->forUser($this->yaseen)->create(['type' => $type->value]);

        expect($row->fresh()->type)->toBe($type);
    }
});

it('still refuses a type the enum does not have', function () {
    // Straight to the table, past the model's enum cast — which is the only way to ask the
    // CHECK itself rather than the cast in front of it. The constraint is the backstop for a
    // writer that is not Eloquent; the cast is the one in front of the writers that are.
    expect(fn () => DB::table('notifications')->insert([
        'user_id' => $this->yaseen->id,
        'type' => 'leave.invented',
        'payload' => json_encode(['title' => 'x', 'context' => []]),
        'group_key' => 'leave.invented:App\\Models\\LeaveRequest:1',
        'count' => 1,
        'is_read' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'notifications_type_is_known');
});
