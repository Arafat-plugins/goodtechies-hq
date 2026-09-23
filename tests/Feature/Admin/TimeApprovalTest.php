<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimerService;
use App\Support\AuditEvent;
use App\Support\Permission as PermissionKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| Admin → Workforce → Time: the approval queue
|--------------------------------------------------------------------------
|
| Decision 4-16: `manual_time_requires_approval` is seeded ON, so a manual or
| corrected entry is written with `approved_at` null and counts toward
| nothing — and until this screen there was nowhere to sign one off. That is
| what these tests are about, and the one that matters most reads a total
| BEFORE and AFTER an approval and asserts it moved.
|
| The rules being pinned down:
|
|   - approving sets `approved_at`, and that single predicate is the whole
|     of the change (decision 4-7) — no total learned a new word;
|   - rejecting is NOT deleting: the row, its hours and the employee's own
|     reason all stay, with the refusal's sentence beside them;
|   - both decisions are audit-logged with old and new values, because
|     these hours become somebody's pay in Phase 9;
|   - the queue is ordered by date, never by size or by person;
|   - a refusal is 403 about the requester and 404 about the record.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-25 14:00:00');

    // The full seed, and the seeded Admin: `surface:admin` sits behind the `two-factor`
    // middleware, and only a user with a confirmed second factor gets past it. A factory Admin
    // is redirected to enrolment and every assertion here would have read 302.
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail()->employee;
    $this->task = Task::factory()->create();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** The one question a total asks, asked the way every screen asks it (decision 4-7). */
function countedSecondsFor(Employee $employee, string $date): int
{
    return app(TimerService::class)->countedSecondsOn($employee, Carbon::parse($date));
}

/** A finished manual entry nobody has ruled on — what the queue is full of. */
function pendingEntry(Employee $employee, Task $task, string $date = '2026-09-25', int $seconds = 5400): TimeEntry
{
    return TimeEntry::factory()
        ->forEmployee($employee)
        ->onTask($task)
        ->manual()
        ->create([
            'work_date' => $date,
            'started_at' => Carbon::parse($date)->setTime(9, 0),
            'ended_at' => Carbon::parse($date)->setTime(9, 0)->addSeconds($seconds),
            'duration_seconds' => $seconds,
        ]);
}

/* ================================================== the one that matters: the total moves */

it('makes the hours count: the day total goes up when an Admin approves a pending entry', function (): void {
    $entry = pendingEntry($this->tapu, $this->task, '2026-09-25', 5400);

    // Before. The hours exist, they are on the employee's record, and they count for nothing —
    // which is exactly the state decision 4-16 says would have shipped to GATE C.
    $before = countedSecondsFor($this->tapu, '2026-09-25');

    expect($before)->toBe(0)
        ->and($entry->fresh()->counts())->toBeFalse();

    $this->actingAs($this->admin)
        ->post('/admin/time/entries/'.$entry->id.'/approve')
        ->assertRedirect();

    $after = countedSecondsFor($this->tapu, '2026-09-25');

    expect($after)->toBe(5400)
        ->and($after)->toBeGreaterThan($before)
        ->and($entry->fresh()->counts())->toBeTrue();
});

it('stamps the approval with who did it and when, and clears nothing else on the row', function (): void {
    $entry = pendingEntry($this->tapu, $this->task);

    $this->actingAs($this->admin)->post('/admin/time/entries/'.$entry->id.'/approve');

    $fresh = $entry->fresh();

    expect($fresh->approved_at)->not->toBeNull()
        ->and((int) $fresh->approved_by)->toBe((int) $this->admin->id)
        ->and($fresh->rejected_at)->toBeNull()
        // The employee's own sentence is untouched: the approval is a ruling ON it, not a
        // replacement for it.
        ->and($fresh->reason)->toBe('Forgot to start the timer this morning.')
        ->and($fresh->duration_seconds)->toBe(5400);
});

it('refreshes the task total, which is a cache of the counted entries and not an increment', function (): void {
    $entry = pendingEntry($this->tapu, $this->task, '2026-09-25', 3600);

    expect((int) $this->task->fresh()->tracked_seconds)->toBe(0);

    $this->actingAs($this->admin)->post('/admin/time/entries/'.$entry->id.'/approve');

    expect((int) $this->task->fresh()->tracked_seconds)->toBe(3600);

    // Approving again is a no-op rather than a second 3600: decision 4-8 says the column is
    // recomputed from this table, never added to.
    $this->actingAs($this->admin)->post('/admin/time/entries/'.$entry->id.'/approve');

    expect((int) $this->task->fresh()->tracked_seconds)->toBe(3600);
});

/* ================================================================== rejecting is not deleting */

it('keeps a rejected entry, its hours and its reason, and leaves it out of every total', function (): void {
    $entry = pendingEntry($this->tapu, $this->task, '2026-09-25', 7200);

    $this->actingAs($this->admin)
        ->post('/admin/time/entries/'.$entry->id.'/reject', ['reason' => 'Already claimed on Tuesday.'])
        ->assertRedirect();

    $fresh = $entry->fresh();

    expect($fresh)->not->toBeNull()
        // The row is still there, and so are the hours. A deletion would have destroyed the
        // only evidence of what was claimed.
        ->and($fresh->duration_seconds)->toBe(7200)
        ->and($fresh->reason)->toBe('Forgot to start the timer this morning.')
        ->and($fresh->rejection_reason)->toBe('Already claimed on Tuesday.')
        ->and((int) $fresh->rejected_by)->toBe((int) $this->admin->id)
        // It does not count, and it does not count for the same reason it did not count while
        // it was waiting: `approved_at` is null. No total learned a new word (decision 4-7).
        ->and($fresh->approved_at)->toBeNull()
        ->and($fresh->counts())->toBeFalse()
        ->and($fresh->awaitsApproval())->toBeFalse()
        ->and($fresh->approvalKey())->toBe('rejected');

    expect(countedSecondsFor($this->tapu, '2026-09-25'))->toBe(0);
});

it('shows the refusal and its reason to the employee whose hours they are', function (): void {
    $entry = pendingEntry($this->tapu, $this->task);

    $this->actingAs($this->admin)
        ->post('/admin/time/entries/'.$entry->id.'/reject', ['reason' => 'Already claimed on Tuesday.']);

    $this->actingAs($this->tapu->user)
        ->get('/employee/time')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $row = collect($page->toArray()['props']['days'])
                ->flatMap(fn (array $day): array => $day['entries'])
                ->first();

            expect($row['approval'])->toBe('rejected')
                ->and($row['approval_label'])->toBe('Turned down — not counted')
                ->and($row['rejection_reason'])->toBe('Already claimed on Tuesday.')
                // Their hours are still on their page. That is the difference between a refusal
                // and a deletion, and it is the whole of it.
                ->and($row['duration_seconds'])->toBe(5400)
                ->and($row['counts'])->toBeFalse();
        });
});

it('requires a reason to turn an entry down', function (): void {
    $entry = pendingEntry($this->tapu, $this->task);

    $this->actingAs($this->admin)
        ->from('/admin/time')
        ->post('/admin/time/entries/'.$entry->id.'/reject', ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($entry->fresh()->rejected_at)->toBeNull();
});

it('lets an Admin change their mind, and the refusal goes rather than sitting beside the approval', function (): void {
    $entry = pendingEntry($this->tapu, $this->task, '2026-09-25', 3600);

    $this->actingAs($this->admin)
        ->post('/admin/time/entries/'.$entry->id.'/reject', ['reason' => 'Wrong task.']);

    expect(countedSecondsFor($this->tapu, '2026-09-25'))->toBe(0);

    $this->actingAs($this->admin)->post('/admin/time/entries/'.$entry->id.'/approve');

    $fresh = $entry->fresh();

    // Both timestamps at once is what `time_entries_not_approved_and_rejected` forbids: an
    // entry that counted AND had been turned down is the contradiction decision 4-7 prevents.
    expect($fresh->approved_at)->not->toBeNull()
        ->and($fresh->rejected_at)->toBeNull()
        ->and($fresh->rejection_reason)->toBeNull()
        ->and(countedSecondsFor($this->tapu, '2026-09-25'))->toBe(3600);
});

it('takes hours back out of a total when an already-counted entry is turned down', function (): void {
    $entry = TimeEntry::factory()
        ->forEmployee($this->tapu)
        ->onTask($this->task)
        ->flagged('Stopped automatically: the timer stopped checking in for more than 5 minutes.')
        ->create([
            'work_date' => '2026-09-25',
            'started_at' => Carbon::parse('2026-09-25 09:00'),
            'ended_at' => Carbon::parse('2026-09-25 13:00'),
            'duration_seconds' => 14400,
            'approved_at' => Carbon::parse('2026-09-25 13:00'),
        ]);

    expect(countedSecondsFor($this->tapu, '2026-09-25'))->toBe(14400);

    $this->actingAs($this->admin)
        ->post('/admin/time/entries/'.$entry->id.'/reject', ['reason' => 'The laptop was shut; this is not four hours of work.']);

    expect(countedSecondsFor($this->tapu, '2026-09-25'))->toBe(0)
        ->and($entry->fresh()->duration_seconds)->toBe(14400);
});

/* ============================================================================ the audit trail */

it('writes an audit row for an approval, with the old and new values', function (): void {
    $entry = pendingEntry($this->tapu, $this->task);

    $this->actingAs($this->admin)->post('/admin/time/entries/'.$entry->id.'/approve');

    $log = AuditLog::where('event', AuditEvent::TimeEntryApproved->value)->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and((int) $log->actor_id)->toBe((int) $this->admin->id)
        ->and((int) $log->target_id)->toBe((int) $entry->id)
        ->and($log->old_value['counts'])->toBeFalse()
        ->and($log->new_value['counts'])->toBeTrue()
        ->and($log->old_value['approved_at'])->toBeNull()
        ->and($log->new_value['approved_at'])->not->toBeNull()
        // The number being ruled on rides along: a log entry saying only "approved" leaves the
        // reader to go and find out what for.
        ->and($log->new_value['duration_seconds'])->toBe(5400);
});

it('writes an audit row for a refusal, carrying the reason', function (): void {
    $entry = pendingEntry($this->tapu, $this->task);

    $this->actingAs($this->admin)
        ->post('/admin/time/entries/'.$entry->id.'/reject', ['reason' => 'Already claimed on Tuesday.']);

    $log = AuditLog::where('event', AuditEvent::TimeEntryRejected->value)->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->new_value['rejection_reason'])->toBe('Already claimed on Tuesday.')
        ->and($log->new_value['counts'])->toBeFalse()
        ->and($log->old_value['rejected_at'])->toBeNull();
});

/* ================================================================================= the screen */

it('shows the queue oldest first, with why each entry is waiting in the server\'s words', function (): void {
    $newer = pendingEntry($this->tapu, $this->task, '2026-09-25');
    $older = pendingEntry($this->tapu, $this->task, '2026-09-23');

    $flagged = TimeEntry::factory()
        ->forEmployee($this->tapu)
        ->onTask($this->task)
        ->flagged('Stopped automatically: the timer stopped checking in for more than 5 minutes, so this entry ends at its last check-in, 1:04 pm.')
        ->create([
            'work_date' => '2026-09-24',
            'started_at' => Carbon::parse('2026-09-24 09:00'),
            'ended_at' => Carbon::parse('2026-09-24 13:04'),
            'duration_seconds' => 14640,
            'approved_at' => null,
        ]);

    $this->actingAs($this->admin)
        ->get('/admin/time?date=2026-09-25')
        ->assertOk()
        ->assertInertia(function ($page) use ($older, $flagged, $newer): void {
            $queue = collect($page->toArray()['props']['queue']);

            // Date order, and nothing else. Not by size, not by person: the entry that has been
            // waiting longest is the one holding up a record, and any ordering of people's
            // hours by anything else would be a ranking (Part H §1).
            expect($queue->pluck('id')->all())->toBe([$older->id, $flagged->id, $newer->id])
                ->and($page->toArray()['props']['queue_total'])->toBe(3);

            $manualRow = $queue->firstWhere('id', $older->id);
            $flaggedRow = $queue->firstWhere('id', $flagged->id);

            expect(collect($manualRow['waiting_because'])->pluck('key')->all())->toBe(['manual'])
                ->and($manualRow['approval'])->toBe('pending')
                ->and($manualRow['approval_label'])->toBe('Waiting for approval')
                // The employee's name is on the row: the Admin's first question is whose
                // afternoon this is.
                ->and($manualRow['employee']['name'])->toBe($this->tapu->user->name);

            $why = collect($flaggedRow['waiting_because'])->firstWhere('key', 'flagged');

            // The flag's own sentence, verbatim (decision 4-6). An Admin who cannot read what
            // the safeguard found has no basis on which to approve or refuse.
            expect($why['detail'])->toContain('stopped checking in')
                ->and($why['detail'])->toContain('1:04 pm');
        });
});

it('names an entry that was edited as a reason it is waiting', function (): void {
    Setting::where('key', 'manual_time_requires_approval')->update(['value' => json_encode(true)]);

    $entry = TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->create([
        'work_date' => '2026-09-25',
        'started_at' => Carbon::parse('2026-09-25 09:00'),
        'ended_at' => Carbon::parse('2026-09-25 10:00'),
        'duration_seconds' => 3600,
    ]);

    app(TimerService::class)->edit(
        $entry,
        Carbon::parse('2026-09-25 09:00'),
        Carbon::parse('2026-09-25 09:30'),
        'The timer ran on after I stopped.',
        $this->tapu->user,
    );

    $this->actingAs($this->admin)
        ->get('/admin/time?date=2026-09-25')
        ->assertOk()
        ->assertInertia(function ($page) use ($entry): void {
            $row = collect($page->toArray()['props']['queue'])->firstWhere('id', $entry->id);

            expect($row)->not->toBeNull()
                ->and(collect($row['waiting_because'])->pluck('key')->all())->toContain('edited');
        });
});

it('counts hours today and this week by employee, project and task, with pending beside the total', function (): void {
    // Counted: an hour on Wednesday, half an hour today.
    TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->create([
        'work_date' => '2026-09-23',
        'started_at' => Carbon::parse('2026-09-23 09:00'),
        'ended_at' => Carbon::parse('2026-09-23 10:00'),
        'duration_seconds' => 3600,
        'approved_at' => Carbon::parse('2026-09-23 10:00'),
    ]);
    TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->create([
        'work_date' => '2026-09-25',
        'started_at' => Carbon::parse('2026-09-25 09:00'),
        'ended_at' => Carbon::parse('2026-09-25 09:30'),
        'duration_seconds' => 1800,
        'approved_at' => Carbon::parse('2026-09-25 09:30'),
    ]);
    // Waiting: reported beside the total, never inside it.
    pendingEntry($this->tapu, $this->task, '2026-09-25', 900);

    $this->actingAs($this->admin)
        ->get('/admin/time?date=2026-09-25')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $props = $page->toArray()['props'];

            $employee = collect($props['byEmployee'])->firstWhere('id', $this->tapu->id);
            $project = collect($props['byProject'])->firstWhere('id', $this->task->project_id);
            $task = collect($props['byTask'])->firstWhere('id', $this->task->id);

            foreach ([$employee, $project, $task] as $row) {
                expect($row['today_seconds'])->toBe(1800)
                    ->and($row['week_seconds'])->toBe(5400)
                    ->and($row['pending_week_seconds'])->toBe(900);
            }
        });
});

it('keeps a remote employee on the by-employee list at zero rather than dropping them', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/time?date=2026-09-25')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $row = collect($page->toArray()['props']['byEmployee'])->firstWhere('id', $this->tapu->id);

            // Zero is an answer — "nobody has tracked anything today" is what an Admin opening
            // this at nine in the morning needs to read.
            expect($row)->not->toBeNull()
                ->and($row['today_seconds'])->toBe(0)
                ->and($row['week_seconds'])->toBe(0);
        });
});

/* ======================================================================== who may, and who may not */

it('refuses the Time screen and both decisions to a remote employee, with 403', function (): void {
    $entry = pendingEntry($this->tapu, $this->task);

    foreach ([
        ['get', '/admin/time'],
        ['post', '/admin/time/entries/'.$entry->id.'/approve'],
        ['post', '/admin/time/entries/'.$entry->id.'/reject'],
    ] as [$method, $path]) {
        $this->actingAs($this->tapu->user)->{$method}($path)->assertForbidden();
    }

    expect($entry->fresh()->approved_at)->toBeNull();
});

it('answers 404 for an entry id that does not exist, never a refusal that confirms one does', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/time/entries/999999/approve')
        ->assertNotFound();
});

it('refuses to rule on a session that is still going', function (): void {
    $running = TimeEntry::factory()->forEmployee($this->tapu)->onTask($this->task)->running()->create();

    $this->actingAs($this->admin)
        ->post('/admin/time/entries/'.$running->id.'/approve')
        ->assertForbidden();

    expect($running->fresh()->approved_at)->toBeNull();
});

it('does not let anybody sign off their own hours, however senior', function (): void {
    // Give the timer employee's ROLE the manage permission as well — a combination nobody
    // seeded, and exactly why the rule is coded rather than argued from who happens to hold
    // what today. The day somebody's tracking mode changes is not the day to find out that
    // "nobody approves their own hours" was only true by accident.
    $this->tapu->role->permissions()->syncWithoutDetaching(
        Permission::where('key', PermissionKey::AttendanceManageOthers->value)->pluck('id')->all(),
    );

    $tapu = User::whereKey($this->tapu->user_id)->firstOrFail();
    $own = pendingEntry($this->tapu, $this->task);
    $somebodyElses = pendingEntry(
        User::where('email', 'yaseen@goodtechies.test')->firstOrFail()->employee,
        $this->task,
    );

    expect($tapu->hasPermission(PermissionKey::AttendanceManageOthers))->toBeTrue()
        // They hold the key, and still cannot rule on their own claim …
        ->and(Gate::forUser($tapu)->allows('approve', $own))->toBeFalse()
        // … while the key is what lets them rule on somebody else's, so the refusal above is
        // about ownership and not about a missing permission.
        ->and(Gate::forUser($tapu)->allows('approve', $somebodyElses))->toBeTrue();
});

/* ============================================================================ no score, anywhere */

it('has no score and no productivity figure in the Admin Time sources or payload', function (): void {
    $words = ['score', 'productivity', 'productive', 'efficiency', 'rating', 'ranking'];

    $files = [
        base_path('app/Http/Controllers/Admin/TimeController.php'),
        base_path('resources/js/Pages/Admin/Time/Index.vue'),
        base_path('resources/js/Components/Time/time.ts'),
        base_path('resources/js/Components/Time/TimeEntryRow.vue'),
        base_path('resources/js/Components/Time/HoursBreakdown.vue'),
        base_path('resources/js/Components/Time/RejectEntryDialog.vue'),
    ];

    foreach ($files as $file) {
        // Comments stripped: a docblock explaining why there is no score is not a score, a
        // label would be. Same helper shape as the attendance half's NoScoreTest.
        $body = (string) file_get_contents($file);
        $body = preg_replace('#/\*.*?\*/#s', '', $body) ?? $body;
        $body = preg_replace('#<!--.*?-->#s', '', $body) ?? $body;
        $body = preg_replace('#(^|\s)//[^\n]*#m', '', $body) ?? $body;

        foreach ($words as $word) {
            expect(stripos($body, $word))->toBeFalse(basename($file).' contains "'.$word.'"');
        }
    }

    pendingEntry($this->tapu, $this->task);

    $props = $this->actingAs($this->admin)->get('/admin/time')->assertOk()->viewData('page')['props'];
    $json = json_encode($props, JSON_THROW_ON_ERROR);

    foreach ($words as $word) {
        expect(stripos($json, $word))->toBeFalse('the Admin Time payload contains "'.$word.'"');
    }
});
