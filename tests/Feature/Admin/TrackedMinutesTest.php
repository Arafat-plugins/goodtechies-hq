<?php

use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The remote seam, wired: "Remote — 4h 18m tracked"
|--------------------------------------------------------------------------
|
| `AttendanceService::trackedMinutes()` returned null for everybody as a
| deliberate seam. Filling it in is the whole of the change — the roster, the
| month grid and every payload shape are untouched, because every surface
| already printed the clause only when the value was not null.
|
| So these tests read the SCREENS, not the method: if the seam was the clean
| one it claimed to be, Tapu's roster row started saying the minutes with no
| edit to the roster at all. They also pin the two things that could quietly
| go wrong:
|
|   - the figure asks `approved_at is not null` and nothing else, so hours
|     waiting for a sign-off are NOT in it (decision 4-7) — the roster must
|     not answer a question the approval queue exists to answer;
|   - a month grid is one query, not one per cell.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-24 18:00:00');

    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail()->employee;
    $this->task = Task::factory()->create();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** A counted stretch of Tapu's day. */
function countedEntry(string $date, string $from, string $to, int $seconds): TimeEntry
{
    return TimeEntry::factory()
        ->forEmployee(test()->tapu)
        ->onTask(test()->task)
        ->create([
            'work_date' => $date,
            'started_at' => Carbon::parse("{$date} {$from}"),
            'ended_at' => Carbon::parse("{$date} {$to}"),
            'duration_seconds' => $seconds,
            'approved_at' => Carbon::parse("{$date} {$to}"),
        ]);
}

/* ================================================================================== the roster */

it('reads "Remote" with the tracked minutes on the Admin roster, from real entries', function (): void {
    countedEntry('2026-09-24', '09:00', '13:18', 15480);

    $this->actingAs($this->admin)
        ->get('/admin/attendance?date=2026-09-24')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $row = collect($page->toArray()['props']['rows'])->firstWhere('employee.name', 'Tapu');

            // The status is still derived from `tracking_mode` and the minutes are still a
            // separate field — the seam changed one value, not a shape.
            expect($row['status'])->toBe('remote')
                ->and($row['status_label'])->toBe('Remote')
                // 4h 18m. AC2's number, on the screen the plan names it for.
                ->and($row['tracked_minutes'])->toBe(258);
        });
});

it('leaves hours that are waiting for approval out of the tracked figure', function (): void {
    countedEntry('2026-09-24', '09:00', '10:00', 3600);

    TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->manual()->create([
        'work_date' => '2026-09-24',
        'started_at' => Carbon::parse('2026-09-24 11:00'),
        'ended_at' => Carbon::parse('2026-09-24 14:00'),
        'duration_seconds' => 10800,
    ]);

    $minutes = app(AttendanceService::class)->trackedMinutes($this->tapu, Carbon::parse('2026-09-24'));

    // 60, not 240. The roster reports what has been agreed; what is waiting is the approval
    // queue's business and is reported there in words (decision 4-7).
    expect($minutes)->toBe(60);
});

it('says 0m on a day the timer never ran, which is a measurement and not a blank', function (): void {
    $minutes = app(AttendanceService::class)->trackedMinutes($this->tapu, Carbon::parse('2026-09-24'));

    expect($minutes)->toBe(0)->and($minutes)->not->toBeNull();
});

it('says nothing about tracked minutes for somebody the timer does not track', function (): void {
    $yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail()->employee;

    $day = app(AttendanceService::class)->dayFor($yaseen, Carbon::parse('2026-09-24'));

    // Null here is `dayFor()`'s guard, not the method's answer: an office employee's day is not
    // measured by a timer at all, so there is nothing to print rather than a zero that would
    // read as "tracked nothing".
    expect($day->trackedMinutes)->toBeNull();
});

/* ================================================================================= the cost */

it('reads a whole month of tracked minutes in one query, not one per cell', function (): void {
    foreach (['2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22'] as $date) {
        countedEntry($date, '09:00', '11:00', 7200);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    $month = app(AttendanceService::class)->month($this->tapu, Carbon::parse('2026-09-15'));

    $timerQueries = collect(DB::getRawQueryLog())
        ->filter(fn (array $entry): bool => str_contains((string) $entry['raw_query'], 'time_entries'))
        ->count();

    DB::disableQueryLog();

    expect($month)->toHaveCount(30)
        // One grouped query for the month, primed before the loop. Thirty cells asking one at a
        // time would have been thirty, and every one of them would have been correct — which is
        // what makes this worth a test rather than a comment.
        ->and($timerQueries)->toBe(1)
        ->and($month->firstWhere('date.day', 1)?->trackedMinutes ?? $month[0]->trackedMinutes)->toBe(120);
});

it('reads the whole roster\'s tracked minutes in one query', function (): void {
    countedEntry('2026-09-24', '09:00', '11:00', 7200);

    DB::enableQueryLog();
    DB::flushQueryLog();

    app(AttendanceService::class)->roster($this->admin, Carbon::parse('2026-09-24'));

    $timerQueries = collect(DB::getRawQueryLog())
        ->filter(fn (array $entry): bool => str_contains((string) $entry['raw_query'], 'time_entries'))
        ->count();

    DB::disableQueryLog();

    expect($timerQueries)->toBe(1);
});

/* ========================================================== the Company dashboard's cards */

it('puts Present today, Absent and the remote time on the Company dashboard, with On leave still a placeholder', function (): void {
    app(AttendanceService::class)->clockIn(
        User::where('email', 'yaseen@goodtechies.test')->firstOrFail()->employee,
        Carbon::parse('2026-09-24 08:58'),
    );

    countedEntry('2026-09-24', '09:00', '13:18', 15480);

    // Waiting hours: named beside the figure, never inside it.
    TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->manual()->create([
        'work_date' => '2026-09-24',
        'started_at' => Carbon::parse('2026-09-24 15:00'),
        'ended_at' => Carbon::parse('2026-09-24 15:45'),
        'duration_seconds' => 2700,
    ]);

    $this->actingAs($this->admin)
        ->get('/admin/dashboard')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $attendance = $page->toArray()['props']['attendance'];
            $tapu = collect($attendance['remote'])->firstWhere('name', 'Tapu');

            expect($attendance['present'])->toBe(1)
                ->and($attendance['absent'])->toBe(0)
                // The cards lead somewhere: a number an Admin cannot act on should not be on a
                // dashboard.
                ->and($attendance['href'])->toBe('/admin/attendance')
                // AC2 — "Tapu 4h 18m / 5h". Two durations, and nothing divides one by the other.
                ->and($tapu['tracked_minutes'])->toBe(258)
                ->and($tapu['target_minutes'])->toBe(300)
                ->and($tapu['pending_minutes'])->toBe(45);
        });
});

it('keeps the attendance block out of the payload for somebody who may not manage attendance', function (): void {
    $tapu = User::whereKey($this->tapu->user_id)->firstOrFail();

    // A remote employee never reaches the Admin dashboard at all — `surface:admin` refuses
    // them first — which is the honest way to assert this half: the block is Admin-surface, and
    // the emptiness is a property of the controller's gate rather than of a role name.
    $this->actingAs($tapu)->get('/admin/dashboard')->assertForbidden();
});
