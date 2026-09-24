<?php

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Schedule;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\HolidayService;
use App\Support\AttendanceStatus;
use App\Support\RoleName;
use App\Support\TrackingMode;
use Database\Seeders\HolidaySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Company holidays — the table, the derivation and the absent sweep
|--------------------------------------------------------------------------
|
| Three claims are asserted here rather than argued.
|
| **1. A holiday is DERIVED, not stored.** Nothing writes
| `AttendanceStatus::Holiday` into `attendance_records`, the CHECK constraint
| refuses one if anything tries, and adding or deleting a holiday changes
| what a month grid says about a day that has ALREADY HAPPENED. That last
| one is the whole point of decision 4-9 and is the test that would fail if
| somebody ever "optimised" this into a stamped column.
|
| **2. Off Day still wins.** A holiday landing on somebody's own non-working
| day reads Off day, and a holiday reads above Remote. Both directions are
| asserted, because either one alone passes a wrong ordering.
|
| **3. `hq:mark-absent` leaves a holiday alone, proved with a real row** —
| a `holidays` record and the real service, never a faked method.
|
| 2026-09-14 is a Monday and 2026-09-18 a Friday; both are in the past, and
| the sweep refuses a day that has not happened. The seeded schedules work
| Sunday to Thursday, but no test below assumes that — each says what its
| employee's schedule is.
|
*/

const HOLIDAY_MONDAY = '2026-09-14';
const HOLIDAY_TUESDAY = '2026-09-15';
const HOLIDAY_FRIDAY = '2026-09-18';

function holidayEmployee(array $days = ['sun', 'mon', 'tue', 'wed', 'thu'], TrackingMode $mode = TrackingMode::OfficeAttendance): Employee
{
    $employee = Employee::factory()
        ->forRole($mode === TrackingMode::RemoteTimer ? RoleName::REMOTE_EMPLOYEE : RoleName::EMPLOYEE)
        ->create(['tracking_mode' => $mode]);

    Schedule::updateOrCreate(['employee_id' => $employee->id], [
        'working_days' => $days,
        'working_hours_per_day' => '8.00',
        'start_time' => '09:00',
        'office_or_remote' => 'office',
    ]);

    return $employee->fresh(['schedule']);
}

function aHoliday(string $date, string $name = 'Victory Day'): Holiday
{
    return Holiday::factory()->on(Carbon::parse($date))->named($name)->create();
}

/* ============================================================ the derivation */

it('derives Holiday on a working day that is on the calendar, and names it', function (): void {
    $employee = holidayEmployee();
    aHoliday(HOLIDAY_MONDAY, 'Eid ul-Fitr');

    $day = app(AttendanceService::class)->dayFor($employee, Carbon::parse(HOLIDAY_MONDAY));

    expect($day->status)->toBe(AttendanceStatus::Holiday)
        ->and($day->holidayName)->toBe('Eid ul-Fitr')
        // The name travels in the payload beside the word, never instead of it: colour and a
        // bare label are not a status (DESIGN.md §6 rule 6).
        ->and($day->toArray()['status_label'])->toBe('Holiday')
        ->and($day->toArray()['holiday_name'])->toBe('Eid ul-Fitr')
        ->and($day->scheduled)->toBeTrue();
});

it('lets Off Day win where the two overlap, and still names the holiday', function (): void {
    // Friday is not a working day for this employee, and it is a company holiday.
    $employee = holidayEmployee(['sun', 'mon', 'tue', 'wed', 'thu']);
    aHoliday(HOLIDAY_FRIDAY, 'Eid ul-Adha');

    $day = app(AttendanceService::class)->dayFor($employee, Carbon::parse(HOLIDAY_FRIDAY));

    // A public holiday landing on somebody's own day off is not news: the more specific
    // statement about THIS person's week wins.
    expect($day->status)->toBe(AttendanceStatus::OffDay)
        // But the cell can still say why the office was shut, which is the reason the name is
        // carried independently of the status.
        ->and($day->holidayName)->toBe('Eid ul-Adha');
});

it('lets Holiday win over Remote, because the calendar is the company\'s', function (): void {
    $tapu = holidayEmployee(mode: TrackingMode::RemoteTimer);
    aHoliday(HOLIDAY_MONDAY);

    $service = app(AttendanceService::class);

    // On the holiday: Holiday, not Remote. The same argument that puts Off Day above Remote —
    // the calendar applies to everybody however their work is tracked.
    expect($service->dayFor($tapu, Carbon::parse(HOLIDAY_MONDAY))->status)
        ->toBe(AttendanceStatus::Holiday);

    // The day after, with no holiday on it: Remote, exactly as before. Without this the test
    // above would pass on a build that had simply broken Remote.
    expect($service->dayFor($tapu, Carbon::parse(HOLIDAY_TUESDAY))->status)
        ->toBe(AttendanceStatus::Remote);
});

it('lets a record win over a holiday: somebody who clocked in was present', function (): void {
    $employee = holidayEmployee();
    aHoliday(HOLIDAY_MONDAY, 'Ashura');

    app(AttendanceService::class)->clockIn($employee, Carbon::parse(HOLIDAY_MONDAY)->setTime(8, 58));

    $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
    $day = app(AttendanceService::class)->dayFor($employee, Carbon::parse(HOLIDAY_MONDAY), $record);

    // Rule 1 of dayFor(): nothing derived overrules a row that says what happened.
    expect($day->status)->toBe(AttendanceStatus::Present)
        // And the day still says which holiday they worked through.
        ->and($day->holidayName)->toBe('Ashura');
});

/* ================================================ derived, and never stored */

it('changes what a PAST day reads when a holiday is added and again when it is removed', function (): void {
    // The claim decision 4-9 exists for. A stamped column could not do this.
    $employee = holidayEmployee();
    $service = app(AttendanceService::class);
    $monday = Carbon::parse(HOLIDAY_MONDAY);

    expect($service->dayFor($employee, $monday)->status)->toBeNull();

    $holiday = aHoliday(HOLIDAY_MONDAY, 'Victory Day');

    // A brand-new service instance, because the cache inside one is request-scoped and this is
    // standing in for the next request.
    expect(app()->make(AttendanceService::class)->dayFor($employee, $monday)->status)
        ->toBe(AttendanceStatus::Holiday);

    $holiday->delete();

    expect(app()->make(AttendanceService::class)->dayFor($employee, $monday)->status)->toBeNull();
});

it('refuses to store a Holiday attendance row at all', function (): void {
    $employee = holidayEmployee();

    // Not a service rule and not a policy — the CHECK constraint. No hand, including this one
    // going straight to the database, can put a derived status on a row (decision 4-9).
    expect(fn () => DB::table('attendance_records')->insert([
        'employee_id' => $employee->id,
        'date' => HOLIDAY_MONDAY,
        'status' => AttendanceStatus::Holiday->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(AttendanceStatus::Holiday->isStorable())->toBeFalse()
        ->and(AttendanceStatus::storableValues())->not->toContain('holiday')
        // Leave did NOT move: an approval writes one, because it records a decision about a
        // person rather than a fact derived from a shared calendar.
        ->and(AttendanceStatus::Leave->isStorable())->toBeTrue();
});

/* ============================================================= the 23:55 sweep */

it('does not mark anybody absent on a holiday, and marks them the day after', function (): void {
    $employee = holidayEmployee();
    aHoliday(HOLIDAY_MONDAY, 'Shaheed Day');

    // A real `holidays` row and the real service. Nothing is mocked.
    $this->artisan('hq:mark-absent', ['--as-of' => HOLIDAY_MONDAY])
        ->expectsOutputToContain('0 marked absent')
        ->assertSuccessful();

    expect(AttendanceRecord::where('employee_id', $employee->id)->exists())->toBeFalse();

    // The next working day, with no holiday on it, is swept as usual — so the test above is
    // about the holiday and not about a sweep that stopped working.
    $this->artisan('hq:mark-absent', ['--as-of' => HOLIDAY_TUESDAY])
        ->expectsOutputToContain('1 marked absent')
        ->assertSuccessful();

    expect(AttendanceRecord::where('employee_id', $employee->id)->pluck('date')
        ->map(fn ($date) => Carbon::parse($date)->toDateString())->all())
        ->toBe([HOLIDAY_TUESDAY]);
});

it('asks coveredByLeaveOrHoliday, which answers true on a holiday', function (): void {
    $employee = holidayEmployee();
    aHoliday(HOLIDAY_MONDAY);

    $service = app(AttendanceService::class);

    expect($service->coveredByLeaveOrHoliday($employee, Carbon::parse(HOLIDAY_MONDAY)))->toBeTrue()
        ->and($service->coveredByLeaveOrHoliday($employee, Carbon::parse(HOLIDAY_TUESDAY)))->toBeFalse();
});

/* ==================================================================== cost */

it('reads a month of holidays in one query, not one per cell', function (): void {
    $employee = holidayEmployee();
    aHoliday('2026-09-04', 'Janmashtami');
    aHoliday(HOLIDAY_MONDAY, 'Victory Day');

    $service = app()->make(AttendanceService::class);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $month = $service->month($employee, Carbon::parse(HOLIDAY_MONDAY));

    $holidayQueries = collect(DB::getRawQueryLog())
        ->filter(fn (array $entry): bool => str_contains((string) $entry['raw_query'], 'from "holidays"'))
        ->count();

    DB::disableQueryLog();

    // One for the whole month — the misses are cached too, so a quiet Tuesday never goes back
    // to the database to be told it is not a holiday.
    expect($holidayQueries)->toBe(1)
        ->and($month)->toHaveCount(30)
        ->and($month->firstWhere(fn ($day) => $day->date->toDateString() === HOLIDAY_MONDAY)->status)
        ->toBe(AttendanceStatus::Holiday);
});

/* ================================================================ the seeder */

it('seeds the Bangladesh list once and is safe to run again', function (): void {
    // The launcher runs `db:seed` on every start; a seeder that duplicates on the second run
    // breaks the user's machine, and it has.
    $this->seed(HolidaySeeder::class);

    $first = Holiday::count();

    expect($first)->toBeGreaterThan(0);

    $this->seed(HolidaySeeder::class);
    $this->seed(HolidaySeeder::class);

    expect(Holiday::count())->toBe($first);
});

it('does not resurrect a holiday the Admin deleted on purpose', function (): void {
    $this->seed(HolidaySeeder::class);

    $victoryDay = Holiday::where('name', 'Victory Day')->firstOrFail();
    $before = Holiday::count();

    $victoryDay->delete();

    // The next launcher start. A per-row firstOrCreate alone would put it straight back.
    $this->seed(HolidaySeeder::class);

    expect(Holiday::where('name', 'Victory Day')->exists())->toBeFalse()
        ->and(Holiday::count())->toBe($before - 1);
});

it('seeds a whole calendar year, with the fixed-date holidays on their statutory dates', function (): void {
    $this->seed(HolidaySeeder::class);

    $year = (int) Carbon::today(config('app.timezone'))->year;

    expect(Holiday::query()->inYear($year)->count())->toBe(Holiday::count())
        // The six that are fixed by statute. If one of these ever moves, the list was edited
        // rather than corrected.
        ->and(Holiday::where('date', $year.'-02-21')->exists())->toBeTrue()
        ->and(Holiday::where('date', $year.'-03-26')->exists())->toBeTrue()
        ->and(Holiday::where('date', $year.'-04-14')->exists())->toBeTrue()
        ->and(Holiday::where('date', $year.'-05-01')->where('name', 'May Day')->exists())->toBeTrue()
        ->and(Holiday::where('date', $year.'-12-16')->where('name', 'Victory Day')->exists())->toBeTrue()
        ->and(Holiday::where('date', $year.'-12-25')->exists())->toBeTrue();
});

it('allows two observances on one date, which the seeded year actually has', function (): void {
    $this->seed(HolidaySeeder::class);

    $year = (int) Carbon::today(config('app.timezone'))->year;

    // May Day and Buddha Purnima. The table is unique on (date, name), not on date, precisely
    // so `firstOrCreate` cannot silently drop the second of a pair like this.
    expect(Holiday::where('date', $year.'-05-01')->count())->toBe(2);

    // And the same name twice on one day is refused.
    expect(fn () => Holiday::factory()->on(Carbon::parse($year.'-05-01'))->named('May Day')->create())
        ->toThrow(QueryException::class);
});

/* ============================================ the seeded agency, end to end */

it('leaves the whole seeded agency alone on a seeded holiday', function (): void {
    $this->seed();

    // Victory Day, 16 December, fixed by statute — and a Wednesday in 2026, which is a working
    // day in the seeded Sunday-to-Thursday week. Nobody is swept.
    $victoryDay = Holiday::where('name', 'Victory Day')->firstOrFail()->date->toDateString();

    Carbon::setTestNow(Carbon::parse($victoryDay)->setTime(23, 55));

    $this->artisan('hq:mark-absent', ['--as-of' => $victoryDay])
        ->expectsOutputToContain('0 marked absent')
        ->assertSuccessful();

    expect(AttendanceRecord::whereDate('date', $victoryDay)->exists())->toBeFalse();

    $yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail()->employee;

    expect(app()->make(AttendanceService::class)->dayFor($yaseen->fresh(['schedule']), Carbon::parse($victoryDay))->status)
        ->toBe(AttendanceStatus::Holiday);

    Carbon::setTestNow();
});

/* ======================================================= the service's reads */

it('counts today as upcoming, and orders what comes after it', function (): void {
    Carbon::setTestNow('2026-09-14 10:00:00');

    aHoliday(HOLIDAY_MONDAY, 'Today’s holiday');
    aHoliday(HOLIDAY_TUESDAY, 'Tomorrow’s holiday');
    aHoliday('2026-09-01', 'Last fortnight’s holiday');

    $upcoming = app(HolidayService::class)->upcoming();

    // A holiday that is TODAY is the most upcoming one there is — a card that dropped it at
    // midnight would go quiet on the day it is most useful.
    expect($upcoming->pluck('name')->all())->toBe(['Today’s holiday', 'Tomorrow’s holiday']);

    Carbon::setTestNow();
});

it('always offers the current year in the switcher, even with nothing in it', function (): void {
    $service = app(HolidayService::class);
    $current = $service->currentYear();

    expect($service->years())->toBe([$current]);

    aHoliday('2028-01-01', 'A holiday somebody entered early');

    expect(app(HolidayService::class)->years())->toBe([$current, 2028]);
});
