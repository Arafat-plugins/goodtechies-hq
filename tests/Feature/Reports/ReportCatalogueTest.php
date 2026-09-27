<?php

use App\Models\Permission as PermissionRow;
use App\Models\Role;
use App\Models\User;
use App\Support\Permission;
use App\Support\ReportKey;
use App\Support\RoleName;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The catalogue, and the three answers a report route can give
|--------------------------------------------------------------------------
|
| docs/report-contract.md §1:
|
|   "No reports.view permission is added. A report requires the permission
|    of THE DATA IT READS, which is why the catalogue needs no role branch:
|    the Reports index lists exactly the cases whose permission() the viewer
|    holds, and it is a capability that decides, not a name."
|
| So the sharpest test in this file is not "the Admin sees eight cards". It
| is: take ONE permission away from the ADMIN role and watch exactly one
| card disappear and exactly one route turn into a 403, with nothing else
| moving. A build that named the role instead would pass every other test
| here and fail that one.
|
| Everything is prefixed REPORTS_ / reports*, because Pest declares
| constants and functions globally across the whole suite (AGENTS.md).
|
*/

/**
 * The whole catalogue an Admin sees, in the index's display order (Part D §15's sixteen).
 *
 * This constant is the reason the catalogue tests below are worth running: an Admin holds every
 * permission, so this list changing is either a report arriving or a report quietly vanishing,
 * and both should be a decision rather than a diff nobody read.
 */
const REPORTS_ALL_SIXTEEN = [
    'task', 'project', 'overdue', 'completion', 'maintenance', 'seo', 'website-project',
    'performance', 'meeting', 'in-app-coordination',
    'employee-work', 'attendance', 'time', 'leave',
    'finance', 'payroll',
];

/** Take one permission away from a role, table-driven, the way the application grants them. */
function reportsRevoke(RoleName $role, Permission $permission): void
{
    $roleRow = Role::where('name', $role->value)->firstOrFail();
    $permissionRow = PermissionRow::where('key', $permission->value)->firstOrFail();

    $roleRow->permissions()->detach($permissionRow->getKey());
}

/**
 * The report keys the index actually offered, in order.
 *
 * @return list<string>
 */
function reportsCatalogueFor(User $viewer): array
{
    $keys = [];

    test()->actingAs($viewer)
        ->get('/admin/reports')
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$keys): void {
            foreach ($page->toArray()['props']['groups'] as $group) {
                foreach ($group['reports'] as $report) {
                    $keys[] = $report['key'];
                }
            }
        });

    return $keys;
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->employee = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
});

it('offers an Admin all eight core reports, grouped, each with its question and filters', function () {
    $this->actingAs($this->admin)
        ->get('/admin/reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Reports/Index')
            ->has('groups', 3)
            ->where('groups.0.key', 'work')
            ->where('groups.0.reports.0.key', 'task')
            ->where('groups.0.reports.0.question', 'How much work is there, and what state is it in?')
            ->where('groups.0.reports.0.filters', ['date_range', 'employee', 'project', 'client'])
            ->where('groups.2.key', 'money')
            ->where('groups.2.reports.1.key', 'payroll'),
        );

    expect(reportsCatalogueFor($this->admin))->toBe(REPORTS_ALL_SIXTEEN);
})->group('phase10', 'reports');

it('names every case it offers and offers every case it names — a menu entry with no builder is a 500', function () {
    // This used to assert the OPPOSITE: slice A had eight cases and the other eight had to 404,
    // because a card whose builder did not exist would have been a 500 with a label on it. Both
    // halves now exist, so the same worry is stated the other way round — every case the enum
    // declares is on an Admin's menu, and every card on the menu opens.
    expect(array_map(fn (ReportKey $key): string => $key->value, ReportKey::cases()))
        ->toBe(REPORTS_ENUM_ORDER())
        ->and(reportsCatalogueFor($this->admin))->toBe(REPORTS_ALL_SIXTEEN)
        // The same sixteen, ordered two different ways on purpose — see REPORTS_ENUM_ORDER().
        ->and(REPORTS_ENUM_ORDER())->toEqualCanonicalizing(REPORTS_ALL_SIXTEEN)
        ->and(REPORTS_ENUM_ORDER())->not->toBe(REPORTS_ALL_SIXTEEN);

    foreach (REPORTS_ALL_SIXTEEN as $key) {
        $this->actingAs($this->admin)->get('/admin/reports/'.$key)->assertOk();
    }

    // And a key that is not a case is still the router's 404, never a controller's 500.
    foreach (['coordination', 'productivity', 'employee-performance', 'nonsense'] as $absent) {
        $this->actingAs($this->admin)->get('/admin/reports/'.$absent)->assertNotFound();
    }
})->group('phase10', 'reports');

it('404s an unknown report key rather than 500ing on a missing builder', function () {
    $this->actingAs($this->admin)->get('/admin/reports/not-a-report')->assertNotFound();
})->group('phase10', 'reports');

it('opens every one of the eight for an Admin', function () {
    foreach (ReportKey::cases() as $key) {
        $this->actingAs($this->admin)
            ->get('/admin/reports/'.$key->value)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports/Show')
                ->where('report.key', $key->value)
                ->has('result.columns')
                ->has('result.empty'),
            );
    }
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| 403 — about the asker
|--------------------------------------------------------------------------
*/

it('refuses the Accountant the admin Reports surface, index and every report', function () {
    // Part C: a route their role may not use is 403. Their finance reporting is Phase 8's
    // /finance/report, which this slice does not touch.
    $this->actingAs($this->accountant)->get('/admin/reports')->assertForbidden();

    foreach (ReportKey::cases() as $key) {
        $this->actingAs($this->accountant)->get('/admin/reports/'.$key->value)->assertForbidden();
    }

    // …and the one they DO have is still theirs, on their own surface.
    $this->actingAs($this->accountant)->get('/finance/report')->assertOk();
})->group('phase10', 'reports');

it('refuses an employee the admin Reports surface', function () {
    $this->actingAs($this->employee)->get('/admin/reports')->assertForbidden();
    $this->actingAs($this->employee)->get('/admin/reports/task')->assertForbidden();
})->group('phase10', 'reports');

it('sends a guest to the login page rather than answering', function () {
    $this->get('/admin/reports')->assertRedirect('/login');
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| The capability, not the name
|--------------------------------------------------------------------------
*/

it('drops exactly the Finance card and 403s exactly the Finance route when finance.view is revoked', function () {
    reportsRevoke(RoleName::ADMIN, Permission::FinanceView);

    expect(reportsCatalogueFor($this->admin))->toBe(
        array_values(array_diff(REPORTS_ALL_SIXTEEN, ['finance'])),
    );

    $this->actingAs($this->admin)->get('/admin/reports/finance')->assertForbidden();

    // Nothing else moved. The other fifteen still open, which is what makes this a test of one
    // capability rather than of the whole screen.
    foreach (array_diff(REPORTS_ALL_SIXTEEN, ['finance']) as $key) {
        $this->actingAs($this->admin)->get('/admin/reports/'.$key)->assertOk();
    }
})->group('phase10', 'reports');

it('drops the three workforce reports that share attendance.manage_others, and only those three', function () {
    // Employee Work, Attendance and Time share one key, so they share one fate. **Leave does
    // not** — it is keyed on `leave.approve`, which is a different question about a different
    // table, so it stays and the Workforce heading stays with it. That is the whole point of
    // keying a report on the data it reads (10-22): the group is not a unit of permission, and
    // revoking one key must not take a neighbour with it.
    reportsRevoke(RoleName::ADMIN, Permission::AttendanceManageOthers);

    expect(reportsCatalogueFor($this->admin))
        ->toBe(array_values(array_diff(REPORTS_ALL_SIXTEEN, ['employee-work', 'attendance', 'time'])));

    $this->actingAs($this->admin)
        ->get('/admin/reports')
        ->assertInertia(fn (Assert $page) => $page
            // Three bands still, because Leave holds the Workforce one open on its own key.
            ->has('groups', 3)
            ->where('groups.0.key', 'work')
            ->where('groups.1.key', 'workforce')
            ->has('groups.1.reports', 1)
            ->where('groups.1.reports.0.key', 'leave')
            ->where('groups.2.key', 'money'),
        );

    foreach (['employee-work', 'attendance', 'time'] as $key) {
        $this->actingAs($this->admin)->get('/admin/reports/'.$key)->assertForbidden();
    }
})->group('phase10', 'reports');

it('drops the payroll report when payroll.view_others is revoked, leaving payroll.view_own alone', function () {
    reportsRevoke(RoleName::ADMIN, Permission::PayrollViewOthers);

    expect(reportsCatalogueFor($this->admin))->not->toContain('payroll');
    $this->actingAs($this->admin)->get('/admin/reports/payroll')->assertForbidden();

    // Their own payslip is a different key and is untouched.
    $this->actingAs($this->admin)->get('/payslip')->assertOk();
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| The filter options are scoped, and the client list is a permission
|--------------------------------------------------------------------------
*/

it('offers only the filters each report accepts, and no client list without clients.view_full', function () {
    $this->actingAs($this->admin)
        ->get('/admin/reports/finance')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters', ['date_range'])
            ->where('options', []),
        );

    $this->actingAs($this->admin)
        ->get('/admin/reports/task')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('options.employees', 5)
            ->has('options.projects', 7)
            ->has('options.clients', 4),
        );

    reportsRevoke(RoleName::ADMIN, Permission::ClientsViewFull);

    // A fresh instance: `User::hasPermission()` memoises the key list per object, and this
    // test has already made two requests with this one.
    $this->actingAs($this->admin->fresh())
        ->get('/admin/reports/task')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('options.projects')
            // Absent, not empty: the names ARE the restricted field (Part C §2).
            ->missing('options.clients'),
        );
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| No role name in the code that decides
|--------------------------------------------------------------------------
*/

it('names no role in the report catalogue, the value objects or the service', function () {
    // The contract's whole argument for ReportKey::permission() is that a capability decides.
    // A role name in any of these files would mean it does not, and no other test here would
    // notice as long as the seeded matrix happened to agree.
    $files = [
        __DIR__.'/../../../app/Support/ReportKey.php',
        __DIR__.'/../../../app/Support/ReportFilter.php',
        __DIR__.'/../../../app/Support/ReportFilters.php',
        __DIR__.'/../../../app/Support/ReportResult.php',
        __DIR__.'/../../../app/Services/ReportService.php',
    ];

    foreach ($files as $file) {
        $source = file_get_contents($file);

        expect($source)->not->toContain('RoleName');
        expect($source)->not->toContain('hasRole(');
    }
})->group('phase10', 'reports');

/**
 * The enum's own declaration order — slice A's eight in the order the contract's §1 table
 * lists them, then slice B's eight.
 *
 * It is **deliberately not** the index's display order, and the test that compares the two is
 * why this function exists: if either were derived from the other, the catalogue would be
 * ordered by the accident of when a report was written rather than by what a reader is looking
 * for, and nobody would notice until the sixteenth card was in the wrong band.
 *
 * @return list<string>
 */
function REPORTS_ENUM_ORDER(): array
{
    return [
        'task', 'employee-work', 'project', 'overdue', 'attendance', 'time', 'finance', 'payroll',
        'completion', 'leave', 'maintenance', 'seo', 'website-project', 'meeting', 'performance',
        'in-app-coordination',
    ];
}
