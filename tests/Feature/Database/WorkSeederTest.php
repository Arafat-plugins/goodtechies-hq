<?php

use App\Models\AttendanceRecord;
use App\Models\DailyWorkSummary;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\TimeEntry;
use App\Services\AttendanceService;
use App\Support\AttendanceStatus;
use App\Support\TimeEntryType;
use App\Support\TrackingMode;
use Database\Seeders\WorkSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| WorkSeeder — the recorded work the demo database never had
|--------------------------------------------------------------------------
|
| Phase 4's screens shipped with no seeded attendance and no seeded time
| entries at all, so nobody — the client included — had ever seen Attendance,
| Time, the Timesheet, Workload or either dashboard's time card with data on
| them, and Phase 10's Attendance and Time reports opened empty.
|
| What is asserted here is the three things that could go quietly wrong:
|
|   1. the two tables stay apart and `tracking_mode` decides who gets which —
|      a remote-timer employee has NO attendance rows (decision 4-11);
|   2. no day is invented: never an off day, never a holiday, never a day
|      inside an approved leave window;
|   3. re-running `db:seed` changes nothing, keyed on identity and not on a
|      sentence — the trap FinanceSeeder fell into.
|
| Constants and helpers in a Pest file are GLOBAL, so everything here is
| prefixed WORK_ / workSeeder…().
|
*/

beforeEach(function (): void {
    $this->seed();
});

/** The window WorkSeeder covers: the start of last month to today. */
function workSeederWindow(): array
{
    $today = Carbon::today(config('app.timezone'))->startOfDay();

    return [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today];
}

function workSeederEmployee(string $number): Employee
{
    return Employee::where('employee_number', $number)->firstOrFail();
}

/**
 * @return array<string, int>
 */
function workSeederCounts(): array
{
    return [
        'attendance' => AttendanceRecord::count(),
        'time_entries' => TimeEntry::count(),
        'approved' => TimeEntry::query()->counted()->count(),
        'pending' => TimeEntry::query()->awaitingDecision()->count(),
        'tracked_seconds' => (int) DB::table('tasks')->sum('tracked_seconds'),
        'attendance_minutes' => (int) AttendanceRecord::query()
            ->whereNotNull('clock_out')
            ->sum(DB::raw('extract(epoch from (clock_out - clock_in)) / 60')),
    ];
}

// -------------------------------------------------------------------------
// The two tables stay apart, and tracking_mode decides
// -------------------------------------------------------------------------

it('seeds attendance for the office clock and tracked time for the timer, never both', function (): void {
    foreach (Employee::with('user')->get() as $employee) {
        $attendance = AttendanceRecord::where('employee_id', $employee->getKey())->count();
        $entries = TimeEntry::where('employee_id', $employee->getKey())->count();

        match ($employee->tracking_mode) {
            TrackingMode::OfficeAttendance => expect($attendance)->toBeGreaterThan(0)
                ->and($entries)->toBe(0),
            // Decision 4-11: a remote-timer employee has no attendance rows AT ALL.
            TrackingMode::RemoteTimer => expect($attendance)->toBe(0)
                ->and($entries)->toBeGreaterThan(0),
            // The Accountant has no schedule and no tracking mode: neither table knows them.
            default => expect($attendance)->toBe(0)->and($entries)->toBe(0),
        };
    }
})->group('phase10');

it('gives Tapu tracked time and gives him no attendance row on any day', function (): void {
    $tapu = workSeederEmployee('GT-003');

    expect($tapu->tracking_mode)->toBe(TrackingMode::RemoteTimer)
        ->and(AttendanceRecord::where('employee_id', $tapu->getKey())->count())->toBe(0)
        ->and(TimeEntry::where('employee_id', $tapu->getKey())->count())->toBeGreaterThan(20);
})->group('phase10');

// -------------------------------------------------------------------------
// No day is invented
// -------------------------------------------------------------------------

it('never seeds a day the employee does not work', function (): void {
    $attendance = app(AttendanceService::class);

    foreach (AttendanceRecord::with('employee.schedule')->get() as $record) {
        expect($attendance->isWorkingDay($record->employee->schedule, $record->date))
            ->toBeTrue("Seeded {$record->date->toDateString()} for employee {$record->employee_id}, which their schedule says is an off day.");
    }
})->group('phase10');

it('never seeds a day covered by a holiday', function (): void {
    [$from, $to] = workSeederWindow();

    $holidays = Holiday::query()
        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
        ->pluck('date')
        ->map(fn ($date): string => Carbon::parse($date)->toDateString())
        ->all();

    // The window has to contain at least one, or this test proves nothing.
    expect($holidays)->not->toBeEmpty();

    expect(AttendanceRecord::query()->whereIn('date', $holidays)->count())->toBe(0);
})->group('phase10');

it('never seeds a day inside an approved leave window', function (): void {
    $yaseen = workSeederEmployee('GT-004');
    $attendance = app(AttendanceService::class);

    // Two working days of last month, taken as approved leave AFTER the first seed, then
    // seeded again: the days must come back empty rather than as Present.
    [$from] = workSeederWindow();
    $days = collect(range(0, 27))
        ->map(fn (int $offset): Carbon => $from->copy()->addDays($offset))
        ->filter(fn (Carbon $day): bool => $attendance->isWorkingDay($yaseen->schedule, $day)
            && ! $attendance->coveredByLeaveOrHoliday($yaseen, $day))
        ->values();

    $window = [$days[1], $days[2]];

    AttendanceRecord::query()->where('employee_id', $yaseen->getKey())->delete();

    LeaveRequest::factory()
        ->forEmployee($yaseen)
        ->ofType(LeaveType::where('name', 'Annual')->firstOrFail())
        ->between($window[0], $window[1])
        ->approved()
        ->create();

    $this->seed(WorkSeeder::class);

    expect(AttendanceRecord::query()->where('employee_id', $yaseen->getKey())->count())->toBeGreaterThan(0)
        ->and(AttendanceRecord::query()
            ->where('employee_id', $yaseen->getKey())
            ->whereIn('date', [$window[0]->toDateString(), $window[1]->toDateString()])
            ->count())->toBe(0);
})->group('phase10');

it('never seeds a day after today, and never a clock-out that has not happened', function (): void {
    [, $today] = workSeederWindow();

    expect(AttendanceRecord::query()->where('date', '>', $today->toDateString())->count())->toBe(0)
        ->and(AttendanceRecord::query()->where('clock_out', '>', Carbon::now())->count())->toBe(0)
        ->and(TimeEntry::query()->where('ended_at', '>', Carbon::now())->count())->toBe(0);
})->group('phase10');

// -------------------------------------------------------------------------
// The statuses the reports exist to tell apart
// -------------------------------------------------------------------------

it('seeds Present, Late, Absent and Half day inside the current month', function (AttendanceStatus $status): void {
    $month = Carbon::today(config('app.timezone'));

    expect(AttendanceRecord::query()
        ->where('status', $status->value)
        ->whereBetween('date', [$month->copy()->startOfMonth()->toDateString(), $month->toDateString()])
        ->count())->toBeGreaterThan(0);
})->with([
    [AttendanceStatus::Present],
    [AttendanceStatus::Late],
    [AttendanceStatus::Absent],
    [AttendanceStatus::HalfDay],
])->group('phase10');

it('derives Late from the schedule rather than asserting it', function (): void {
    $attendance = app(AttendanceService::class);

    foreach (AttendanceRecord::with('employee.schedule')->whereNotNull('clock_in')->get() as $record) {
        $late = $attendance->isLate($record->employee->schedule, $record->clock_in);

        // A Half day is an Admin's word and may sit on top of either, so it is not asked here.
        if ($record->status === AttendanceStatus::HalfDay) {
            continue;
        }

        expect($record->status)->toBe($late ? AttendanceStatus::Late : AttendanceStatus::Present);
    }
})->group('phase10');

it('writes an Absent day the way the sweep does and a Half day the way an Admin does', function (): void {
    $absent = AttendanceRecord::where('status', AttendanceStatus::Absent->value)->firstOrFail();

    expect($absent->clock_in)->toBeNull()
        ->and($absent->clock_out)->toBeNull()
        ->and($absent->edited_by)->toBeNull();

    $half = AttendanceRecord::where('status', AttendanceStatus::HalfDay->value)->firstOrFail();

    expect($half->clock_in)->not->toBeNull()
        ->and($half->note)->not->toBeNull()
        ->and($half->edited_by)->not->toBeNull();
})->group('phase10');

// -------------------------------------------------------------------------
// Tracked time: the entry types, and the one predicate every total asks
// -------------------------------------------------------------------------

it('seeds only stopped entries, mostly auto, and every manual one with a reason and an approver', function (): void {
    $entries = TimeEntry::all();

    expect($entries)->not->toBeEmpty()
        ->and($entries->every(fn (TimeEntry $entry): bool => $entry->isStopped()))->toBeTrue()
        ->and($entries->every(fn (TimeEntry $entry): bool => $entry->duration_seconds > 0))->toBeTrue();

    $manual = $entries->where('entry_type', TimeEntryType::Manual);
    $auto = $entries->where('entry_type', TimeEntryType::Auto);

    expect($manual)->not->toBeEmpty()
        ->and($auto->count())->toBeGreaterThan($manual->count());

    foreach ($manual as $entry) {
        expect($entry->reason)->not->toBeNull()
            ->and(trim((string) $entry->reason))->not->toBe('')
            ->and($entry->approved_by)->not->toBeNull()
            ->and($entry->last_heartbeat_at)->toBeNull();
    }
})->group('phase10');

it('seeds both approved and pending time, so the waiting-for-a-sign-off path renders', function (): void {
    expect(TimeEntry::query()->counted()->count())->toBeGreaterThan(0)
        ->and(TimeEntry::query()->awaitingDecision()->count())->toBeGreaterThan(0)
        // A rejection is somebody's judgement about somebody's hours. The seed makes none.
        ->and(TimeEntry::query()->rejected()->count())->toBe(0);
})->group('phase10');

it('puts the pending minutes in the view beside the tracked ones, never inside them', function (): void {
    $tapu = workSeederEmployee('GT-003');

    $pendingDay = TimeEntry::query()
        ->where('employee_id', $tapu->getKey())
        ->awaitingDecision()
        ->orderByDesc('work_date')
        ->firstOrFail();

    $summary = DailyWorkSummary::query()
        ->where('employee_id', $tapu->getKey())
        ->where('work_date', $pendingDay->work_date->toDateString())
        ->firstOrFail();

    $approved = (int) TimeEntry::query()
        ->where('employee_id', $tapu->getKey())
        ->whereDate('work_date', $pendingDay->work_date->toDateString())
        ->counted()
        ->sum('duration_seconds');

    expect((int) $summary->pending_minutes)->toBeGreaterThan(0)
        ->and((int) $summary->tracked_minutes)->toBe(intdiv($approved, 60))
        ->and((int) $summary->tracked_minutes)->toBeGreaterThan(0);
})->group('phase10');

it('leaves tasks.tracked_seconds equal to the counted sum on every task it touched', function (): void {
    $taskIds = TimeEntry::query()->distinct()->pluck('task_id');

    expect($taskIds)->not->toBeEmpty();

    foreach ($taskIds as $taskId) {
        $counted = (int) TimeEntry::query()
            ->where('task_id', $taskId)
            ->stopped()
            ->counted()
            ->sum('duration_seconds');

        expect((int) DB::table('tasks')->where('id', $taskId)->value('tracked_seconds'))->toBe($counted);
    }
})->group('phase10');

// -------------------------------------------------------------------------
// Idempotence, keyed on identity
// -------------------------------------------------------------------------

it('changes nothing when the whole seed is run again', function (): void {
    $before = workSeederCounts();

    // The launcher runs db:seed on every start, so this is the case that actually happens.
    $this->seed();

    expect(workSeederCounts())->toBe($before);
})->group('phase10');

it('changes nothing when WorkSeeder alone is run again', function (): void {
    $before = workSeederCounts();

    $this->seed(WorkSeeder::class);

    expect(workSeederCounts())->toBe($before);
})->group('phase10');

it('keys a time entry on its client uuid and not on any sentence', function (): void {
    $uuids = TimeEntry::pluck('client_uuid');

    expect($uuids->unique()->count())->toBe($uuids->count());

    $before = TimeEntry::orderBy('id')->pluck('client_uuid')->all();

    // The trap FinanceSeeder fell into: two rows merged because their NOTES were made equal.
    // Rewriting every reason must not let a re-seed lose or duplicate a row.
    TimeEntry::query()->whereNotNull('reason')->update(['reason' => 'Same sentence on every row.']);

    $this->seed(WorkSeeder::class);

    expect(TimeEntry::orderBy('id')->pluck('client_uuid')->all())->toBe($before);
})->group('phase10');
