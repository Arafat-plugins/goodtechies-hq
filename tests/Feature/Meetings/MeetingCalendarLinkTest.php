<?php

use App\Exceptions\MeetingStateException;
use App\Models\ActivityLog;
use App\Models\Meeting;
use App\Models\User;
use App\Services\Calendar\CalendarLink;
use App\Services\Calendar\CalendarOutcome;
use App\Services\Calendar\ManualLink;
use App\Services\Calendar\MeetLink;
use App\Services\MeetingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The calendar seam — Part D §12, the manual driver
|--------------------------------------------------------------------------
|
| One driver is built. This file therefore does two different jobs, and the
| second is the one that matters in six months:
|
|   1. It asserts what `ManualLink` does: stores a pasted link, produces
|      none of its own, and on cancel records that the Meet has to be called
|      off by hand (Part D §12's counterpart of spec §47).
|   2. It asserts the CONTRACT every driver must satisfy — against the
|      interface, through a fake — so `CalendarApiOneWay` arrives with a
|      test already written for it. Those cases are marked "contract:".
|
| What the second driver will have to do is written out in full in
| `CalendarLink`'s docblock; this file is the executable half of it.
|
| Helpers here are prefixed MEETING_ (AGENTS.md).
|
*/

beforeEach(function () {
    $this->seed();

    $this->meetings = app(MeetingService::class);

    $this->shahadat = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    Meeting::query()->delete();
});

const MEETING_GOOD_LINK = 'https://meet.google.com/qkd-mprv-tza';

/**
 * @param  array<string, mixed>  $overrides
 */
function MEETING_schedule(array $overrides = []): Meeting
{
    $start = Carbon::today()->addDays(3)->setTime(15, 0);

    return test()->meetings->schedule(
        test()->shahadat,
        [
            'title' => 'Client review',
            'start_at' => $start,
            'end_at' => $start->copy()->addHour(),
        ] + $overrides,
        [test()->tapu->id],
    );
}

/*
|--------------------------------------------------------------------------
| The binding
|--------------------------------------------------------------------------
*/

it('resolves the manual driver by default', function () {
    expect(app(CalendarLink::class))->toBeInstanceOf(ManualLink::class)
        ->and(config('services.google_calendar.driver'))->toBe('manual');
});

it('refuses to boot with a driver that is not built, rather than falling back quietly', function () {
    config(['services.google_calendar.driver' => 'api']);
    app()->forgetInstance(CalendarLink::class);

    // A silent fall back to `manual` would mean a VPS configured for the API running for a week
    // with no Meet links and nobody noticing.
    expect(fn () => app(CalendarLink::class))->toThrow(InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| ManualLink
|--------------------------------------------------------------------------
*/

it('produces no link of its own', function () {
    $driver = new ManualLink;
    $meeting = Meeting::factory()->organisedBy($this->shahadat)->create();

    expect($driver->createsLinksItself())->toBeFalse()
        ->and($driver->schedule($meeting)->touchesMeeting())->toBeFalse()
        ->and($driver->reschedule($meeting)->touchesMeeting())->toBeFalse()
        // The button the organiser presses is the driver's to name, so no Vue file holds a
        // hard-coded Google URL.
        ->and($driver->startUrl())->toBe(MeetLink::NEW_MEETING_URL);
});

it('stores a link the organizer pasted', function () {
    $meeting = MEETING_schedule(['meet_link' => MEETING_GOOD_LINK]);

    expect($meeting->meet_link)->toBe(MEETING_GOOD_LINK)
        // The manual driver never fills this: there is no remote event.
        ->and($meeting->google_event_id)->toBeNull();
});

it('refuses a paste that is not a Meet joining link', function () {
    MEETING_schedule(['meet_link' => 'https://zoom.us/j/123456']);
})->throws(MeetingStateException::class);

it('refuses the instant-meeting URL itself, which is the likeliest wrong paste', function () {
    expect(MeetLink::looksValid(MeetLink::NEW_MEETING_URL))->toBeFalse()
        ->and(MeetLink::looksValid(MEETING_GOOD_LINK))->toBeTrue()
        ->and(MeetLink::looksValid('https://meet.google.com/lookup/goodtechies-standup'))->toBeTrue()
        ->and(MeetLink::looksValid('https://meet.google.com/qkd-mprv-tza?authuser=1'))->toBeTrue()
        ->and(MeetLink::looksValid('http://meet.google.com/qkd-mprv-tza'))->toBeFalse()
        ->and(MeetLink::looksValid('https://meet.google.evil.com/qkd-mprv-tza'))->toBeFalse()
        ->and(MeetLink::looksValid(''))->toBeFalse();
});

it('clears the link when the organizer empties the field', function () {
    $meeting = MEETING_schedule(['meet_link' => MEETING_GOOD_LINK]);

    $this->meetings->update($this->shahadat, $meeting, ['meet_link' => '']);

    expect($meeting->fresh()->meet_link)->toBeNull();
});

it('keeps the pasted link when an edit says nothing about it', function () {
    $meeting = MEETING_schedule(['meet_link' => MEETING_GOOD_LINK]);

    // The driver's empty outcome must not wipe what the organiser just pasted.
    $this->meetings->update($this->shahadat, $meeting, ['title' => 'Client review (moved room)']);

    expect($meeting->fresh()->meet_link)->toBe(MEETING_GOOD_LINK);
});

it('records that the Meet must be cancelled by hand', function () {
    $meeting = MEETING_schedule(['meet_link' => MEETING_GOOD_LINK]);

    $this->meetings->cancel($this->shahadat, $meeting);

    $trail = ActivityLog::query()
        ->where('object_type', $meeting->getMorphClass())
        ->where('object_id', $meeting->id)
        ->pluck('description')
        ->all();

    // Part D §12: "with the manual driver the record notes 'cancel the Meet manually'". It is
    // on the meeting's trail, attributed and timestamped — not a column, and not eleven
    // people's notification.
    expect($trail)->toContain(ManualLink::CANCEL_INSTRUCTION)
        ->and($trail)->toContain('Meeting cancelled')
        ->and($trail)->not->toContain('Google Calendar event deleted');
});

it('says nothing about cancelling a Meet that never existed', function () {
    $meeting = MEETING_schedule();

    $this->meetings->cancel($this->shahadat, $meeting);

    $trail = ActivityLog::query()
        ->where('object_type', $meeting->getMorphClass())
        ->where('object_id', $meeting->id)
        ->pluck('description')
        ->all();

    // Telling somebody to go and close a room that was never opened is an instruction to do
    // nothing, and a trail full of those is a trail nobody reads.
    expect($trail)->not->toContain(ManualLink::CANCEL_INSTRUCTION);
});

/*
|--------------------------------------------------------------------------
| contract: what ANY driver must satisfy
|--------------------------------------------------------------------------
|
| The fake below is what `CalendarApiOneWay` will be, minus Google: it
| creates an event and a link on schedule, keeps them on reschedule and
| deletes the remote event on cancel. If these pass for it, the real one has
| a suite to write itself against.
|
*/

function MEETING_apiDriverFake(): CalendarLink
{
    return new class implements CalendarLink
    {
        public int $rescheduleCalls = 0;

        public function name(): string
        {
            return 'api';
        }

        public function createsLinksItself(): bool
        {
            return true;
        }

        public function startUrl(): ?string
        {
            return null;
        }

        public function schedule(Meeting $meeting): CalendarOutcome
        {
            return CalendarOutcome::linked('evt-'.$meeting->getKey(), 'https://meet.google.com/abc-defg-hij');
        }

        public function reschedule(Meeting $meeting): CalendarOutcome
        {
            $this->rescheduleCalls++;

            return CalendarOutcome::linked($meeting->google_event_id, $meeting->meet_link);
        }

        public function cancel(Meeting $meeting): CalendarOutcome
        {
            return CalendarOutcome::remoteEventRemoved();
        }
    };
}

it('contract: a driver that creates an event has both of its values stored on the meeting', function () {
    $this->app->instance(CalendarLink::class, MEETING_apiDriverFake());
    $this->meetings = app()->make(MeetingService::class);

    $meeting = MEETING_schedule();

    expect($meeting->google_event_id)->toBe('evt-'.$meeting->id)
        ->and($meeting->meet_link)->toBe('https://meet.google.com/abc-defg-hij');
});

it('contract: the remote event is one meeting\'s and one meeting\'s only', function () {
    $this->app->instance(CalendarLink::class, MEETING_apiDriverFake());
    $this->meetings = app()->make(MeetingService::class);

    $meeting = MEETING_schedule();

    // A retried insert that succeeded on the far side but timed out here must not be able to
    // attach the same event to two rows. `meetings_one_per_google_event` is that promise.
    expect(fn () => Meeting::factory()
        ->organisedBy($this->shahadat)
        ->create(['google_event_id' => $meeting->google_event_id]))
        ->toThrow(QueryException::class);
});

it('contract: the driver is asked to reschedule only when the time actually moved', function () {
    $fake = MEETING_apiDriverFake();
    $this->app->instance(CalendarLink::class, $fake);
    $this->meetings = app()->make(MeetingService::class);

    $meeting = MEETING_schedule();

    $this->meetings->update($this->shahadat, $meeting, ['agenda' => 'A typo, fixed.']);
    expect($fake->rescheduleCalls)->toBe(0);

    $moved = $meeting->start_at->copy()->addDay();
    $this->meetings->update($this->shahadat, $meeting->fresh(), [
        'start_at' => $moved,
        'end_at' => $moved->copy()->addHour(),
    ]);
    expect($fake->rescheduleCalls)->toBe(1);
});

it('contract: a driver that deletes the remote event says so on the trail, and asks nobody to do it by hand', function () {
    $this->app->instance(CalendarLink::class, MEETING_apiDriverFake());
    $this->meetings = app()->make(MeetingService::class);

    $meeting = MEETING_schedule();
    $this->meetings->cancel($this->shahadat, $meeting);

    $trail = ActivityLog::query()
        ->where('object_type', $meeting->getMorphClass())
        ->where('object_id', $meeting->id)
        ->pluck('description')
        ->all();

    expect($trail)->toContain('Google Calendar event deleted')
        ->and($trail)->not->toContain(ManualLink::CANCEL_INSTRUCTION);
});

it('contract: a driver that throws leaves no meeting behind', function () {
    $this->app->instance(CalendarLink::class, new class implements CalendarLink
    {
        public function name(): string
        {
            return 'broken';
        }

        public function createsLinksItself(): bool
        {
            return true;
        }

        public function startUrl(): ?string
        {
            return null;
        }

        public function schedule(Meeting $meeting): CalendarOutcome
        {
            throw new RuntimeException('Google said no.');
        }

        public function reschedule(Meeting $meeting): CalendarOutcome
        {
            return CalendarOutcome::nothing();
        }

        public function cancel(Meeting $meeting): CalendarOutcome
        {
            return CalendarOutcome::nothing();
        }
    });

    $this->meetings = app()->make(MeetingService::class);

    expect(fn () => MEETING_schedule())->toThrow(RuntimeException::class);

    // The driver runs INSIDE the transaction, so a failed call is a clean error rather than a
    // meeting that looks scheduled with no event behind it.
    expect(Meeting::query()->count())->toBe(0);
});
