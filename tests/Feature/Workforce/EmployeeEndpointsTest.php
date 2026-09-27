<?php

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\RoleName;
use App\Support\TrackingMode;
use App\Support\UserStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Admin → Workforce → Employees / Users & Roles (Phase 12)
|--------------------------------------------------------------------------
|
| Part D §2: "Users & Roles is the same screen family (Employees list →
| employee detail → role/schedule/tracking_mode)". One family, so one set of
| endpoints, and this file is the whole of their behaviour:
|
| **Inactive people are on the list.** Part B §3 rule 11 — a departure is
| `status = inactive`, never a missing row — so the list is asserted to still
| carry somebody after they have been deactivated, and the absence of any
| DELETE route is asserted directly against the route table.
|
| **Hiring works end to end, including the first sign-in.** There is no email
| in the MVP (Part H §1) and no password-reset route, so `store()` generates
| the password and says it once — on its own prop, on the redirect target, and
| nowhere else. The test reads it off that prop and signs the new colleague in
| with it, because a hire whose account cannot be used is not a hire.
|
| **Role and tracking mode are writable, and they are two endpoints.** Part D §2
| names the family "Employees list → employee detail → role/schedule/
| tracking_mode"; the working week is the Work Schedule editor's, and the other
| two live here. Their own behaviour — MANAGER refused, the self-guard, the
| audit rows, what a switch does NOT migrate — is
| tests/Feature/Workforce/EmployeeRoleTrackingTest.php.
|
| **Deactivation surfaces the open tasks and never reassigns them** (spec §47
| "open tasks flagged for reassignment"; Part D §21 "no auto-reassign"). The
| count is on the row BEFORE the act, the audit row carries the ids, and the
| act is not blocked by them.
|
| **The self-guard, for all three self-actions.** Part C §1: "ADMIN ✅ (not own
| account)".
|
| Every constant and helper here is prefixed WORKFORCE_EMPLOYEE_ /
| workforceEmployee*, because Pest declares both globally across the whole
| suite (AGENTS.md) — `insertSession` already exists in
| tests/Feature/Services/EmployeeAdministrationServiceTest.php.
|
*/

/** The keys every row of the Employees list carries, whoever is reading it. */
const WORKFORCE_EMPLOYEE_ROW_KEYS = [
    'id',
    'name',
    'employee_number',
    'role',
    'tracking_mode',
    'status',
    'status_label',
    'schedule',
    'schedule_summary',
    'projects_count',
    'project_permissions_count',
    'impact',
    'url',
    'is_you',
    'permissions',
    // The record half, which an Admin passes EmployeePolicy::view for.
    'email',
    'phone',
    'employment_type',
    'employment_type_label',
    'joining_date',
    'manager',
    'last_login_at',
];

beforeEach(function (): void {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->faruk = User::where('email', 'faruk@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;
});

function workforceInsertSession(string $id, ?int $userId): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '198.51.100.7',
        'user_agent' => 'PestAgent',
        'payload' => base64_encode('a:0:{}'),
        'last_activity' => now()->timestamp,
    ]);
}

/**
 * A valid create payload, in the shape `Components/Employees/EmployeeCreateDialog.vue` posts.
 *
 * @return array<string, mixed>
 */
function workforceEmployeePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Nadia Rahman',
        'email' => 'nadia@goodtechies.test',
        'employee_number' => null,
        'phone' => '+8801700000000',
        'role' => RoleName::EMPLOYEE->value,
        'employment_type' => 'full_time',
        'joining_date' => '2026-10-01',
        'manager_id' => null,
        'tracking_mode' => TrackingMode::OfficeAttendance->value,
        'working_days' => ['sun', 'mon', 'tue', 'wed', 'thu'],
        'working_hours_per_day' => '8',
        'start_time' => '09:00',
        'office_or_remote' => 'office',
    ], $overrides);
}

/**
 * The password `store()` said once, read off the ONE response that carries it.
 *
 * It travels as its own prop on the redirect target and not in `success` — see
 * `EmployeeFirstSignInPanel.vue` for why a credential does not share a channel with *"Project
 * archived"*. The assertions here are the contract: a `firstSignIn` object, the name and the
 * email beside the password so the Admin can hand over both, and sixteen letters and digits
 * (`Str::password(16, true, true, false)` — no symbols, because it is read out loud).
 *
 * @param  array<string, mixed>  $props
 */
function workforceEmployeePasswordFromProps(array $props): string
{
    expect($props)->toHaveKey('firstSignIn');

    $credential = $props['firstSignIn'];

    expect($credential)->toBeArray()
        ->toHaveKeys(['name', 'email', 'password'])
        ->and($credential['password'])->toMatch('/^[A-Za-z0-9]{16}$/');

    return $credential['password'];
}

/** The Show page's props for one employee, as the browser would receive them. */
function workforceEmployeeShowProps(int $employeeId): array
{
    return test()->actingAs(test()->admin)
        ->get('/admin/employees/'.$employeeId)
        ->viewData('page')['props'];
}

/*
|--------------------------------------------------------------------------
| The list
|--------------------------------------------------------------------------
*/

it('lists every employee with role, tracking mode, schedule summary and status', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/employees')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Employees/Index')
            ->has('employees', 6)
            ->has('options.roles')
            ->has('options.tracking_modes')
            ->has('options.employment_types')
            ->has('options.weekdays', 7)
            ->where('filters.status', 'all')
            ->where('filters.search', null));
});

it('sends an Admin exactly the row keys and no others', function (): void {
    $rows = $this->actingAs($this->admin)->get('/admin/employees')->viewData('page')['props']['employees'];

    foreach ($rows as $row) {
        expect(array_keys($row))->toEqualCanonicalizing(WORKFORCE_EMPLOYEE_ROW_KEYS);
    }
});

it('sends the seeded schedule summary and status the list prints', function (): void {
    $rows = collect($this->actingAs($this->admin)->get('/admin/employees')->viewData('page')['props']['employees']);

    $tapu = $rows->firstWhere('id', $this->tapu->employee->id);

    expect($tapu['role'])->toBe('REMOTE_EMPLOYEE')
        ->and($tapu['tracking_mode'])->toBe('remote_timer')
        ->and($tapu['status'])->toBe('active')
        ->and($tapu['status_label'])->toBe('Active')
        // TeamSeeder gives Tapu a five-hour remote week with no start time, which is exactly the
        // sentence the record card prints — and the "no start time" half is load-bearing: an
        // employee without one can never be Late.
        ->and($tapu['schedule_summary'])->toBe('Sun, Mon, Tue, Wed, Thu · 5 h/day · no start time')
        ->and($tapu['schedule']['office_or_remote'])->toBe('remote')
        ->and($tapu['schedule']['start_time'])->toBeNull()
        ->and($tapu['impact']['open_tasks_url'])
        ->toBe('/admin/tasks?assignee_id='.$this->tapu->employee->id.'&bucket=open');

    // The Accountant has `tracking_mode: none` and no schedule in the seed. Null is a real answer
    // and the screen says so, rather than inventing a working week for somebody who has none.
    $accountant = $rows->firstWhere('id', $this->accountant->employee->id);

    expect($accountant['tracking_mode'])->toBe('none')
        ->and($accountant['schedule'])->toBeNull()
        ->and($accountant['schedule_summary'])->toBeNull();
});

it('keeps an inactive employee on the list, marked', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/employees/'.$this->tapu->employee->id.'/deactivate')
        ->assertRedirect();

    $rows = collect($this->actingAs($this->admin)->get('/admin/employees')->viewData('page')['props']['employees']);

    expect($rows)->toHaveCount(6)
        ->and($rows->firstWhere('id', $this->tapu->employee->id)['status'])->toBe('inactive')
        ->and($rows->firstWhere('id', $this->tapu->employee->id)['status_label'])->toBe('Inactive');

    // And `?status=` is a view choice on top of that, never the default.
    $active = collect($this->actingAs($this->admin)
        ->get('/admin/employees?status=active')
        ->viewData('page')['props']['employees']);

    expect($active->pluck('id'))->not->toContain($this->tapu->employee->id);
});

it('narrows the list by search across name, email and employee number', function (): void {
    foreach (['Tapu', 'tapu@goodtechies.test', 'GT-003'] as $term) {
        $rows = collect($this->actingAs($this->admin)
            ->get('/admin/employees?search='.urlencode($term))
            ->viewData('page')['props']['employees']);

        expect($rows)->toHaveCount(1)
            ->and($rows->first()['id'])->toBe($this->tapu->employee->id);
    }
});

/*
|--------------------------------------------------------------------------
| The detail
|--------------------------------------------------------------------------
*/

it('shows one employee with their record, memberships and grant lists', function (): void {
    $employee = $this->tapu->employee;

    $this->actingAs($this->admin)
        ->get('/admin/employees/'.$employee->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Employees/Show')
            ->where('employee.id', $employee->id)
            ->where('employee.name', 'Tapu')
            ->where('employee.email', 'tapu@goodtechies.test')
            ->where('employee.is_you', false)
            ->where('employee.deactivated_at', null)
            ->where('employee.permissions.can_deactivate', true)
            ->where('employee.permissions.can_change_role', true)
            ->where('employee.permissions.can_change_tracking_mode', true)
            ->where('employee.permissions.can_manage_permissions', true)
            ->has('employee.projects')
            ->has('employee.project_permissions')
            ->has('weekdays', 7)
            ->has('grantableProjects')
            ->has('grantablePermissions', 1)
            // Four assignable roles, never five: MANAGER is assigned to nobody (Part C §1).
            ->has('roleOptions', 4)
            ->has('trackingModeOptions', 3));
});

it('names the viewer their own record and draws no self controls on it', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/employees/'.$this->admin->employee->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.is_you', true)
            ->where('employee.permissions.can_deactivate', false)
            ->where('employee.permissions.can_reactivate', false)
            ->where('employee.permissions.can_change_role', false)
            ->where('employee.permissions.can_change_tracking_mode', false)
            ->where('employee.permissions.can_manage_permissions', false)
            ->where('grantableProjects', null)
            ->where('grantablePermissions', null)
            // Null, not an empty list: no role control and no tracking control are drawn on your
            // own record at all (DESIGN.md §5.12 — hidden, never disabled).
            ->where('roleOptions', null)
            ->where('trackingModeOptions', null));
});

it('keeps the detail-only keys off a list row', function (): void {
    $row = collect($this->actingAs($this->admin)->get('/admin/employees')->viewData('page')['props']['employees'])
        ->firstWhere('id', $this->tapu->employee->id);

    foreach (['projects', 'project_permissions', 'deactivated_at'] as $detailOnly) {
        expect(array_key_exists($detailOnly, $row))->toBeFalse();
    }
});

/*
|--------------------------------------------------------------------------
| Hiring
|--------------------------------------------------------------------------
*/

it('creates the user, the employee and the schedule in one transaction, audited', function (): void {
    $response = $this->actingAs($this->admin)->post('/admin/employees', workforceEmployeePayload());

    $employee = Employee::query()->whereHas('user', fn ($q) => $q->where('email', 'nadia@goodtechies.test'))->sole();

    $response->assertRedirect('/admin/employees/'.$employee->id);

    expect($employee->employee_number)->toBe('GT-006')
        ->and($employee->role->name)->toBe(RoleName::EMPLOYEE)
        ->and($employee->tracking_mode)->toBe(TrackingMode::OfficeAttendance)
        ->and($employee->status)->toBe(UserStatus::Active)
        ->and($employee->phone)->toBe('+8801700000000')
        ->and($employee->joining_date->toDateString())->toBe('2026-10-01')
        ->and($employee->user->status)->toBe(UserStatus::Active);

    $schedule = Schedule::where('employee_id', $employee->id)->sole();

    expect($schedule->working_days)->toBe(['sun', 'mon', 'tue', 'wed', 'thu'])
        ->and((float) $schedule->working_hours_per_day)->toBe(8.0)
        ->and($schedule->office_or_remote)->toBe('office');

    $audit = AuditLog::where('event', AuditEvent::EmployeeCreated->value)->sole();

    expect($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->target_type)->toBe($employee->getMorphClass())
        ->and($audit->target_id)->toBe($employee->id)
        ->and($audit->old_value)->toBeNull()
        ->and($audit->new_value['email'])->toBe('nadia@goodtechies.test')
        ->and($audit->new_value['role'])->toBe('EMPLOYEE')
        ->and($audit->new_value['employee_number'])->toBe('GT-006')
        // No credential in the log, ever.
        ->and(array_key_exists('password', $audit->new_value))->toBeFalse();

    // The working week went through ScheduleService, which is the only writer of the table and
    // audits it — so a hire's schedule is in the trail the editor's changes are in.
    expect(AuditLog::where('event', AuditEvent::ScheduleChanged->value)->count())->toBe(1)
        ->and(ActivityLog::where('object_type', $employee->getMorphClass())
            ->where('object_id', $employee->id)
            ->value('description'))->toBe('Nadia Rahman added as EMPLOYEE');
});

it('lets the new colleague sign in with the password it said once', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/employees', workforceEmployeePayload())
        ->assertRedirect();

    $employee = Employee::query()->whereHas('user', fn ($q) => $q->where('email', 'nadia@goodtechies.test'))->sole();

    $password = workforceEmployeePasswordFromProps(
        $this->actingAs($this->admin)
            ->get('/admin/employees/'.$employee->id)
            ->viewData('page')['props'],
    );

    // Signed out, and in as the new person with the password the Admin was given. This is the
    // whole of "first sign-in" in this build: no email, no reset route, one credential handed over.
    $this->post('/logout');
    $this->flushSession();

    $this->post('/login', ['email' => 'nadia@goodtechies.test', 'password' => $password])
        ->assertRedirect();

    expect(Auth::check())->toBeTrue()
        ->and(Auth::user()->email)->toBe('nadia@goodtechies.test');
});

it('continues the GT series when the number is left blank and keeps a typed one', function (): void {
    $this->actingAs($this->admin)->post('/admin/employees', workforceEmployeePayload());
    $this->actingAs($this->admin)->post('/admin/employees', workforceEmployeePayload([
        'name' => 'Imran Hossain',
        'email' => 'imran@goodtechies.test',
    ]));
    $this->actingAs($this->admin)->post('/admin/employees', workforceEmployeePayload([
        'name' => 'Sadia Akter',
        'email' => 'sadia@goodtechies.test',
        'employee_number' => 'GT-900',
    ]));

    // Only the GT series: the factory Manager in beforeEach wears an `EMP-` number, which is
    // exactly the kind of row the series must step over rather than count.
    expect(Employee::query()
        ->pluck('employee_number')
        ->filter(fn (string $number): bool => str_starts_with($number, 'GT-'))
        ->sort()
        ->values()
        ->all())
        ->toBe(['GT-001', 'GT-002', 'GT-003', 'GT-004', 'GT-005', 'GT-006', 'GT-007', 'GT-900']);
});

it('refuses a duplicate email and a duplicate employee number', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/employees', workforceEmployeePayload(['email' => 'tapu@goodtechies.test']))
        ->assertSessionHasErrors('email');

    $this->actingAs($this->admin)
        ->post('/admin/employees', workforceEmployeePayload(['employee_number' => 'GT-003']))
        ->assertSessionHasErrors('employee_number');

    expect(User::count())->toBe(6);
});

it('refuses MANAGER, which Part C assigns to nobody in the MVP', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/employees', workforceEmployeePayload(['role' => RoleName::MANAGER->value]))
        ->assertSessionHasErrors('role');

    expect(User::count())->toBe(6);
});

/*
|--------------------------------------------------------------------------
| The first sign-in password — its own channel, said exactly once
|--------------------------------------------------------------------------
|
| There is no email in the MVP (Part H §1) and no password-reset route, so
| handing the Admin one generated password IS the delivery mechanism. What
| these assert is that it is handed over ONCE and that the promise the screen
| makes about it is true:
|
|   * it is a prop of its own on the redirect target, not a sentence inside
|     the generic `success` flash `FlashMessage.vue` renders as a persistent
|     Alert beside "Project archived";
|   * it is absent from an ordinary visit, absent from a reload, and absent
|     from another employee's page;
|   * the response that carries it tells the browser to encrypt and then drop
|     its history state, which is what stops Back re-rendering it from
|     `history.state` without asking the server;
|   * it reaches neither `audit_logs` nor any payload that is not that one.
|
*/

it('hands the password over on the redirect after a hire, as its own prop', function (): void {
    $this->actingAs($this->admin)->post('/admin/employees', workforceEmployeePayload())->assertRedirect();

    $employee = Employee::query()->whereHas('user', fn ($q) => $q->where('email', 'nadia@goodtechies.test'))->sole();

    $props = workforceEmployeeShowProps($employee->id);
    $password = workforceEmployeePasswordFromProps($props);

    expect($props['firstSignIn']['name'])->toBe('Nadia Rahman')
        ->and($props['firstSignIn']['email'])->toBe('nadia@goodtechies.test')
        // The hire is still confirmed, and the credential is no longer in that sentence.
        ->and($props['flash']['success'])->toBe('Nadia Rahman added.')
        ->and($props['flash']['success'])->not->toContain($password);
});

it('shows the password exactly once — a second visit and a reload carry nothing', function (): void {
    $this->actingAs($this->admin)->post('/admin/employees', workforceEmployeePayload());

    $employee = Employee::query()->whereHas('user', fn ($q) => $q->where('email', 'nadia@goodtechies.test'))->sole();

    $password = workforceEmployeePasswordFromProps(workforceEmployeeShowProps($employee->id));

    // The same URL, immediately, in the same session: the flash was spent by the first render.
    $again = $this->actingAs($this->admin)->get('/admin/employees/'.$employee->id);

    expect(array_key_exists('firstSignIn', $again->viewData('page')['props']))->toBeFalse()
        ->and($again->getContent())->not->toContain($password);
});

it('keeps the password off an ordinary visit to somebody else\'s record', function (): void {
    $props = workforceEmployeeShowProps($this->tapu->employee->id);

    expect(array_key_exists('firstSignIn', $props))->toBeFalse();

    // And a credential in flight for one hire is never drawn on another person's page: the flash
    // is keyed to the employee it belongs to, so a mis-click reads nothing rather than a password
    // beside the wrong name.
    $this->actingAs($this->admin)->post('/admin/employees', workforceEmployeePayload());

    $elsewhere = workforceEmployeeShowProps($this->tapu->employee->id);

    expect(array_key_exists('firstSignIn', $elsewhere))->toBeFalse();
});

it('tells the browser to seal and then drop the history entry that held it', function (): void {
    $this->actingAs($this->admin)->post('/admin/employees', workforceEmployeePayload());

    $employee = Employee::query()->whereHas('user', fn ($q) => $q->where('email', 'nadia@goodtechies.test'))->sole();

    // `encryptHistory` makes the stored page state ciphertext instead of a readable object;
    // `clearHistory` throws the key away. Without the pair, Back restores this very response —
    // password included — out of `history.state` and never asks the server. The client half is
    // `EmployeeFirstSignInPanel.vue`, which rolls the key again once it has mounted.
    $page = $this->actingAs($this->admin)->get('/admin/employees/'.$employee->id)->viewData('page');

    expect($page['encryptHistory'] ?? null)->toBeTrue()
        ->and($page['clearHistory'] ?? null)->toBeTrue();

    // An ordinary visit asks for neither, so nobody else's Back button is broken by this.
    $ordinary = $this->actingAs($this->admin)->get('/admin/employees/'.$this->tapu->employee->id)->viewData('page');

    expect(array_key_exists('encryptHistory', $ordinary))->toBeFalse()
        ->and(array_key_exists('clearHistory', $ordinary))->toBeFalse();
});

it('never audits the password and never lets a resource carry it', function (): void {
    $this->actingAs($this->admin)->post('/admin/employees', workforceEmployeePayload());

    $employee = Employee::query()->whereHas('user', fn ($q) => $q->where('email', 'nadia@goodtechies.test'))->sole();

    $password = workforceEmployeePasswordFromProps(workforceEmployeeShowProps($employee->id));

    foreach (AuditLog::all() as $row) {
        expect(json_encode([$row->old_value, $row->new_value]))->not->toContain($password);
    }

    expect(ActivityLog::query()->where('description', 'ilike', '%'.$password.'%')->count())->toBe(0)
        // Not in the database in plaintext either — `users.password` is the hash of it.
        ->and($employee->user->password)->not->toBe($password)
        ->and(password_verify($password, $employee->user->password))->toBeTrue();

    // And no list or detail payload carries it, for the employee it belongs to or for anybody.
    $list = $this->actingAs($this->admin)->get('/admin/employees');

    expect($list->getContent())->not->toContain($password)
        ->and($this->actingAs($this->admin)->get('/admin/employees/'.$employee->id)->getContent())
        ->not->toContain($password);
});

/*
|--------------------------------------------------------------------------
| Deactivate and reactivate
|--------------------------------------------------------------------------
*/

it('deactivates, ends the sessions, rotates the remember token and records the open tasks', function (): void {
    $employee = $this->tapu->employee;

    workforceInsertSession('wf-tapu-1', $this->tapu->id);
    workforceInsertSession('wf-tapu-2', $this->tapu->id);
    workforceInsertSession('wf-admin-1', $this->admin->id);

    $this->tapu->setRememberToken(Str::random(60));
    $this->tapu->save();
    $stale = $this->tapu->getRememberToken();

    $open = Task::query()->notArchived()->open()->forEmployee($employee)->pluck('id')->sort()->values()->all();

    // The seed leaves Tapu real work, which is the whole point of the flag.
    expect($open)->not->toBeEmpty();

    $this->actingAs($this->admin)
        ->post('/admin/employees/'.$employee->id.'/deactivate')
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $flash): bool => str_contains($flash, 'can no longer sign in')
            && str_contains($flash, count($open).' open'));

    expect($employee->fresh()->status)->toBe(UserStatus::Inactive)
        ->and(User::find($this->tapu->id)->status)->toBe(UserStatus::Inactive)
        ->and(User::find($this->tapu->id)->getRememberToken())->not->toBe($stale)
        ->and(DB::table('sessions')->where('user_id', $this->tapu->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $this->admin->id)->count())->toBe(1);

    $audit = AuditLog::where('event', AuditEvent::EmployeeDeactivated->value)->sole();

    expect($audit->old_value)->toBe(['status' => 'active', 'user_status' => 'active'])
        ->and($audit->new_value['status'])->toBe('inactive')
        ->and($audit->new_value['open_task_count'])->toBe(count($open))
        ->and($audit->new_value['open_task_ids'])->toBe($open);
});

it('counts the open tasks on the row before the act', function (): void {
    $employee = $this->tapu->employee;
    $expected = Task::query()->notArchived()->open()->forEmployee($employee)->count();

    $row = collect($this->actingAs($this->admin)->get('/admin/employees')->viewData('page')['props']['employees'])
        ->firstWhere('id', $employee->id);

    expect($row['impact']['open_task_count'])->toBe($expected)->and($expected)->toBeGreaterThan(0);
});

it('never deletes: no destroy route exists for an employee or a user', function (): void {
    $destroys = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('DELETE', $route->methods(), true))
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'admin/employees') || str_contains($uri, 'users'))
        ->values();

    // The one DELETE under `admin/employees` is a project-permission GRANT, which is a live
    // privilege and not a person. Nothing can delete the employee or the user.
    expect($destroys->all())->toBe(['admin/employees/{employee}/permissions/{grant}']);
});

it('reactivates without restoring the sessions it ended', function (): void {
    $employee = $this->tapu->employee;

    workforceInsertSession('wf-tapu-3', $this->tapu->id);

    $this->actingAs($this->admin)->post('/admin/employees/'.$employee->id.'/deactivate');
    $this->actingAs($this->admin)
        ->post('/admin/employees/'.$employee->id.'/reactivate')
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $flash): bool => str_contains($flash, 'can sign in again'));

    expect($employee->fresh()->status)->toBe(UserStatus::Active)
        ->and(User::find($this->tapu->id)->status)->toBe(UserStatus::Active)
        // The browser they were signed in on stays signed out. A reactivation that resurrected a
        // session somebody else may now be sitting at would be worse than no reactivation at all.
        ->and(DB::table('sessions')->where('user_id', $this->tapu->id)->count())->toBe(0);

    $audit = AuditLog::where('event', AuditEvent::EmployeeReactivated->value)->sole();

    expect($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->old_value)->toBe(['status' => 'inactive', 'user_status' => 'inactive'])
        ->and($audit->new_value)->toBe(['status' => 'active', 'user_status' => 'active']);
});

it('reports when the login was switched off, read off the audit trail', function (): void {
    $employee = $this->tapu->employee;

    $this->actingAs($this->admin)->post('/admin/employees/'.$employee->id.'/deactivate');

    $this->actingAs($this->admin)
        ->get('/admin/employees/'.$employee->id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.status', 'inactive')
            ->whereNot('employee.deactivated_at', null));
});

/*
|--------------------------------------------------------------------------
| The self-guard — all three self-actions
|--------------------------------------------------------------------------
*/

it('refuses all five self-actions with 403 and writes nothing', function (): void {
    $own = $this->admin->employee->id;
    $project = Project::query()->whereNull('archived_at')->firstOrFail();

    $this->actingAs($this->admin)
        ->put('/admin/employees/'.$own.'/role', ['role' => RoleName::EMPLOYEE->value])
        ->assertForbidden();
    $this->actingAs($this->admin)
        ->put('/admin/employees/'.$own.'/tracking-mode', ['tracking_mode' => TrackingMode::None->value])
        ->assertForbidden();
    $this->actingAs($this->admin)->post('/admin/employees/'.$own.'/deactivate')->assertForbidden();
    $this->actingAs($this->admin)->post('/admin/employees/'.$own.'/reactivate')->assertForbidden();
    $this->actingAs($this->admin)->post('/admin/employees/'.$own.'/permissions', [
        'project_id' => $project->id,
        'permission' => 'projects.view_finance',
    ])->assertForbidden();

    expect($this->admin->fresh()->status)->toBe(UserStatus::Active)
        ->and($this->admin->employee->fresh()->status)->toBe(UserStatus::Active)
        ->and($this->admin->employee->fresh()->role->name)->toBe(RoleName::ADMIN)
        ->and($this->admin->employee->fresh()->tracking_mode)->toBe(TrackingMode::OfficeAttendance)
        ->and(AuditLog::count())->toBe(0)
        ->and(DB::table('user_project_permissions')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Who may reach any of it
|--------------------------------------------------------------------------
*/

it('refuses every route to a role without roles.manage', function (): void {
    $employee = $this->tapu->employee;

    foreach ([$this->yaseen, $this->manager, $this->accountant] as $user) {
        $this->actingAs($user)->get('/admin/employees')->assertForbidden();
        $this->actingAs($user)->get('/admin/employees/'.$employee->id)->assertForbidden();
        $this->actingAs($user)->post('/admin/employees', workforceEmployeePayload())->assertForbidden();
        $this->actingAs($user)->post('/admin/employees/'.$employee->id.'/deactivate')->assertForbidden();
        $this->actingAs($user)->post('/admin/employees/'.$employee->id.'/reactivate')->assertForbidden();
        $this->actingAs($user)
            ->put('/admin/employees/'.$employee->id.'/role', ['role' => RoleName::ADMIN->value])
            ->assertForbidden();
        $this->actingAs($user)
            ->put('/admin/employees/'.$employee->id.'/tracking-mode', ['tracking_mode' => TrackingMode::None->value])
            ->assertForbidden();
    }

    // A remote employee is on the Employee shell and is refused by `surface:admin` first.
    $this->actingAs($this->tapu)->get('/admin/employees')->assertForbidden();

    expect($employee->fresh()->status)->toBe(UserStatus::Active)
        ->and($employee->fresh()->role->name)->toBe(RoleName::REMOTE_EMPLOYEE)
        ->and($employee->fresh()->tracking_mode)->toBe(TrackingMode::RemoteTimer)
        ->and(User::count())->toBe(6);
});

it('sends a guest to login', function (): void {
    $this->get('/admin/employees')->assertRedirect('/login');
    $this->post('/admin/employees/'.$this->tapu->employee->id.'/deactivate')->assertRedirect('/login');
});

it('stops a body-less write at validation, which is what the permission matrix reads', function (): void {
    // The matrix sends every row without a body, so an ADMIN cell of 302 means "got past every
    // gate and stopped at the Form Request". These two rows are the ones that depends on, so the
    // claim is asserted here rather than inferred there.
    $this->actingAs($this->admin)
        ->post('/admin/employees')
        ->assertRedirect()
        ->assertSessionHasErrors(['name', 'email', 'role', 'tracking_mode']);

    $this->actingAs($this->admin)
        ->post('/admin/employees/'.$this->tapu->employee->id.'/permissions')
        ->assertRedirect()
        ->assertSessionHasErrors(['project_id', 'permission']);

    $this->actingAs($this->admin)
        ->put('/admin/employees/'.$this->tapu->employee->id.'/role')
        ->assertRedirect()
        ->assertSessionHasErrors('role');

    $this->actingAs($this->admin)
        ->put('/admin/employees/'.$this->tapu->employee->id.'/tracking-mode')
        ->assertRedirect()
        ->assertSessionHasErrors('tracking_mode');

    expect(User::count())->toBe(6)->and(DB::table('user_project_permissions')->count())->toBe(0);
});

it('404s an id that is not there, rather than a page that says it is not yours', function (): void {
    $this->actingAs($this->admin)->get('/admin/employees/999999')->assertNotFound();
    $this->actingAs($this->admin)->post('/admin/employees/999999/deactivate')->assertNotFound();
    $this->actingAs($this->admin)->post('/admin/employees/999999/reactivate')->assertNotFound();
    $this->actingAs($this->admin)
        ->put('/admin/employees/999999/role', ['role' => RoleName::EMPLOYEE->value])
        ->assertNotFound();
    $this->actingAs($this->admin)
        ->put('/admin/employees/999999/tracking-mode', ['tracking_mode' => TrackingMode::None->value])
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Re-issuing a password — the only way back into a locked-out account
|--------------------------------------------------------------------------
|
| Without this, an account could become permanently unusable by nobody's
| mistake. There is no email (Part H §1), `routes/auth.php` has no reset
| route, and Profile → Password needs the CURRENT password — so a new hire
| who lost the sentence, or anybody who simply forgot, had a login no Admin
| in the agency could repair, on a record that must be kept forever
| (Part B §3 rule 11). The only remedy was to deactivate them and create a
| second person, splitting one human being's history across two records.
|
| It travels on the same one-shot channel as a hire's, and it does one thing
| a hire does not: it ends their sessions. That is the point rather than a
| side effect — the case where a reset matters most is the one where somebody
| else may have had the old credential, and a browser already signed in is
| exactly what you are trying to remove.
|
*/

it('re-issues a password on its own one-shot channel, and signs them out everywhere', function (): void {
    $employee = $this->tapu->employee;
    $before = $this->tapu->fresh()->password;

    // A session for them, so "ends their sessions" is asserted against a row that exists.
    DB::table('sessions')->insert([
        'id' => 'workforce-reset-session',
        'user_id' => $this->tapu->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'pest',
        'payload' => '',
        'last_activity' => time(),
    ]);

    $this->actingAs($this->admin)
        ->post('/admin/employees/'.$employee->id.'/reset-password')
        ->assertRedirect();

    $props = workforceEmployeeShowProps($employee->id);
    $password = workforceEmployeePasswordFromProps($props);

    expect($props['firstSignIn']['reissued'])->toBeTrue()
        ->and($props['firstSignIn']['email'])->toBe('tapu@goodtechies.test')
        // The credential is not in the sentence every routine confirmation uses.
        ->and($props['flash']['success'])->not->toContain($password)
        ->and(DB::table('sessions')->where('user_id', $this->tapu->id)->count())->toBe(0)
        ->and($this->tapu->fresh()->password)->not->toBe($before);

    // And it is a password that actually works.
    Auth::logout();
    expect(Auth::attempt(['email' => 'tapu@goodtechies.test', 'password' => $password]))->toBeTrue();
});

it('records the re-issue without recording the credential', function (): void {
    $employee = $this->tapu->employee;

    $this->actingAs($this->admin)->post('/admin/employees/'.$employee->id.'/reset-password');

    $password = workforceEmployeePasswordFromProps(workforceEmployeeShowProps($employee->id));

    $audit = AuditLog::where('event', AuditEvent::EmployeePasswordReset->value)->sole();

    expect($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->target_id)->toBe($employee->id)
        // A hash is not a before-value anybody should be shown.
        ->and($audit->old_value)->toBeNull()
        ->and($audit->new_value)->toBe(['email' => 'tapu@goodtechies.test', 'sessions_ended' => true])
        // The whole row, encoded, carries no credential — asserted on the JSON rather than on
        // the keys, because a password could only ever arrive here by accident.
        ->and(json_encode($audit->toArray()))->not->toContain($password)
        ->and(ActivityLog::where('object_type', $employee->getMorphClass())
            ->where('object_id', $employee->id)
            ->where('description', 'Sign-in password re-issued')
            ->count())->toBe(1);
});

it('refuses an Admin re-issuing their own password, and anybody without roles.manage', function (): void {
    // Their own: Profile → Password is where that happens, and it asks for the current one
    // first. An ability here would be a way around that proof.
    $this->actingAs($this->admin)
        ->post('/admin/employees/'.$this->admin->employee->id.'/reset-password')
        ->assertForbidden();

    $this->actingAs($this->yaseen)
        ->post('/admin/employees/'.$this->tapu->employee->id.'/reset-password')
        ->assertForbidden();

    expect(AuditLog::where('event', AuditEvent::EmployeePasswordReset->value)->count())->toBe(0);
});
