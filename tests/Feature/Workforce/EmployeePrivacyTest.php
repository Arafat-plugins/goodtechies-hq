<?php

use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\Permission as PermissionModel;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeAdministrationService;
use App\Support\Permission;
use App\Support\RoleName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Employees privacy — the salary that must never be here
|--------------------------------------------------------------------------
|
| Part C §1's field rule, applied to the one screen that would be worst to get
| wrong. `EmployeeResource`'s docblock states the constraint; this file is what
| keeps it, and it deliberately asserts against the **encoded response** of
| every endpoint rather than against the resource's array — a promise that only
| the resource's own test checks is a promise somebody deletes with the
| resource.
|
| Three separate claims, one test each so a failure says which broke:
|
|   1. no salary KEY anywhere in any payload (recursive walk);
|   2. no seeded salary FIGURE anywhere in the encoded body — which catches a
|      number arriving as the value of an allowed key, the way
|      tests/Feature/Finance/AccountantProjectEndpointTest.php does;
|   3. the record fields (email, phone, joining date, manager, last sign-in)
|      are ABSENT — asserted with array_key_exists, because `=== null` passes
|      for both the right answer and the wrong one.
|
| Plus Part C's other half: a record outside the requester's scope is absent
| from the list and **404 by id, never 403**.
|
| Everything here is prefixed WORKFORCE_PRIVACY_ / workforcePrivacy*, because
| Pest declares constants and functions globally across the suite (AGENTS.md).
|
*/

/** Money, and the two credentials, that must not appear as a key anywhere in these payloads. */
const WORKFORCE_PRIVACY_FORBIDDEN_KEYS = [
    'base_salary',
    'allowance',
    'net_salary',
    'salary',
    'bonus',
    'deduction',
    'advance',
    'leave_impact',
    'admin_notes',
    'price',
    'recurring_amount',
    'contract_value',
    'profitability_snapshot',
    'password',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'remember_token',
];

/**
 * Every figure `PayrollSeeder` puts in `employee_salaries`, exactly as a decimal column
 * serializes. If any of these reaches this screen, the leak is real and not theoretical.
 */
const WORKFORCE_PRIVACY_SALARY_FIGURES = [
    '2000.00',
    '1800.00',
    '1100.00',
    '900.00',
    '800.00',
    '1000.00',
];

beforeEach(function (): void {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->service = app(EmployeeAdministrationService::class);
});

/**
 * The props of every Employees endpoint that answers with a page, plus the flash of every one
 * that answers with a redirect — the whole surface this slice adds, read the way a browser
 * receives it.
 *
 * @return list<array{label: string, body: string}>
 */
function workforcePrivacyBodies(User $admin, Employee $employee): array
{
    $bodies = [];

    foreach (['/admin/employees', '/admin/employees?status=active', '/admin/employees/'.$employee->id] as $url) {
        $response = test()->actingAs($admin)->get($url);
        $response->assertOk();

        $bodies[] = [
            'label' => 'GET '.$url,
            // The assembled page payload, encoded — not the resource's array.
            'body' => json_encode($response->viewData('page'), JSON_THROW_ON_ERROR),
        ];
        // And the raw HTML the browser is handed, which is where an escaped figure would hide.
        $bodies[] = ['label' => 'HTML '.$url, 'body' => $response->getContent()];
    }

    $project = Project::query()->whereNull('archived_at')->firstOrFail();

    $writes = [
        'POST /admin/employees' => fn () => test()->actingAs($admin)->post('/admin/employees', [
            'name' => 'Privacy Probe',
            'email' => 'probe@goodtechies.test',
            'employee_number' => null,
            'phone' => null,
            'role' => RoleName::EMPLOYEE->value,
            'employment_type' => 'full_time',
            'joining_date' => null,
            'manager_id' => null,
            'tracking_mode' => 'office_attendance',
            'working_days' => ['sun'],
            'working_hours_per_day' => '8',
            'start_time' => null,
            'office_or_remote' => 'office',
        ]),
        'POST .../permissions' => fn () => test()->actingAs($admin)->post(
            '/admin/employees/'.$employee->id.'/permissions',
            ['project_id' => $project->id, 'permission' => Permission::ProjectsViewFinance->value],
        ),
        'POST .../deactivate' => fn () => test()->actingAs($admin)
            ->post('/admin/employees/'.$employee->id.'/deactivate'),
        'POST .../reactivate' => fn () => test()->actingAs($admin)
            ->post('/admin/employees/'.$employee->id.'/reactivate'),
    ];

    foreach ($writes as $label => $call) {
        $response = $call();

        $bodies[] = [
            'label' => $label,
            'body' => json_encode([
                'flash' => $response->getSession()->get('success'),
                'content' => $response->getContent(),
            ], JSON_THROW_ON_ERROR),
        ];
    }

    // The revoke, last, so it has a grant to take.
    $grantId = DB::table('user_project_permissions')->where('user_id', $employee->user_id)->value('id');
    $response = test()->actingAs($admin)
        ->delete('/admin/employees/'.$employee->id.'/permissions/'.$grantId);

    $bodies[] = [
        'label' => 'DELETE .../permissions/{grant}',
        'body' => json_encode([
            'flash' => $response->getSession()->get('success'),
            'content' => $response->getContent(),
        ], JSON_THROW_ON_ERROR),
    ];

    return $bodies;
}

/**
 * Every key in a decoded payload, however deep.
 *
 * @return list<string>
 */
function workforcePrivacyKeys(string $json): array
{
    $decoded = json_decode($json, true);

    $keys = [];

    if (is_array($decoded)) {
        array_walk_recursive($decoded, function (mixed $value, string|int $key) use (&$keys): void {
            $keys[] = (string) $key;
        });

        // array_walk_recursive skips the keys of nested ARRAYS, so the nested maps are walked too.
        $stack = [$decoded];

        while ($stack !== []) {
            $current = array_pop($stack);

            foreach ($current as $key => $value) {
                $keys[] = (string) $key;

                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }
    }

    return $keys;
}

/*
|--------------------------------------------------------------------------
| No salary — key, figure or otherwise
|--------------------------------------------------------------------------
*/

it('carries no salary or credential key anywhere in any Employees payload', function (): void {
    foreach (workforcePrivacyBodies($this->admin, $this->tapu->employee) as $payload) {
        $keys = workforcePrivacyKeys($payload['body']);

        foreach (WORKFORCE_PRIVACY_FORBIDDEN_KEYS as $forbidden) {
            expect($keys)->not->toContain($forbidden, $payload['label'].' leaked the key '.$forbidden);
        }
    }
});

it('names no seeded salary figure anywhere in the encoded body', function (): void {
    // The key walk above catches a field somebody NAMED; this catches a figure arriving as the
    // value of an allowed key. The seeded salaries are real rows in `employee_salaries` for every
    // person on this screen, so this is not a hypothetical.
    foreach (workforcePrivacyBodies($this->admin, $this->tapu->employee) as $payload) {
        foreach (WORKFORCE_PRIVACY_SALARY_FIGURES as $figure) {
            expect($payload['body'])->not->toContain($figure, $payload['label'].' leaked '.$figure);
        }
    }
});

/*
|--------------------------------------------------------------------------
| The record fields are absent, not null
|--------------------------------------------------------------------------
*/

it('omits the record fields from a reader who may not see the record', function (): void {
    $request = Request::create('/admin/employees');
    $request->setUserResolver(fn (): User => $this->yaseen);

    $payload = (new EmployeeResource($this->tapu->employee))->toArray($request);

    foreach (['email', 'phone', 'employment_type', 'employment_type_label', 'joining_date', 'manager', 'last_login_at'] as $field) {
        expect(array_key_exists($field, $payload))->toBeFalse($field.' reached a reader who may not see it');
    }

    // The roster half is still there — a name and a status are what a colleague may know — and
    // every permission answers no.
    expect($payload['name'])->toBe('Tapu')
        ->and($payload['status'])->toBe('active')
        ->and($payload['permissions'])->toBe([
            'can_change_role' => false,
            'can_change_tracking_mode' => false,
            'can_deactivate' => false,
            'can_reactivate' => false,
            'can_reset_password' => false,
            'can_manage_permissions' => false,
        ]);
});

it('gives somebody their own record, because EmployeePolicy::view allows self', function (): void {
    $request = Request::create('/admin/employees');
    $request->setUserResolver(fn (): User => $this->yaseen);

    $payload = (new EmployeeResource($this->yaseen->employee))->toArray($request);

    expect($payload['email'])->toBe('yaseen@goodtechies.test')
        ->and($payload['is_you'])->toBeTrue()
        // …and still no salary, on their own record, where the temptation is greatest.
        ->and(array_key_exists('base_salary', $payload))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Out of scope is absent, and absent by id is 404
|--------------------------------------------------------------------------
*/

it('shows nobody to a role without roles.manage, and answers null by id', function (): void {
    expect($this->service->listFor($this->yaseen))->toBeEmpty()
        // Null is what the controller turns into 404. It is a query answer and not a refusal,
        // which is the whole of Part C's rule: a 403 here would confirm the record exists.
        ->and($this->service->findFor($this->yaseen, $this->tapu->employee))->toBeNull()
        ->and($this->service->findFor(null, $this->tapu->employee))->toBeNull()
        ->and($this->service->listFor(null))->toBeEmpty();
});

it('scopes a roles.manage holder who is not an Admin to their own team', function (): void {
    // The branch production does not use today — ADMIN is the only role Part C §1 gives
    // `roles.manage` — and the reason it exists: a role that is given the key later must not
    // silently receive the whole company with it.
    $manager = Employee::factory()->forRole(RoleName::MANAGER)->create();

    DB::table('role_permissions')->insert([
        'role_id' => Role::where('name', RoleName::MANAGER->value)->firstOrFail()->id,
        'permission_id' => PermissionModel::where('key', Permission::RolesManage->value)->firstOrFail()->id,
    ]);

    $this->tapu->employee->update(['manager_id' => $manager->id]);

    // Re-read the user: `User::hasPermission()` memoizes the keys per instance.
    $viewer = User::find($manager->user_id);

    expect($this->service->listFor($viewer)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$manager->id, $this->tapu->employee->id])->sort()->values()->all())
        ->and($this->service->findFor($viewer, $this->tapu->employee))->not->toBeNull()
        // Yaseen reports to nobody, so he is absent from the list and null by id — 404, not 403.
        ->and($this->service->findFor($viewer, $this->yaseen->employee))->toBeNull();
});

it('has no deactivated_at until there is a deactivation in the audit trail', function (): void {
    $employee = $this->tapu->employee;

    expect($this->service->deactivatedAt($employee))->toBeNull();

    $this->actingAs($this->admin)->post('/admin/employees/'.$employee->id.'/deactivate');

    expect($this->service->deactivatedAt($employee->fresh()))->not->toBeNull();
});
