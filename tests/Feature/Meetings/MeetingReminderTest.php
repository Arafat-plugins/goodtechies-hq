<?php

use App\Models\Meeting;
use App\Models\Notification;
use App\Models\User;
use App\Services\MeetingService;
use App\Support\NotificationTab;
use App\Support\NotificationType;
use App\Support\UserStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| hq:remind-meetings — Part D §12, "reminder 15 min before"
|--------------------------------------------------------------------------
|
| Two rules, and the second is the one with teeth.
|
|   - The window stays a QUERY. Nothing here writes a flag on a meeting, so
|     "is a reminder due?" is right at every minute of every day and there
|     is no column to drift.
|   - **Exactly once per meeting, however often the scheduler runs.** The
|     memory is the notifications table itself — the same memory
|     `hq:flag-overdue` and `hq:notify-due-tomorrow` use — which is why the
|     central test here runs the command THREE times across the fifteen
|     minutes and still finds one row per person.
|
| The other three cases the spec implies: not for a cancelled meeting, not
| for one that has already started, and not before the window opens.
|
| Helpers here are prefixed MEETING_ (AGENTS.md); MEETING_rowsFor() is
| defined in MeetingServiceTest and is global across the suite, so this file
| uses its own reminder-shaped one rather than redeclaring it.
|
*/

beforeEach(function () {
    $this->seed();

    $this->meetings = app(MeetingService::class);

    $this->shahadat = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    Meeting::query()->delete();
    Notification::query()->delete();
});

/**
 * Every reminder row about one meeting, whoever it went to.
 *
 * @return Collection<int, Notification>
 */
function MEETING_remindersFor(Meeting $meeting)
{
    return Notification::query()
        ->where('type', NotificationType::MeetingReminder->value)
        ->where('group_key', sprintf(
            '%s:%s:%d',
            NotificationType::MeetingReminder->value,
            $meeting->getMorphClass(),
            $meeting->getKey(),
        ))
        ->get();
}

/**
 * A meeting starting `$minutes` from now, with Tapu and Yaseen in the room.
 */
function MEETING_startingIn(int $minutes): Meeting
{
    $start = Carbon::now()->addMinutes($minutes);

    return test()->meetings->schedule(
        test()->shahadat,
        [
            'title' => sprintf('Starts in %d minutes', $minutes),
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes(30),
        ],
        [test()->tapu->id, test()->yaseen->id],
    );
}

it('reminds the participants and the organizer fifteen minutes before', function () {
    $meeting = MEETING_startingIn(14);
    Notification::query()->delete();

    $this->artisan('hq:remind-meetings')->assertSuccessful();

    $rows = MEETING_remindersFor($meeting);

    // Part D §12 wants "participants and the organizer" — and the organiser is here only
    // because the event carries no actor. Every other meeting type drops them.
    expect($rows->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->shahadat->id, $this->tapu->id, $this->yaseen->id])->sort()->values()->all())
        ->and($rows->pluck('count')->unique()->all())->toBe([1]);
});

it('sends exactly one reminder per person however many times the scheduler runs', function () {
    // Twelve minutes out: inside the window for the whole of this test.
    $meeting = MEETING_startingIn(12);
    Notification::query()->delete();

    // Three runs spread across the window, the way `everyMinute()` really runs it. The middle
    // one is the interesting case: it is a fresh minute, the meeting is still due, and the
    // only thing standing between it and a second row is the notifications table.
    $this->artisan('hq:remind-meetings')->assertSuccessful();

    Carbon::setTestNow(Carbon::now()->addMinutes(4));
    $this->artisan('hq:remind-meetings')->assertSuccessful();

    Carbon::setTestNow(Carbon::now()->addMinutes(5));
    $this->artisan('hq:remind-meetings')->assertSuccessful();

    $rows = MEETING_remindersFor($meeting);

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->shahadat->id, $this->tapu->id, $this->yaseen->id])->sort()->values()->all())
        // One row each, and `count` still 1 — a second send inside the dedup window would have
        // GROWN the row rather than adding one, so counting rows alone could not have caught it.
        ->and($rows->pluck('count')->all())->toBe([1, 1, 1]);

    Carbon::setTestNow();
});

it('does not remind anybody about a meeting that has been cancelled', function () {
    $meeting = MEETING_startingIn(10);
    $this->meetings->cancel($this->shahadat, $meeting);
    Notification::query()->delete();

    $this->artisan('hq:remind-meetings')->assertSuccessful();

    expect(MEETING_remindersFor($meeting))->toHaveCount(0);
});

it('does not remind anybody about a meeting that has already started', function () {
    $start = Carbon::now()->subMinutes(5);

    $meeting = $this->meetings->schedule($this->shahadat, [
        'title' => 'Already under way',
        'start_at' => $start,
        'end_at' => $start->copy()->addMinutes(30),
    ], [$this->tapu->id]);

    Notification::query()->delete();

    $this->artisan('hq:remind-meetings')->assertSuccessful();

    expect(MEETING_remindersFor($meeting))->toHaveCount(0);
});

it('does not remind anybody before the window opens', function () {
    $meeting = MEETING_startingIn(45);
    Notification::query()->delete();

    $this->artisan('hq:remind-meetings')->assertSuccessful();

    expect(MEETING_remindersFor($meeting))->toHaveCount(0);

    // …and does once the clock reaches it, with no state having changed on the meeting in
    // between: the window is a query, not a flag.
    Carbon::setTestNow(Carbon::now()->addMinutes(35));
    $this->artisan('hq:remind-meetings')->assertSuccessful();

    expect(MEETING_remindersFor($meeting))->toHaveCount(3);

    Carbon::setTestNow();
});

it('reminds each of several due meetings exactly once in one run', function () {
    $first = MEETING_startingIn(5);
    $second = MEETING_startingIn(11);
    $tooFar = MEETING_startingIn(60);
    Notification::query()->delete();

    $this->artisan('hq:remind-meetings')->assertSuccessful();
    $this->artisan('hq:remind-meetings')->assertSuccessful();

    expect(MEETING_remindersFor($first))->toHaveCount(3)
        ->and(MEETING_remindersFor($second))->toHaveCount(3)
        ->and(MEETING_remindersFor($tooFar))->toHaveCount(0);
});

it('does not remind somebody who has lost the meetings key since being invited', function () {
    $meeting = MEETING_startingIn(9);
    Notification::query()->delete();

    // Deactivated between the invitation and the reminder. The participant row is untouched —
    // nothing in the application removes it — so the per-object gate is the only thing that
    // catches this.
    $this->tapu->forceFill(['status' => UserStatus::Inactive])->save();

    $this->artisan('hq:remind-meetings')->assertSuccessful();

    expect(MEETING_remindersFor($meeting)->pluck('user_id')->all())
        ->not->toContain($this->tapu->id);
});

it('puts the reminder on the Meetings tab of the Notification Center', function () {
    $meeting = MEETING_startingIn(8);
    Notification::query()->delete();

    $this->artisan('hq:remind-meetings')->assertSuccessful();

    $row = MEETING_remindersFor($meeting)->firstOrFail();

    expect($row->type)->toBe(NotificationType::MeetingReminder)
        ->and(NotificationType::MeetingReminder->tab())->toBe(NotificationTab::Meetings)
        // The sentence carries the time, because "when" is the first thing a reader wants from
        // a reminder and a bell row that makes them open the meeting to find out has failed.
        ->and($row->type->summary($row->payload, (int) $row->count))
        ->toContain($meeting->start_at->timezone(config('app.timezone'))->isoFormat('h:mm a'));
});

it('stores no reminder flag on the meeting itself', function () {
    $meeting = MEETING_startingIn(7);
    Notification::query()->delete();

    $before = $meeting->fresh()->updated_at;
    $this->artisan('hq:remind-meetings')->assertSuccessful();

    // The command only sends. If it had written a column, this would have moved.
    expect($meeting->fresh()->updated_at->equalTo($before))->toBeTrue();
});
