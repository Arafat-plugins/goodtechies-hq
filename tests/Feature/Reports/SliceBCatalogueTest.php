<?php

use App\Models\Permission as PermissionRow;
use App\Models\Role;
use App\Models\User;
use App\Services\ReportService;
use App\Support\Permission;
use App\Support\ReportColumn;
use App\Support\ReportFilters;
use App\Support\ReportGroup;
use App\Support\ReportKey;
use App\Support\RoleName;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The catalogue of sixteen, and the three answers a report route can give
|--------------------------------------------------------------------------
|
| Slice A proved the catalogue for the eight core reports. This file is the
| same three questions asked of the eight Part D §15 adds:
|
|   - they are ON the menu, grouped, and every one of them OPENS WITH REAL
|     ROWS on a freshly seeded database (Part E Phase 10's "Done when");
|   - a viewer without the permission of the data gets no card and a 403,
|     and it is a CAPABILITY that decides — revoking one key moves exactly
|     one report;
|   - every link a cell carries goes somewhere that answers.
|
| The link check is the one that could not exist before this slice:
| `ReportColumn::linkedBy()` is new, and a report whose "Completed 3" opens
| a 404 is worse than one with no link at all.
|
| Prefixed SLICEB_ / slicebCat*, because Pest declares constants and
| functions globally (AGENTS.md).
|
*/

/** The eight Part D §15 adds, in the order the index draws them. */
const SLICEB_EIGHT = [
    'completion',
    'maintenance',
    'seo',
    'website-project',
    'performance',
    'meeting',
    'in-app-coordination',
    'leave',
];

/** All sixteen, grouped the way the index groups them. */
const SLICEB_SIXTEEN = [
    'task', 'project', 'overdue', 'completion', 'maintenance', 'seo', 'website-project',
    'performance', 'meeting', 'in-app-coordination',
    'employee-work', 'attendance', 'time', 'leave',
    'finance', 'payroll',
];

function slicebCatRevoke(RoleName $role, Permission $permission): void
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
function slicebCatCatalogue(User $viewer): array
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

it('offers an Admin all sixteen reports, grouped, in the order Part D §15 names them', function () {
    expect(slicebCatCatalogue($this->admin))->toBe(SLICEB_SIXTEEN);

    // Sixteen cases and sixteen builders: a case with no builder is a menu entry that 500s, and
    // the loop below is what would find it.
    expect(ReportKey::cases())->toHaveCount(16);

    $this->actingAs($this->admin)
        ->get('/admin/reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Reports/Index')
            ->has('groups', 3)
            ->where('groups.0.reports.3.key', 'completion')
            ->where('groups.0.reports.3.question', 'How much of what was started got finished, and how long did it take?')
            ->where('groups.1.reports.3.key', 'leave')
            ->where('groups.1.reports.3.filters', ['date_range', 'employee']),
        );
})->group('phase10', 'reports');

it('opens every one of the eight with real rows on a freshly seeded database', function () {
    // Part E Phase 10: "every report opens with real data". Three of the first eight did not,
    // and that is what decision 10-27 and `WorkSeeder` exist for — so this is asserted per
    // report rather than trusted.
    foreach (SLICEB_EIGHT as $key) {
        $rows = [];

        $this->actingAs($this->admin)
            ->get('/admin/reports/'.$key)
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$rows, $key): void {
                expect($page->toArray()['props']['report']['key'])->toBe($key);
                $rows = $page->toArray()['props']['result']['rows'];
            });

        expect($rows)->not->toBe([], "The {$key} report opened empty on a seeded database.");
    }
})->group('phase10', 'reports');

it('opens every one of the eight over the default window too, not only a wide one', function () {
    // The default range is the current calendar month (contract §2), which is what somebody
    // actually lands on. A report that only fills up when a window is widened by hand is a
    // report that looks broken on the first click.
    foreach (SLICEB_EIGHT as $key) {
        $report = ReportKey::from($key);

        $result = app(ReportService::class)->build(
            $report,
            $this->admin,
            ReportFilters::for($report, [], Carbon::today()),
        );

        expect($result->rows)->not->toBe([], "The {$key} report is empty for the current month.");
    }
})->group('phase10', 'reports');

it('keeps every one of the eight inside the three-chart budget and the seven formats', function () {
    foreach (SLICEB_EIGHT as $key) {
        $report = ReportKey::from($key);

        $result = app(ReportService::class)->build(
            $report,
            $this->admin,
            ReportFilters::for($report, [
                'from' => Carbon::today()->subDays(45)->toDateString(),
                'to' => Carbon::today()->addDays(35)->toDateString(),
            ], Carbon::today()),
        );

        expect(count($result->charts))->toBeLessThanOrEqual(3);
        expect($result->empty)->not->toBe('');

        // A row is keyed by its columns, plus the href keys those columns name — and by nothing
        // else. An href key that no column points at is a value travelling to the browser for
        // no reason (contract §3).
        $declared = array_map(fn (ReportColumn $column): string => $column->key, $result->columns);

        // `array_unique`, because two columns may point at ONE href key — the Meeting report's
        // title and its "Tasks created" count both open the meeting, and carrying the same URL
        // twice on a row would be two things to keep in step.
        $links = array_values(array_unique(array_filter(array_map(
            fn (ReportColumn $column): ?string => $column->linkKey,
            $result->columns,
        ))));

        expect($declared)->not->toBe([]);

        foreach ($result->rows as $row) {
            expect(array_keys($row))->toEqualCanonicalizing([...$declared, ...$links]);
        }

        if ($result->totals !== null) {
            // The footer is the columns and never the hrefs: a total does not link anywhere.
            expect(array_keys($result->totals))->toEqualCanonicalizing($declared);
        }
    }
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| Every link goes somewhere that answers
|--------------------------------------------------------------------------
*/

it('gives every linked cell an href that answers 200 for the viewer it was built for', function () {
    $seen = 0;

    foreach (ReportKey::cases() as $report) {
        $result = app(ReportService::class)->build(
            $report,
            $this->admin,
            ReportFilters::for($report, [
                'from' => Carbon::today()->subDays(45)->toDateString(),
                'to' => Carbon::today()->addDays(35)->toDateString(),
            ], Carbon::today()),
        );

        $links = array_values(array_unique(array_filter(array_map(
            fn (ReportColumn $column): ?string => $column->linkKey,
            $result->columns,
        ))));

        foreach ($result->rows as $row) {
            foreach ($links as $link) {
                if (! array_key_exists($link, $row)) {
                    continue;
                }

                $seen++;

                $this->actingAs($this->admin)
                    ->get((string) $row[$link])
                    ->assertOk();
            }
        }
    }

    // A guard on the guard: if the builders stopped linking anything, the loop above would pass
    // by doing nothing at all.
    expect($seen)->toBeGreaterThan(20);
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| 403 — about the asker
|--------------------------------------------------------------------------
*/

it('refuses the Accountant and an employee every one of the eight', function () {
    foreach (SLICEB_EIGHT as $key) {
        $this->actingAs($this->accountant)->get('/admin/reports/'.$key)->assertForbidden();
        $this->actingAs($this->employee)->get('/admin/reports/'.$key)->assertForbidden();
    }
})->group('phase10', 'reports');

it('404s a report key that is not a case, and the old spelling of coordination', function () {
    // `{report}` binds to a `ReportKey`, so a value that is not a case is a 404 from the router
    // rather than a 500 from a missing builder — including `coordination`, which is NOT the key
    // (`in-app-coordination` is, after Part D §15's own name for the report).
    foreach (['coordination', 'website', 'not-a-report', 'in_app_coordination'] as $missing) {
        $this->actingAs($this->admin)->get('/admin/reports/'.$missing)->assertNotFound();
    }
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| The capability, not the name
|--------------------------------------------------------------------------
*/

it('drops exactly the Leave card when leave.approve is revoked, and nothing else moves', function () {
    // The sharpest shape of the contract's §1: Leave is `leave.approve` and NOT
    // `attendance.manage_others`, so revoking it must leave the three attendance-keyed
    // workforce reports exactly where they were.
    slicebCatRevoke(RoleName::ADMIN, Permission::LeaveApprove);

    expect(slicebCatCatalogue($this->admin))->toBe(
        array_values(array_diff(SLICEB_SIXTEEN, ['leave'])),
    );

    $this->actingAs($this->admin)->get('/admin/reports/leave')->assertForbidden();

    foreach (['employee-work', 'attendance', 'time'] as $key) {
        $this->actingAs($this->admin->fresh())->get('/admin/reports/'.$key)->assertOk();
    }
})->group('phase10', 'reports');

it('drops exactly the Meeting card when meetings.use is revoked', function () {
    slicebCatRevoke(RoleName::ADMIN, Permission::MeetingsUse);

    expect(slicebCatCatalogue($this->admin))->toBe(
        array_values(array_diff(SLICEB_SIXTEEN, ['meeting'])),
    );

    $this->actingAs($this->admin)->get('/admin/reports/meeting')->assertForbidden();
    $this->actingAs($this->admin->fresh())->get('/admin/reports/in-app-coordination')->assertOk();
})->group('phase10', 'reports');

it('drops exactly the In-app coordination card when messages.use is revoked', function () {
    slicebCatRevoke(RoleName::ADMIN, Permission::MessagesUse);

    expect(slicebCatCatalogue($this->admin))->toBe(
        array_values(array_diff(SLICEB_SIXTEEN, ['in-app-coordination'])),
    );

    $this->actingAs($this->admin)->get('/admin/reports/in-app-coordination')->assertForbidden();
    $this->actingAs($this->admin->fresh())->get('/admin/reports/meeting')->assertOk();
})->group('phase10', 'reports');

it('drops the four project cuts together with the Project report when projects.view is revoked', function () {
    // They share one key, so they share one fate — and Completion does not go with them,
    // because it reads tasks.
    slicebCatRevoke(RoleName::ADMIN, Permission::ProjectsView);

    expect(slicebCatCatalogue($this->admin))->toBe([
        'task', 'overdue', 'completion', 'meeting', 'in-app-coordination',
        'employee-work', 'attendance', 'time', 'leave', 'finance', 'payroll',
    ]);

    foreach (['project', 'maintenance', 'seo', 'website-project', 'performance'] as $key) {
        $this->actingAs($this->admin)->get('/admin/reports/'.$key)->assertForbidden();
    }

    $this->actingAs($this->admin->fresh())->get('/admin/reports/completion')->assertOk();
})->group('phase10', 'reports');

/*
|--------------------------------------------------------------------------
| The filter bar each of the eight offers
|--------------------------------------------------------------------------
*/

it('offers no employee picker on a report that takes no employee filter', function () {
    // Performance takes no employee (Part D §2), so the control is ABSENT rather than present
    // and ignored — a filter the server drops is worse than none (10-33's reasoning).
    $this->actingAs($this->admin)
        ->get('/admin/reports/performance')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters', ['date_range', 'project', 'client'])
            ->missing('options.employees')
            ->has('options.projects', 7)
            ->has('options.clients', 4),
        );

    // Leave takes an employee and no project or client: its rows are people.
    $this->actingAs($this->admin)
        ->get('/admin/reports/leave')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters', ['date_range', 'employee'])
            ->has('options.employees', 5)
            ->missing('options.projects')
            ->missing('options.clients'),
        );

    // Meeting and coordination take a project and no client: neither table has one.
    foreach (['meeting', 'in-app-coordination'] as $key) {
        $this->actingAs($this->admin)
            ->get('/admin/reports/'.$key)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.filters', ['date_range', 'project'])
                ->missing('options.employees')
                ->missing('options.clients'),
            );
    }
})->group('phase10', 'reports');

it('puts each of the eight under a heading, and names no role to decide it', function () {
    foreach (SLICEB_EIGHT as $key) {
        expect(ReportGroup::inDisplayOrder())->toContain(ReportKey::from($key)->group());
    }

    // The contract's whole argument is that a capability decides. A role name in either file
    // would mean it does not, and the seeded matrix agreeing would hide it.
    foreach ([
        __DIR__.'/../../../app/Support/ReportKey.php',
        __DIR__.'/../../../app/Services/ReportService.php',
    ] as $file) {
        $source = file_get_contents($file);

        expect($source)->not->toContain('RoleName')
            ->and($source)->not->toContain('hasRole(');
    }
})->group('phase10', 'reports');
