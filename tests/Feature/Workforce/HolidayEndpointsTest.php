<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\RoleName;
use Database\Seeders\HolidaySeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Admin → Workforce → Leave → Holidays: the screen the client edits
|--------------------------------------------------------------------------
|
| `HolidaySeeder` supplies the Bangladesh public-holiday list for the current
| year and roughly two thirds of those dates are lunar estimates rather than
| gazetted dates, so this screen is not an optional extra — it is where the
| seed is made true. What is asserted here:
|
| **Every refusal is 403, and the one 404 is an unknown id.** Unlike the
| Recurring block, that is a fact about the RECORD and not about the surface:
| a holiday has no employee, no project and no scope, so there is no holiday
| that is present for one signed-in reader and absent for another, and Part
| C's absence rule has nothing to be about. The matrix carries the 403 cells;
| the 404 is here, because every role that could show it there is refused by
| `surface:admin` first.
|
| **Permission is the policy's, per record, resolved on the server.** The
| payload's `canManage` and each row's `permissions` block are asserted, and
| the MANAGER case is the one worth reading twice: a Manager may approve
| their own team's leave and still may not put a day on the company calendar.
|
| **A write reaches the attendance derivation.** The last block adds a
| holiday over an HTTP request and then asks the roster what that day is.
|
*/

beforeEach(function (): void {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;

    $this->year = (int) Carbon::today(config('app.timezone'))->year;
});

/*
|--------------------------------------------------------------------------
| Reading
|--------------------------------------------------------------------------
*/

it('shows an Admin the seeded year, with the year switcher and the manage flag', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/holidays')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Holidays/Index')
            ->where('year', $this->year)
            ->where('currentYear', $this->year)
            ->where('canManage', true)
            ->has('years')
            ->has('holidays', Holiday::query()->inYear($this->year)->count())
            // Per record, from the policy, on the server — never a role compared in Vue
            // (decisions 2-28, 2-31).
            ->where('holidays.0.permissions.can_update', true)
            ->where('holidays.0.permissions.can_delete', true)
            // Derived server-side so the screen formats nothing and the agency's timezone wins.
            ->has('holidays.0.weekday')
            ->has('holidays.0.days_away'));
});

it('opens a year with no holidays rather than refusing it — next January', function (): void {
    // The following January is the case this exists for: no seeded list, an empty year, and an
    // Admin who has to be told to type the gazette in.
    $this->actingAs($this->admin)
        ->get('/admin/holidays?year='.($this->year + 1))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('year', $this->year + 1)
            ->has('holidays', 0));
});

it('falls back to the current year for a year nobody could mean', function (): void {
    foreach (['not-a-year', '90210', '-3', ''] as $value) {
        $this->actingAs($this->admin)
            ->get('/admin/holidays?year='.$value)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('year', $this->year));
    }
});

/*
|--------------------------------------------------------------------------
| Who is refused, and with what
|--------------------------------------------------------------------------
*/

it('refuses everybody but the Admin with 403, including the Manager', function (): void {
    foreach ([$this->manager, $this->yaseen, $this->tapu, $this->accountant] as $user) {
        $this->actingAs($user)->get('/admin/holidays')->assertForbidden();
        $this->actingAs($user)->post('/admin/holidays', [
            'date' => $this->year.'-11-11', 'name' => 'A day they invented',
        ])->assertForbidden();
    }

    // A Manager may rule on their own team's leave and still may not shut the agency for a
    // day: `settings.manage` is the key, not the Workforce → Leave area's.
    expect(Holiday::where('name', 'A day they invented')->exists())->toBeFalse();
});

it('answers 404 for a holiday that is not there, and that is the only 404 here', function (): void {
    $this->actingAs($this->admin)
        ->put('/admin/holidays/999999', ['date' => $this->year.'-11-11', 'name' => 'Nothing'])
        ->assertNotFound();

    $this->actingAs($this->admin)->delete('/admin/holidays/999999')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Adding, renaming, moving, removing
|--------------------------------------------------------------------------
*/

it('lets an Admin add a holiday, and says so naming the day', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/holidays', ['date' => $this->year.'-11-11', 'name' => '  Agency Day  '])
        ->assertRedirect('/admin/holidays?year='.$this->year)
        ->assertSessionHas('success');

    $holiday = Holiday::where('name', 'Agency Day')->firstOrFail();

    expect($holiday->date->toDateString())->toBe($this->year.'-11-11')
        // Trimmed, so a stray space cannot make a second row that looks identical.
        ->and($holiday->name)->toBe('Agency Day');

    expect(session('success'))->toContain('Agency Day')
        ->and(session('success'))->toContain('11 November');
});

it('refuses the same holiday twice on one day, with a sentence that is true', function (): void {
    $this->actingAs($this->admin)->post('/admin/holidays', [
        'date' => $this->year.'-11-11', 'name' => 'Agency Day',
    ])->assertRedirect();

    $this->actingAs($this->admin)
        ->post('/admin/holidays', ['date' => $this->year.'-11-11', 'name' => 'Agency Day'])
        ->assertSessionHasErrors('date');

    expect(Holiday::where('date', $this->year.'-11-11')->count())->toBe(1);
});

it('allows a second, differently named observance on the same day', function (): void {
    $this->actingAs($this->admin)->post('/admin/holidays', [
        'date' => $this->year.'-11-11', 'name' => 'Agency Day',
    ])->assertRedirect();

    $this->actingAs($this->admin)
        ->post('/admin/holidays', ['date' => $this->year.'-11-11', 'name' => 'Founders Day'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Holiday::where('date', $this->year.'-11-11')->count())->toBe(2);
});

it('renames and moves a holiday in one edit, and follows it to its new year', function (): void {
    $holiday = Holiday::factory()->on(Carbon::parse($this->year.'-01-03'))->named('Eid estimate')->create();

    // The real case: a lunar date seeded in early January that the gazette puts in the previous
    // year. The redirect has to follow the row, or the Admin watches their own edit vanish.
    $this->actingAs($this->admin)
        ->put('/admin/holidays/'.$holiday->id, [
            'date' => ($this->year - 1).'-12-31',
            'name' => 'Eid ul-Fitr',
        ])
        ->assertRedirect('/admin/holidays?year='.($this->year - 1));

    $holiday->refresh();

    expect($holiday->name)->toBe('Eid ul-Fitr')
        ->and($holiday->date->toDateString())->toBe(($this->year - 1).'-12-31');
});

it('lets an Admin save a holiday without changing it', function (): void {
    $holiday = Holiday::where('name', 'Victory Day')->firstOrFail();

    // The uniqueness rule ignores the routed row, so re-saving is not "already taken".
    $this->actingAs($this->admin)
        ->put('/admin/holidays/'.$holiday->id, [
            'date' => $holiday->date->toDateString(),
            'name' => $holiday->name,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('removes a holiday and says what that day goes back to', function (): void {
    $holiday = Holiday::where('name', 'Victory Day')->firstOrFail();

    $this->actingAs($this->admin)
        ->delete('/admin/holidays/'.$holiday->id)
        ->assertRedirect('/admin/holidays?year='.$holiday->date->year);

    expect(Holiday::whereKey($holiday->id)->exists())->toBeFalse()
        ->and(session('success'))->toContain('Victory Day')
        ->and(session('success'))->toContain('work schedule');
});

/*
|--------------------------------------------------------------------------
| The audit trail
|--------------------------------------------------------------------------
*/

it('records every change as a configuration change, with old and new', function (): void {
    $before = AuditLog::where('event', AuditEvent::ConfigurationChanged->value)->count();

    $this->actingAs($this->admin)->post('/admin/holidays', [
        'date' => $this->year.'-11-11', 'name' => 'Agency Day',
    ]);

    $holiday = Holiday::where('name', 'Agency Day')->firstOrFail();

    $this->actingAs($this->admin)->put('/admin/holidays/'.$holiday->id, [
        'date' => $this->year.'-11-12', 'name' => 'Agency Day',
    ]);

    $this->actingAs($this->admin)->delete('/admin/holidays/'.$holiday->id);

    $rows = AuditLog::where('event', AuditEvent::ConfigurationChanged->value)
        ->orderBy('id')
        ->get()
        ->slice($before)
        ->values();

    // Three acts, three rows. No new AuditEvent case was invented: Part C §4's own list names
    // "configuration changes", and the holiday calendar is one.
    expect($rows)->toHaveCount(3)
        ->and($rows[0]->old_value)->toBeNull()
        ->and($rows[0]->new_value['name'])->toBe('Agency Day')
        // The move carries both halves in one shape, so a reader diffs them by eye.
        ->and($rows[1]->old_value['date'])->toBe($this->year.'-11-11')
        ->and($rows[1]->new_value['date'])->toBe($this->year.'-11-12')
        // And the delete says what went, rather than only that something did (decision 2-23).
        ->and($rows[2]->old_value['name'])->toBe('Agency Day')
        ->and($rows[2]->new_value)->toBeNull()
        ->and($rows[2]->actor_id)->toBe($this->admin->id);
});

/*
|--------------------------------------------------------------------------
| A write reaching the attendance derivation
|--------------------------------------------------------------------------
*/

it('changes what the roster says for a day, from an HTTP write', function (): void {
    // A Wednesday in the past, a working day in the seeded Sun–Thu week, with no seeded
    // holiday on it.
    $wednesday = '2026-09-16';

    $before = $this->actingAs($this->admin)->get('/admin/attendance?date='.$wednesday);
    $before->assertOk();

    $this->actingAs($this->admin)
        ->post('/admin/holidays', ['date' => $wednesday, 'name' => 'A day the client added'])
        ->assertRedirect();

    $this->actingAs($this->admin)
        ->get('/admin/attendance?date='.$wednesday)
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $rows = collect($page->toArray()['props']['rows'] ?? []);

            expect($rows)->not->toBeEmpty();

            // Everybody whose own schedule says they work that day now reads Holiday; anybody
            // for whom it was already an off day still reads Off day, which is the ordering
            // rule stated on live data. Nobody reads Absent, and no attendance row was written
            // to make any of it true.
            $office = $rows->where('employee.tracking_mode', 'office_attendance');

            expect($office)->not->toBeEmpty()
                ->and($office->where('scheduled', true))->not->toBeEmpty()
                ->and($office->where('scheduled', true)->pluck('status')->unique()->all())->toBe(['holiday'])
                ->and($office->where('scheduled', false)->pluck('status')->unique()->all())
                ->toBeIn([[], ['off_day']])
                // The name rides along on every row, whichever word the status got.
                ->and($office->pluck('holiday_name')->unique()->all())->toBe(['A day the client added']);
        });
});

it('keeps the seeder out of the way of an Admin who has already edited the year', function (): void {
    // The launcher runs `db:seed` on every start. An Admin who corrected a lunar date in the
    // morning must not find the estimate back in the afternoon.
    $eid = Holiday::where('name', 'Eid ul-Fitr')->firstOrFail();

    $this->actingAs($this->admin)->put('/admin/holidays/'.$eid->id, [
        'date' => $eid->date->copy()->addDay()->toDateString(),
        'name' => 'Eid ul-Fitr',
    ])->assertRedirect();

    $corrected = $eid->fresh()->date->toDateString();

    $this->seed(HolidaySeeder::class);

    expect(Holiday::whereKey($eid->id)->firstOrFail()->date->toDateString())->toBe($corrected)
        ->and(Holiday::where('name', 'Eid ul-Fitr')->count())->toBe(1);
});
