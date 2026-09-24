<?php

use App\Models\Employee;
use App\Models\User;
use App\Services\AttendanceService;
use App\Support\UserStatus;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The Team directory
|--------------------------------------------------------------------------
|
| Part D §2's row for this screen: "Team directory: name, role, availability
| today (present / remote-tracking / on leave), DM button — **no salary, no
| tracking data**". Those last six words are what the first test in this file
| is, and it asserts the EXACT key list rather than the absence of a few names
| somebody happened to think of — a spot check for `salary` passes on a payload
| carrying `base_pay`, and that is the failure mode the rule exists for.
|
| Availability is not defined here, or anywhere near here. It is
| `AttendanceService::dayFor()`, the one statement of what a day is (decisions
| 4-9 and 5-2), and the tests below assert that this screen AGREES with the
| attendance roster rather than that it computes the same thing twice.
|
*/

/** Every key `TeamMemberResource` sends, and the whole of it. */
const TEAM_MEMBER_KEYS = [
    'id',
    'name',
    'role',
    'role_label',
    'availability',
    'availability_label',
    'availability_tone',
    'holiday_name',
    'is_you',
    'dm_url',
];

/**
 * Field names that must never appear in this payload at any depth.
 *
 * The exact-key assertion is the real test; this is the second net, because a nested object
 * would satisfy the key list at the top level and still carry a salary underneath.
 */
const TEAM_FORBIDDEN_KEYS = [
    'salary',
    'base_salary',
    'base_pay',
    'allowance',
    'bonus',
    'deduction',
    'pay',
    'rate',
    'tracking_mode',
    'tracked_minutes',
    'worked_minutes',
    'clock_in',
    'clock_out',
    'clock_in_at',
    'employee_number',
    'phone',
    'email',
    'joining_date',
    'employment_type',
    'manager_id',
    'note',
    'edited_by',
];

/** @return array<string, mixed> the row for this person, by name */
function teamRow(array $members, string $name): array
{
    foreach ($members as $member) {
        if ($member['name'] === $name) {
            return $member;
        }
    }

    throw new RuntimeException("no directory row for {$name}");
}

beforeEach(function () {
    // Thursday 24 September 2026: a working day on the seeded Sunday-to-Thursday week, and not
    // a seeded holiday. Pinned, because "today's availability" is the whole subject and a suite
    // that ran on a Friday would assert something different.
    Carbon::setTestNow('2026-09-24 10:00:00');

    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| The payload
|--------------------------------------------------------------------------
*/

it('sends exactly ten keys per member and not one more', function () {
    $members = $this->actingAs($this->admin)->get('/team')->assertOk()->viewData('page')['props']['members'];

    expect($members)->not->toBeEmpty();

    foreach ($members as $member) {
        expect(array_keys($member))->toEqualCanonicalizing(TEAM_MEMBER_KEYS);
    }
})->group('phase6', 'team');

it('carries no salary and no tracking field anywhere in the payload', function () {
    $props = $this->actingAs($this->admin)->get('/team')->assertOk()->viewData('page')['props'];

    // The DIRECTORY's own props, at every depth. Not the shared Inertia props around them:
    // `auth.user` carries the reader's own email on every page in the application, and that is
    // a fact about the reader rather than something this screen published about a colleague.
    $keys = [];
    array_walk_recursive($props['members'], function (mixed $value, string|int $key) use (&$keys): void {
        $keys[] = (string) $key;
    });

    foreach (TEAM_FORBIDDEN_KEYS as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }

    // And no money-shaped value under a name nobody thought of: there is no numeric field in
    // this payload except the row id, so anything else numeric is a fact somebody added.
    foreach ($props['members'] as $member) {
        foreach ($member as $key => $value) {
            expect($key === 'id' || ! is_numeric($value))->toBeTrue("members.{$key} carries a number");
        }
    }
})->group('phase6', 'team');

it('shows the same availability word the attendance roster shows', function () {
    // The point of the screen, and the rule it must not restate: one definition, two readers.
    $roster = app(AttendanceService::class)->roster($this->admin, Carbon::today());
    $members = $this->actingAs($this->admin)->get('/team')->assertOk()->viewData('page')['props']['members'];

    foreach ($roster as $row) {
        expect(teamRow($members, $row['employee']['name'])['availability'])->toBe($row['status']);
    }
})->group('phase6', 'team');

it('reads Present for somebody who clocked in, without saying when', function () {
    app(AttendanceService::class)->clockIn($this->yaseen->employee, Carbon::parse('2026-09-24 08:50:00'));

    $row = teamRow(
        $this->actingAs($this->admin)->get('/team')->assertOk()->viewData('page')['props']['members'],
        $this->yaseen->name,
    );

    expect($row['availability'])->toBe('present')
        ->and($row['availability_label'])->toBe('Present')
        ->and($row['availability_tone'])->toBe('done')
        // The time is tracking data. It is on the Attendance roster, which is scoped; it is not
        // in a directory every colleague can open.
        ->and(json_encode($row, JSON_THROW_ON_ERROR))->not->toContain('08:50');
})->group('phase6', 'team');

it('reads Remote for the timer-tracked employee and carries none of his minutes', function () {
    $row = teamRow(
        $this->actingAs($this->yaseen)->get('/team')->assertOk()->viewData('page')['props']['members'],
        $this->tapu->name,
    );

    // "remote-tracking" is one of the three words the plan's own line names, so the STATUS is
    // allowed. The tracking data behind it is not, and none of it is here.
    expect($row['availability'])->toBe('remote')
        ->and($row)->not->toHaveKey('tracked_minutes')
        ->and($row)->not->toHaveKey('tracking_mode');
})->group('phase6', 'team');

it('says Not tracked for somebody no clock and no timer watches, rather than Off day', function () {
    // The Accountant has no schedule, so `dayFor()` would call every day of their life an Off
    // Day — a true statement about an empty schedule and a false one about the person. The
    // controller asks `Employee::tracked()` instead of restating a tracking-mode comparison.
    $row = teamRow(
        $this->actingAs($this->admin)->get('/team')->assertOk()->viewData('page')['props']['members'],
        $this->accountant->name,
    );

    expect($row['availability'])->toBeNull()
        ->and($row['availability_label'])->toBe('Not tracked')
        ->and($row['availability_tone'])->toBeNull();
})->group('phase6', 'team');

/*
|--------------------------------------------------------------------------
| The DM button
|--------------------------------------------------------------------------
*/

it('gives a DM link for a colleague who can be messaged and none for yourself', function () {
    $members = $this->actingAs($this->admin)->get('/team')->assertOk()->viewData('page')['props']['members'];

    expect(teamRow($members, $this->tapu->name)['dm_url'])
        ->toBe(route('messages.direct', $this->tapu->getKey()))
        ->and(teamRow($members, $this->admin->name)['dm_url'])->toBeNull()
        ->and(teamRow($members, $this->admin->name)['is_you'])->toBeTrue();
})->group('phase6', 'team');

it('draws no DM button for the Accountant, who has no messaging', function () {
    // They are IN the directory — they work here — and there is nowhere for a message to them
    // to go. The button is absent rather than drawn and refused (DESIGN.md §5.11), and the
    // reason is the key they do not hold, not their role name.
    $members = $this->actingAs($this->admin)->get('/team')->assertOk()->viewData('page')['props']['members'];

    expect(teamRow($members, $this->accountant->name)['dm_url'])->toBeNull()
        ->and(teamRow($members, $this->accountant->name)['name'])->toBe($this->accountant->name);
})->group('phase6', 'team');

it('posts the DM link straight into the conversation it opens', function () {
    // The link this page draws is the endpoint the other half of Phase 6 owns. Following it is
    // what proves the button is not decorative.
    $this->actingAs($this->admin)
        ->post(route('messages.direct', $this->tapu->getKey()))
        ->assertRedirect();
})->group('phase6', 'team');

/*
|--------------------------------------------------------------------------
| Who reaches it
|--------------------------------------------------------------------------
*/

it('refuses the Accountant with 403 and sends a guest to the login page', function () {
    $this->actingAs($this->accountant)->get('/team')->assertForbidden();

    $this->app['auth']->forgetGuards();
    $this->get('/team')->assertRedirect('/login');
})->group('phase6', 'team');

it('shows every active employee and nobody who has been deactivated', function () {
    $employee = Employee::query()->whereHas('user', fn ($query) => $query->where('email', $this->yaseen->email))->firstOrFail();
    $employee->forceFill(['status' => UserStatus::Inactive->value])->save();

    $members = $this->actingAs($this->admin)->get('/team')->assertOk()->viewData('page')['props']['members'];

    expect(array_column($members, 'name'))->not->toContain($this->yaseen->name)
        ->and(array_column($members, 'name'))->toContain($this->tapu->name);
})->group('phase6', 'team');

it('renders the page in the right shell for each surface', function () {
    // Decision 4-26: two pages shipped with no layout at all and every measurement on them
    // passed, because a page with no shell has nothing to overflow. This asserts the page, and
    // `defineOptions({ layout })` inside it picks the shell from the viewer's own surface.
    $this->actingAs($this->admin)->get('/team')->assertInertia(fn (Assert $page) => $page->component('Shared/Team'));
    $this->actingAs($this->tapu)->get('/team')->assertInertia(fn (Assert $page) => $page->component('Shared/Team'));
})->group('phase6', 'team');
