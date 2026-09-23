<?php

use App\Models\DailyWorkSummary;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\AttendanceService as Attendance;
use App\Support\AttendanceStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| `daily_work_summary` — the reporting join, and why it is a view
|--------------------------------------------------------------------------
|
| Part C rule 6: "`attendance_records` (office) and `time_entries` (remote)
| are separate tables — never merged into one 'worked hours' table. A
| `daily_work_summary` VIEW joins them for reporting only."
|
| The rule exists because two STORED answers to "how long did they work" can
| disagree, and the day they do, Phase 9 pays one of them. So these tests
| assert three things:
|
|   1. it is a view, not a table, and nothing can write to it;
|   2. it returns BOTH sources correctly — an office day with its clock
|      times and its status, a remote day with its tracked minutes — and
|      keeps them in their own columns;
|   3. its remote figure AGREES with `AttendanceService::trackedMinutes()`,
|      which is the operational path the roster reads. Two answers to one
|      question is the exact bug rule 6 prevents, so it is asserted rather
|      than assumed.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-24 18:00:00');

    $this->seed();

    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail()->employee;
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail()->employee;
    $this->task = Task::factory()->create();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function summaryFor(Employee $employee, string $date): ?DailyWorkSummary
{
    return DailyWorkSummary::query()
        ->forEmployees([(int) $employee->getKey()])
        ->forDate(Carbon::parse($date))
        ->first();
}

/* ================================================================ it is a view, not a table */

it('is a view in the database and not a table', function (): void {
    $kind = DB::selectOne(
        "select table_type from information_schema.tables where table_schema = 'public' and table_name = 'daily_work_summary'"
    );

    expect($kind)->not->toBeNull()
        ->and($kind->table_type)->toBe('VIEW');

    // And nothing has a row of its own to keep in step: the two sources hold every fact, which
    // is what makes a disagreement structurally impossible rather than merely unlikely.
    expect(DB::selectOne(
        "select count(*) as total from information_schema.columns where table_name = 'daily_work_summary' and column_name = 'id'"
    )->total)->toBe(0);
});

it('refuses to be written to, naming the rule rather than failing with SQL', function (): void {
    $row = new DailyWorkSummary;

    expect(fn () => $row->save())->toThrow(LogicException::class, 'Part C rule 6')
        ->and(fn () => $row->delete())->toThrow(LogicException::class, 'Part C rule 6');
});

/* ============================================================ both sources, side by side */

it('returns an office day from attendance_records and a remote day from time_entries', function (): void {
    // The office half: Yaseen clocks in at 08:58 and out at 17:02 — 8h 4m.
    app(AttendanceService::class)->clockIn($this->yaseen, Carbon::parse('2026-09-24 08:58'));
    app(AttendanceService::class)->clockOut($this->yaseen, Carbon::parse('2026-09-24 17:02'));

    // The remote half: Tapu's timer, two counted stretches — 2h 10m.
    TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->create([
        'work_date' => '2026-09-24',
        'started_at' => Carbon::parse('2026-09-24 09:00'),
        'ended_at' => Carbon::parse('2026-09-24 10:30'),
        'duration_seconds' => 5400,
        'approved_at' => Carbon::parse('2026-09-24 10:30'),
    ]);
    TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->create([
        'work_date' => '2026-09-24',
        'started_at' => Carbon::parse('2026-09-24 14:00'),
        'ended_at' => Carbon::parse('2026-09-24 14:40'),
        'duration_seconds' => 2400,
        'approved_at' => Carbon::parse('2026-09-24 14:40'),
    ]);

    $office = summaryFor($this->yaseen, '2026-09-24');
    $remote = summaryFor($this->tapu, '2026-09-24');

    // The office row carries the clock's facts and NOTHING from the timer.
    expect($office)->not->toBeNull()
        ->and($office->source)->toBe('office')
        ->and($office->attendance_status)->toBe(AttendanceStatus::Present->value)
        ->and($office->worked_minutes)->toBe(484)
        ->and($office->clock_in->format('H:i'))->toBe('08:58')
        ->and($office->clock_out->format('H:i'))->toBe('17:02')
        ->and($office->tracked_minutes)->toBeNull()
        ->and($office->entry_count)->toBeNull();

    // The remote row carries the timer's and NOTHING from the clock. Tapu has no attendance
    // record and structurally cannot have one (decision 4-11), which is why his half of this
    // view has to come from the other table rather than from a merged one.
    expect($remote)->not->toBeNull()
        ->and($remote->source)->toBe('remote')
        ->and($remote->tracked_minutes)->toBe(130)
        ->and($remote->entry_count)->toBe(2)
        ->and($remote->attendance_status)->toBeNull()
        ->and($remote->worked_minutes)->toBeNull()
        ->and($remote->clock_in)->toBeNull();
});

it('leaves an open office day without worked minutes, because one end has not happened', function (): void {
    app(AttendanceService::class)->clockIn($this->yaseen, Carbon::parse('2026-09-24 09:05'));

    $row = summaryFor($this->yaseen, '2026-09-24');

    expect($row->attendance_status)->toBe(AttendanceStatus::Present->value)
        ->and($row->clock_in)->not->toBeNull()
        // Null, not zero: it is a difference and the clock-out has not been made yet.
        ->and($row->worked_minutes)->toBeNull();
});

/* ================================================ the one predicate, on both sides of the join */

it('counts only approved entries, and reports the waiting and refused ones separately', function (): void {
    TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->create([
        'work_date' => '2026-09-24',
        'started_at' => Carbon::parse('2026-09-24 09:00'),
        'ended_at' => Carbon::parse('2026-09-24 10:00'),
        'duration_seconds' => 3600,
        'approved_at' => Carbon::parse('2026-09-24 10:00'),
    ]);
    // Waiting: not counted, not hidden.
    TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->manual()->create([
        'work_date' => '2026-09-24',
        'started_at' => Carbon::parse('2026-09-24 11:00'),
        'ended_at' => Carbon::parse('2026-09-24 11:30'),
        'duration_seconds' => 1800,
    ]);
    // Refused: still on the row, still reported, still not counted.
    TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->manual()->rejected()->create([
        'work_date' => '2026-09-24',
        'started_at' => Carbon::parse('2026-09-24 12:00'),
        'ended_at' => Carbon::parse('2026-09-24 12:15'),
        'duration_seconds' => 900,
    ]);
    // Still running: an open entry's length is a question about `now()`, so a report read
    // tomorrow must not have banked it.
    TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->running()->create([
        'work_date' => '2026-09-24',
    ]);

    $row = summaryFor($this->tapu, '2026-09-24');

    expect($row->tracked_minutes)->toBe(60)
        ->and($row->pending_minutes)->toBe(30)
        ->and($row->rejected_minutes)->toBe(15)
        // Three finished entries. The running one is not among them.
        ->and($row->entry_count)->toBe(3);
});

it('agrees with the roster about how long the remote employee worked', function (): void {
    TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->create([
        'work_date' => '2026-09-24',
        'started_at' => Carbon::parse('2026-09-24 09:00'),
        'ended_at' => Carbon::parse('2026-09-24 13:18'),
        'duration_seconds' => 15480,
        'approved_at' => Carbon::parse('2026-09-24 13:18'),
    ]);
    // Waiting hours are in neither figure, and are in neither for the same reason.
    TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->manual()->create([
        'work_date' => '2026-09-24',
        'started_at' => Carbon::parse('2026-09-24 15:00'),
        'ended_at' => Carbon::parse('2026-09-24 16:00'),
        'duration_seconds' => 3600,
    ]);

    $fromTheView = summaryFor($this->tapu, '2026-09-24')->tracked_minutes;
    $fromTheRoster = app(Attendance::class)->trackedMinutes($this->tapu, Carbon::parse('2026-09-24'));

    // 4h 18m — AC2's number, reached twice by two paths that must never disagree.
    expect($fromTheView)->toBe(258)
        ->and($fromTheRoster)->toBe(258)
        ->and($fromTheView)->toBe($fromTheRoster);
});

it('gives a person with neither an attendance row nor an entry no row at all', function (): void {
    expect(summaryFor($this->tapu, '2026-09-24'))->toBeNull()
        ->and(summaryFor($this->yaseen, '2026-09-24'))->toBeNull();
});
