<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\Permission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Admin → Audit Log viewer (Phase 12)
|--------------------------------------------------------------------------
|
| Part E, Phase 12: "Admin → Audit Log viewer (filters, old/new diff,
| read-only)", and acceptance criterion 11: "the audit log captures every event
| in Part C §4 and is not editable through the application by any role,
| including ADMIN".
|
| This file is the screen and who reaches it. The old/new diff has its own
| file, tests/Feature/Admin/AuditLogDiffTest.php.
|
| **The seeders write no audit rows, on purpose.** `FinanceSeeder`,
| `PayrollSeeder` and `DatabaseSeeder` each say so in their docblocks: an audit
| log whose first rows were written by the installer is an audit log nobody
| reads, and a dozen existing test files call `$this->seed()` and then assert
| `AuditLog::count()` is 0 so that the row they are about is the only row there
| is. So every row this file needs, it writes itself — which is also the honest
| shape of the test, because the rows the screen has to render in production are
| the ones eleven phases' services wrote, not ones a seeder invented.
|
| **Read-only is not asserted by hoping.** `audit_logs` is append-only at the
| database (Part B §3 rule 3), which
| tests/Feature/Database/AuditLogAppendOnlyTest.php already proves by running
| raw UPDATE, DELETE and TRUNCATE as `hq_app` inside a savepoint and expecting
| `42501 insufficient_privilege` from each. What is asserted here is the other
| half: that the application offers no route through which anybody would try.
|
| Every constant and helper is prefixed AUDIT_VIEW_ / auditView*, because Pest
| declares both globally across the whole suite (AGENTS.md).
|
*/

/** Where the viewer lives. One path, one verb. */
const AUDIT_VIEW_PATH = '/admin/audit-log';

/** The keys every row of the viewer carries. */
const AUDIT_VIEW_ROW_KEYS = [
    'id',
    'event',
    'event_label',
    'event_group',
    'event_known',
    'actor',
    'target',
    'old_value',
    'new_value',
    'diff',
    'created_at',
    'recorded_at',
    'ip',
    'user_agent',
];

beforeEach(function (): void {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
});

/**
 * One audit row, written the way the table receives them: an INSERT and nothing else.
 *
 * `AuditLogger` is the only writer in the application and is tested where it is used; what this
 * needs is control over the event string (including one no `AuditEvent` case matches), the actor
 * (including none) and the timestamp, which is what the date filter cuts on.
 *
 * @param  array<string, mixed>|null  $old
 * @param  array<string, mixed>|null  $new
 */
function auditViewRow(
    AuditEvent|string $event,
    ?array $old = null,
    ?array $new = null,
    ?User $actor = null,
    ?string $targetType = 'App\Models\Employee',
    ?int $targetId = 1,
    ?string $at = null,
): AuditLog {
    return AuditLog::create([
        'actor_id' => $actor?->getKey(),
        'event' => $event instanceof AuditEvent ? $event->value : $event,
        'target_type' => $targetType,
        'target_id' => $targetId,
        'old_value' => $old,
        'new_value' => $new,
        'ip' => '198.51.100.9',
        'user_agent' => 'PestAgent',
        'created_at' => $at === null
            ? now()
            : Carbon::createFromFormat('Y-m-d H:i:s', $at, (string) config('app.timezone')),
    ]);
}

/** The viewer's props for a query, as the browser would receive them. */
function auditViewProps(string $query = ''): array
{
    return test()->actingAs(test()->admin)
        ->get(AUDIT_VIEW_PATH.$query)
        ->viewData('page')['props'];
}

/*
|--------------------------------------------------------------------------
| Who may read it
|--------------------------------------------------------------------------
*/

it('opens for an Admin and carries every entry key', function (): void {
    $actor = $this->admin;

    auditViewRow(AuditEvent::SalaryChanged, ['base_salary' => '25000.00'], ['base_salary' => '30000.00'], $actor);

    $this->actingAs($actor)
        ->get(AUDIT_VIEW_PATH)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/AuditLog/Index')
            ->has('entries.data', 1)
            ->has('entries.data.0', fn (Assert $entry) => $entry->hasAll(AUDIT_VIEW_ROW_KEYS))
            ->has('filters')
            ->has('options.actors')
            ->has('options.events')
            ->has('options.target_types')
            ->where('timezone', (string) config('app.timezone'))
            ->where('entry', null)
        );
});

/**
 * The row Part C §1 is most easily misread on.
 *
 * An ACCOUNTANT holds `payroll.view_others` and may read every payslip in the agency — so they
 * can already see what somebody earns. They are still refused here, because the audit log is a
 * different question from the data it describes: *who changed this, and when* is not *what does
 * it say*. Both halves are asserted, so a future change that quietly widened `audit.view` to the
 * finance shell would fail on the second line and not on a comment.
 */
it('refuses the Accountant, who may read every payslip but not the log', function (): void {
    expect($this->accountant->hasPermission(Permission::PayrollViewOthers))->toBeTrue()
        ->and($this->accountant->hasPermission(Permission::AuditView))->toBeFalse();

    $this->actingAs($this->accountant)->get(AUDIT_VIEW_PATH)->assertForbidden();
});

it('refuses every other role', function (string $email): void {
    $user = User::where('email', $email)->firstOrFail();

    expect($user->hasPermission(Permission::AuditView))->toBeFalse();

    $this->actingAs($user)->get(AUDIT_VIEW_PATH)->assertForbidden();
})->with([
    'employee' => 'yaseen@goodtechies.test',
    'remote employee' => 'tapu@goodtechies.test',
]);

it('sends a guest to the login page', function (): void {
    $this->get(AUDIT_VIEW_PATH)->assertRedirect('/login');
});

/*
|--------------------------------------------------------------------------
| Read-only
|--------------------------------------------------------------------------
*/

/**
 * There is no write route, so there is none to forget.
 *
 * Asserted against the registered route table rather than by trying a POST, because a 405 would
 * also be produced by a route that exists and is merely mis-verbed. The whole surface of this
 * feature is one GET.
 */
it('registers exactly one route for the audit log, and it is a GET', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains($route->uri(), 'audit'))
        ->map(fn ($route): array => [
            'uri' => $route->uri(),
            'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
        ])
        ->values();

    expect($routes->all())->toBe([['uri' => 'admin/audit-log', 'methods' => ['GET']]]);
});

it('offers no write verb on the audit log path', function (string $method): void {
    auditViewRow(AuditEvent::UserLogin, null, ['guard' => 'web'], $this->admin);

    $response = $this->actingAs($this->admin)->call($method, AUDIT_VIEW_PATH);

    // 405: the router has no such route on this path. Nothing ran, and nothing could have.
    expect($response->status())->toBe(405)
        ->and(AuditLog::count())->toBe(1);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

/**
 * The seeded log is empty, by the seeders' own decision, so the empty state is the state a fresh
 * install opens in — worth asserting rather than assuming.
 */
it('shows an empty log as empty, because no seeder writes to this table', function (): void {
    expect(AuditLog::count())->toBe(0);

    $this->actingAs($this->admin)
        ->get(AUDIT_VIEW_PATH)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 0)
            ->where('entries.meta.total', 0)
            ->where('options.events', [])
            ->where('options.target_types', [])
        );
});

/*
|--------------------------------------------------------------------------
| Newest first, and paginated
|--------------------------------------------------------------------------
*/

it('pages the log 25 at a time, newest first', function (): void {
    foreach (range(1, 30) as $index) {
        auditViewRow(AuditEvent::TaskStatusChanged, ['status' => 'todo'], ['status' => 'in_progress'], $this->admin, targetId: $index);
    }

    $first = auditViewProps();

    expect($first['entries']['data'])->toHaveCount(25)
        ->and($first['entries']['meta']['total'])->toBe(30)
        // Newest first: the last row written is the first row read.
        ->and($first['entries']['data'][0]['target']['id'])->toBe(30)
        ->and($first['entries']['data'][24]['target']['id'])->toBe(6);

    $second = auditViewProps('?page=2');

    expect($second['entries']['data'])->toHaveCount(5)
        ->and($second['entries']['data'][0]['target']['id'])->toBe(5)
        ->and($second['entries']['data'][4]['target']['id'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

it('narrows by actor, and by the rows nobody signed in for', function (): void {
    auditViewRow(AuditEvent::RoleChanged, ['role' => 'employee'], ['role' => 'admin'], $this->admin);
    auditViewRow(AuditEvent::UserLogin, null, ['guard' => 'web'], $this->tapu);
    // A console command or a queue worker: no actor at all. "Which of these did nobody do" is a
    // real question and cannot be asked by leaving the filter off, because off means everybody.
    auditViewRow(AuditEvent::AttendanceEdited, ['status' => 'absent'], ['status' => 'present'], null);

    $mine = auditViewProps('?actor='.$this->admin->id);

    expect($mine['entries']['data'])->toHaveCount(1)
        ->and($mine['entries']['data'][0]['actor']['id'])->toBe($this->admin->id)
        ->and($mine['filters']['actor'])->toBe((string) $this->admin->id);

    $system = auditViewProps('?actor=system');

    expect($system['entries']['data'])->toHaveCount(1)
        ->and($system['entries']['data'][0]['actor'])->toBeNull()
        ->and($system['entries']['data'][0]['event'])->toBe(AuditEvent::AttendanceEdited->value);
});

it('narrows by event and by kind of record', function (): void {
    auditViewRow(AuditEvent::SalaryChanged, ['base_salary' => '1'], ['base_salary' => '2'], $this->admin, 'App\Models\EmployeeSalary', 7);
    auditViewRow(AuditEvent::ProjectPriceChanged, ['price' => '100'], ['price' => '200'], $this->admin, 'App\Models\Project', 3);
    auditViewRow(AuditEvent::ProjectCreated, null, ['name' => 'New'], $this->admin, 'App\Models\Project', 4);

    $byEvent = auditViewProps('?event='.AuditEvent::SalaryChanged->value);

    expect($byEvent['entries']['data'])->toHaveCount(1)
        ->and($byEvent['entries']['data'][0]['event'])->toBe(AuditEvent::SalaryChanged->value)
        ->and($byEvent['filters']['event'])->toBe(AuditEvent::SalaryChanged->value);

    $byType = auditViewProps('?target_type='.urlencode('App\Models\Project'));

    expect($byType['entries']['data'])->toHaveCount(2)
        ->and($byType['entries']['data'][0]['target']['label'])->toBe('Project')
        ->and($byType['filters']['target_type'])->toBe('App\Models\Project');
});

it('narrows by a date range, cut on the agency timezone', function (): void {
    auditViewRow(AuditEvent::LeaveApproved, null, ['days' => 2], $this->admin, at: '2026-09-20 10:00:00');
    // 00:30 local. In UTC this instant belongs to the previous day, which is exactly the row a
    // `whereDate()` comparison would have put in the wrong bucket.
    auditViewRow(AuditEvent::LeaveRejected, null, ['days' => 1], $this->admin, at: '2026-09-22 00:30:00');
    auditViewRow(AuditEvent::LeaveBalanceAdjusted, ['balance' => 5], ['balance' => 7], $this->admin, at: '2026-09-25 18:00:00');

    $window = auditViewProps('?date_from=2026-09-21&date_to=2026-09-22');

    expect($window['entries']['data'])->toHaveCount(1)
        ->and($window['entries']['data'][0]['event'])->toBe(AuditEvent::LeaveRejected->value)
        ->and($window['filters']['date_from'])->toBe('2026-09-21')
        ->and($window['filters']['date_to'])->toBe('2026-09-22');

    // The last day of a range includes the whole of it, not midnight.
    $openEnded = auditViewProps('?date_from=2026-09-25');

    expect($openEnded['entries']['data'])->toHaveCount(1)
        ->and($openEnded['entries']['data'][0]['event'])->toBe(AuditEvent::LeaveBalanceAdjusted->value);
});

it('refuses a range that ends before it starts', function (): void {
    $this->actingAs($this->admin)
        ->get(AUDIT_VIEW_PATH.'?date_from=2026-09-20&date_to=2026-09-19')
        ->assertSessionHasErrors('date_to');
});

it('refuses an actor that is neither an id nor the system sentinel', function (): void {
    $this->actingAs($this->admin)
        ->get(AUDIT_VIEW_PATH.'?actor=or-1-1')
        ->assertSessionHasErrors('actor');
});

/**
 * A filtered view is a URL, so the same URL twice is the same view — which is all "survives a
 * reload" means for a screen that keeps nothing in `localStorage`. Every filter comes back in
 * `filters`, which is also how the page knows which chips to draw.
 */
it('echoes every filter back, so a filtered view survives a reload', function (): void {
    auditViewRow(AuditEvent::ExpenseEdited, ['amount' => '10'], ['amount' => '20'], $this->admin, 'App\Models\Expense', 2, '2026-09-24 09:00:00');

    $query = '?actor='.$this->admin->id
        .'&event='.AuditEvent::ExpenseEdited->value
        .'&target_type='.urlencode('App\Models\Expense')
        .'&date_from=2026-09-24&date_to=2026-09-24';

    $expected = [
        'actor' => (string) $this->admin->id,
        'event' => AuditEvent::ExpenseEdited->value,
        'target_type' => 'App\Models\Expense',
        'date_from' => '2026-09-24',
        'date_to' => '2026-09-24',
    ];

    $first = auditViewProps($query);
    $again = auditViewProps($query);

    expect($first['filters'])->toBe($expected)
        ->and($again['filters'])->toBe($expected)
        ->and($first['entries']['data'])->toHaveCount(1)
        ->and($again['entries']['data'])->toHaveCount(1);
});

/**
 * `FilterBar` writes an empty parameter on its way to removing a chip, so `?event=` arrives on a
 * perfectly ordinary Clear and must mean *not narrowed* rather than *narrowed to nothing*.
 */
it('treats a blank filter as not narrowed', function (): void {
    auditViewRow(AuditEvent::UserLogin, null, ['guard' => 'web'], $this->admin);

    $props = auditViewProps('?event=&actor=&target_type=&date_from=&date_to=');

    expect($props['entries']['data'])->toHaveCount(1)
        ->and($props['filters'])->toBe([
            'actor' => null,
            'event' => null,
            'target_type' => null,
            'date_from' => null,
            'date_to' => null,
        ]);
});

/*
|--------------------------------------------------------------------------
| An event this build does not know
|--------------------------------------------------------------------------
*/

/**
 * `audit_logs.event` is a plain indexed string with **no CHECK constraint** — verified against
 * the Phase 0 migration. So a row written by an earlier build can carry an event this one has no
 * `AuditEvent` case for, and the viewer's job is to render it, not to crash on it or hide it.
 *
 * It is also filterable, which is the part a `Rule::enum` on the Form Request would have broken:
 * the reader would have been told their question was invalid while the row sat in the table.
 */
it('renders and filters an event string AuditEvent no longer has', function (): void {
    expect(AuditEvent::tryFrom('legacy.thing_happened'))->toBeNull();

    auditViewRow('legacy.thing_happened', ['note' => 'before'], ['note' => 'after'], $this->admin);
    auditViewRow(AuditEvent::UserLogin, null, ['guard' => 'web'], $this->admin);

    $all = auditViewProps();
    $legacy = collect($all['entries']['data'])->firstWhere('event', 'legacy.thing_happened');

    expect($legacy)->not->toBeNull()
        ->and($legacy['event_known'])->toBeFalse()
        // Humanised rather than blank, and grouped as what it is.
        ->and($legacy['event_label'])->toBe('Legacy thing happened')
        ->and($legacy['event_group'])->toBe(AuditEvent::GROUP_UNRECOGNISED)
        // The diff still works: it is an ordinary pair of maps.
        ->and($legacy['diff']['kind'])->toBe('updated')
        ->and($legacy['diff']['changed_count'])->toBe(1);

    $filtered = auditViewProps('?event=legacy.thing_happened');

    expect($filtered['entries']['data'])->toHaveCount(1)
        ->and($filtered['entries']['data'][0]['event'])->toBe('legacy.thing_happened');

    // And it is offered in the picker, because the options come from the table and not the enum.
    expect(collect($all['options']['events'])->pluck('value')->all())
        ->toContain('legacy.thing_happened');
});

/*
|--------------------------------------------------------------------------
| The filter options
|--------------------------------------------------------------------------
*/

it('offers only events that have been recorded, in family order', function (): void {
    auditViewRow(AuditEvent::SalaryChanged, ['base_salary' => '1'], ['base_salary' => '2'], $this->admin);
    auditViewRow(AuditEvent::UserLogin, null, ['guard' => 'web'], $this->admin);
    auditViewRow(AuditEvent::RoleChanged, ['role' => 'employee'], ['role' => 'admin'], $this->admin);

    $options = auditViewProps()['options']['events'];

    // Access, then roles & permissions, then money — `AuditEvent::groups()`' order, not the
    // alphabet's. Nothing that has never happened is offered.
    expect(collect($options)->pluck('value')->all())->toBe([
        AuditEvent::UserLogin->value,
        AuditEvent::RoleChanged->value,
        AuditEvent::SalaryChanged->value,
    ])->and(collect($options)->pluck('group')->all())->toBe([
        AuditEvent::GROUP_ACCESS,
        AuditEvent::GROUP_PERMISSIONS,
        AuditEvent::GROUP_MONEY,
    ])->and($options[0]['label'])->toBe('Signed in');
});

/**
 * Actors come from `users`, not from a second scan of the log, so everybody who could act is
 * offered — and somebody who has left keeps their rows for ever (Part B §3 rule 11), so they
 * stay on the list and are marked with the WORD rather than dropped.
 */
it('offers every account as an actor, plus the system', function (): void {
    $options = auditViewProps()['options']['actors'];

    expect($options[0])->toBe(['value' => 'system', 'label' => 'System (no signed-in actor)'])
        ->and(collect($options)->pluck('value')->all())->toContain((string) $this->accountant->id)
        ->and(collect($options)->pluck('label')->all())->toContain($this->admin->name);
});

/*
|--------------------------------------------------------------------------
| The deep link
|--------------------------------------------------------------------------
*/

/**
 * `DetailDrawer` writes `?detail=<id>` with `replaceState` and cannot fetch what it names, so a
 * link to an entry that is not on the page the link also asked for would open an empty drawer.
 * The controller resolves it — with no scope, because every row is in scope for anybody holding
 * `audit.view`, and therefore with no 404 to give either.
 */
it('resolves a pasted entry link even when the entry is not on the page', function (): void {
    $oldest = auditViewRow(AuditEvent::ProjectCreated, null, ['name' => 'First'], $this->admin);

    foreach (range(1, 30) as $index) {
        auditViewRow(AuditEvent::TaskAssigned, null, ['assignee_id' => $index], $this->admin);
    }

    $props = auditViewProps('?detail='.$oldest->id);

    expect(collect($props['entries']['data'])->pluck('id')->all())->not->toContain($oldest->id)
        ->and($props['entry'])->not->toBeNull()
        ->and($props['entry']['id'])->toBe($oldest->id)
        ->and($props['entry']['diff']['kind'])->toBe('created');

    // An id that is not a row is simply nothing — not a 404, which would be describing a scope
    // this screen does not have.
    expect(auditViewProps('?detail=999999')['entry'])->toBeNull();
});

it('does not repeat an entry that is already on the page', function (): void {
    $entry = auditViewRow(AuditEvent::PayrollApproved, ['status' => 'draft'], ['status' => 'approved'], $this->admin);

    $props = auditViewProps('?detail='.$entry->id);

    expect(collect($props['entries']['data'])->pluck('id')->all())->toContain($entry->id)
        ->and($props['entry'])->toBeNull();
});
