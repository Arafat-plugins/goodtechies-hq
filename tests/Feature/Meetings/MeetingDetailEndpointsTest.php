<?php

use App\Models\Conversation;
use App\Models\Meeting;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\MeetingService;
use App\Support\NotificationType;
use App\Support\RsvpStatus;
use App\Support\TaskStatus;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The meeting detail screen and its four verbs
|--------------------------------------------------------------------------
|
| `GET /meetings/{meeting}` plus cancel, rsvp, notes and action-items —
| Phase 7 slice 3, master prompt Part D §12.
|
| Two rules are asserted here more than anything else, because they are the
| two that would be expensive to get wrong:
|
|   1. **404 before 403.** Every id is resolved through
|      `Meeting::visibleTo($user)` before any ability is asked. A meeting
|      this person may not see is *not found*; one they can see and may not
|      act on is *forbidden*. Backwards, the 403 would tell an employee that
|      a meeting with that id exists and that they were not invited.
|
|   2. **An absent key is not a null one.** Yaseen is a participant of the
|      Buffalo Modular review and is NOT a member of Buffalo Modular. His
|      payload has no `project` key at all. `assertJsonMissingPath` and
|      `array_key_exists` are the only assertions that can tell that apart
|      from `project: null`, which is why they are the ones used.
|
| Prefixed MEETING_DETAIL_ throughout: Pest declares a test file's constants
| and functions GLOBALLY, and two files sharing a name silently give one of
| them the other's value (AGENTS.md).
|
*/

beforeEach(function () {
    $this->seed();

    $this->meetings = app(MeetingService::class);

    $this->shahadat = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->faruk = User::where('email', 'faruk@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    // Tapu is a member of this project. Yaseen is not. That is the whole privacy case, and it
    // is the same pair `MeetingPolicyTest` is built on.
    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();

    // The seeder's demo meetings would make every count in this file an arithmetic puzzle.
    Meeting::query()->delete();
    Notification::query()->delete();

    $this->meeting = $this->meetings->schedule(
        $this->shahadat,
        [
            'title' => 'Buffalo Modular — quarterly review',
            'start_at' => now()->addDays(3)->setTime(15, 0),
            'end_at' => now()->addDays(3)->setTime(16, 0),
            'project_id' => $this->project->id,
            'meet_link' => 'https://meet.google.com/abc-defg-hij',
            'agenda' => 'Rankings, then the backlog.',
        ],
        [$this->tapu->id, $this->yaseen->id],
    );

    // A meeting neither employee is in. Every "stranger" assertion in this file points here.
    $this->outsiders = $this->meetings->schedule(
        $this->faruk,
        [
            'title' => 'Admins only',
            'start_at' => now()->addDays(4)->setTime(9, 0),
            'end_at' => now()->addDays(4)->setTime(10, 0),
        ],
        [$this->shahadat->id],
    );
});

/** The detail URL for a meeting. */
function MEETING_DETAIL_url(Meeting $meeting, string $suffix = ''): string
{
    return '/meetings/'.$meeting->getKey().$suffix;
}

/**
 * The `meeting` prop of the detail page, as this person is served it.
 *
 * @return array<string, mixed>
 */
function MEETING_DETAIL_propsFor(object $test, User $user, Meeting $meeting): array
{
    return $test->actingAs($user)
        ->get(MEETING_DETAIL_url($meeting))
        ->assertOk()
        ->inertiaPage()['props']['meeting'];
}

/** This person's seat, straight out of the database. */
function MEETING_DETAIL_rsvpOf(Meeting $meeting, User $user): ?string
{
    return $meeting->fresh()->rsvpOf($user)?->value;
}

/*
|--------------------------------------------------------------------------
| show
|--------------------------------------------------------------------------
*/

it('renders the meeting for its organiser, a participant and an Admin', function () {
    // Shahadat is both the organiser and an Admin; Faruk is the Admin who is in neither room
    // by invitation and sees it anyway, which is the override being exercised rather than
    // assumed.
    foreach ([$this->shahadat, $this->tapu, $this->faruk] as $user) {
        $this->actingAs($user)
            ->get(MEETING_DETAIL_url($this->meeting))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shared/Meetings/Show')
                ->where('meeting.id', $this->meeting->id)
                ->where('meeting.title', 'Buffalo Modular — quarterly review')
                ->where('meeting.has_meet_link', true)
                ->where('meeting.state', 'waiting')
                ->where('meeting.state_label', 'Scheduled')
                ->has('meeting.participants', 3)
                ->has('meeting.action_items')
                ->has('meeting.calendar.cancel_notice')
            );
    }
});

it('gives a stranger 404 and never 403, so they do not learn the meeting exists', function () {
    // Tapu is an employee in perfectly good standing with `meetings.use` in his hand. What he
    // does not have is a seat at this one, and the answer to that is *not found*.
    $this->actingAs($this->tapu)
        ->get(MEETING_DETAIL_url($this->outsiders))
        ->assertNotFound();

    // And an id that never existed answers identically, which is the property that makes the
    // first assertion worth anything.
    $this->actingAs($this->tapu)
        ->get('/meetings/'.(Meeting::max('id') + 500))
        ->assertNotFound();
});

it('leaves the linked project OUT of a participant who may not see it, rather than sending null', function () {
    // Tapu is on Buffalo Modular, so he gets the name.
    $tapu = MEETING_DETAIL_propsFor($this, $this->tapu, $this->meeting);

    expect(array_key_exists('project', $tapu))->toBeTrue()
        ->and($tapu['project']['name'])->toBe('Buffalo Modular — SEO');

    // Yaseen is in the same room and not on the project. The key is GONE. `=== null` would pass
    // for the right answer and the wrong one alike, so it is not the assertion made here: a
    // `project: null` would say "there is a project here and you may not have it", which is
    // exactly the fact Part C keeps from him.
    $yaseen = MEETING_DETAIL_propsFor($this, $this->yaseen, $this->meeting);

    expect(array_key_exists('project', $yaseen))->toBeFalse();

    // The same assertion over the wire, on the shape Inertia actually serialises.
    $this->actingAs($this->yaseen)
        ->get(MEETING_DETAIL_url($this->meeting), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => Inertia::getVersion(),
        ])
        ->assertOk()
        ->assertJsonMissingPath('props.meeting.project');

    // And nothing else smuggles it back: not the project's name anywhere in his whole payload
    // (the meeting's own TITLE names the client, which is a string the organiser typed and
    // this test deliberately does not match on), and not a bare id either.
    expect(json_encode($yaseen))->not->toContain($this->project->name)
        ->and(array_key_exists('project_id', $yaseen))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| rsvp
|--------------------------------------------------------------------------
*/

it('lets a participant give each of the three answers, and shows the new one at once', function () {
    foreach ([RsvpStatus::Accepted, RsvpStatus::Declined, RsvpStatus::Pending] as $answer) {
        $this->actingAs($this->tapu)
            ->from(MEETING_DETAIL_url($this->meeting))
            ->post(MEETING_DETAIL_url($this->meeting, '/rsvp'), ['status' => $answer->value])
            ->assertRedirect(MEETING_DETAIL_url($this->meeting));

        expect(MEETING_DETAIL_rsvpOf($this->meeting, $this->tapu))->toBe($answer->value);

        // "Visible immediately after" is the part a person judges this control by: the next
        // render of the page they were sent back to has to carry the answer they just gave.
        expect(MEETING_DETAIL_propsFor($this, $this->tapu, $this->meeting)['my_rsvp'])
            ->toBe($answer->value);
    }
});

it('refuses an RSVP from somebody who is not in the room, with 403 and not 404', function () {
    // Faruk is an Admin: he may edit this meeting and cancel it, and he is not in it. Seeing a
    // meeting and being at it are different questions, so the record is not hidden from him —
    // the ACT is refused.
    $this->actingAs($this->faruk)
        ->post(MEETING_DETAIL_url($this->meeting, '/rsvp'), ['status' => RsvpStatus::Accepted->value])
        ->assertForbidden();

    expect(MEETING_DETAIL_rsvpOf($this->meeting, $this->faruk))->toBeNull();
});

it('will not let a participant answer for somebody else, even carrying their user_id', function () {
    $before = MEETING_DETAIL_rsvpOf($this->meeting, $this->yaseen);

    $this->actingAs($this->tapu)
        ->post(MEETING_DETAIL_url($this->meeting, '/rsvp'), [
            'status' => RsvpStatus::Declined->value,
            'user_id' => $this->yaseen->id,
        ])
        ->assertSessionHasErrors('user_id');

    // Neither seat moved. Not Yaseen's, which is the attack; and not Tapu's either, because
    // quietly recording the sender's own answer instead would be obeying a different
    // instruction from the one that was sent.
    expect(MEETING_DETAIL_rsvpOf($this->meeting, $this->yaseen))->toBe($before)
        ->and(MEETING_DETAIL_rsvpOf($this->meeting, $this->tapu))->toBe(RsvpStatus::Pending->value);

    // The prohibition is the outer lock and not the only one: an Admin who IS in the room may
    // answer, and may not answer for the person beside them. This is the ability itself,
    // asked the way the controller asks it.
    expect(Gate::forUser($this->tapu)->allows('rsvp', [$this->meeting, $this->yaseen]))->toBeFalse()
        ->and(Gate::forUser($this->tapu)->allows('rsvp', [$this->meeting, $this->tapu]))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| notes and decisions
|--------------------------------------------------------------------------
*/

it('lets the organiser and an Admin write the meeting up', function () {
    $this->actingAs($this->shahadat)
        ->put(MEETING_DETAIL_url($this->meeting, '/notes'), [
            'notes' => 'Rankings are up; the backlog is not.',
            'decisions' => 'Ship the sitemap on Thursday.',
        ])
        ->assertRedirect();

    expect($this->meeting->fresh()->note?->notes)->toBe('Rankings are up; the backlog is not.');

    // Faruk organised nothing here. He is an Admin, and Part D §12 gives the edit to "the
    // organizer and any Admin".
    $this->actingAs($this->faruk)
        ->put(MEETING_DETAIL_url($this->meeting, '/notes'), [
            'notes' => 'Corrected afterwards.',
            'decisions' => null,
        ])
        ->assertRedirect();

    $note = $this->meeting->fresh()->note;

    expect($note?->notes)->toBe('Corrected afterwards.')
        // Sent as null, stored as null: emptying one half is an edit, not an omission.
        ->and($note?->decisions)->toBeNull();
});

it('refuses a plain participant the pen and still hands them the page', function () {
    $this->actingAs($this->shahadat)->put(MEETING_DETAIL_url($this->meeting, '/notes'), [
        'notes' => 'What the room decided.',
        'decisions' => 'Thursday.',
    ])->assertRedirect();

    // 403 and not 404: Tapu is looking straight at the meeting, and pretending it had vanished
    // would be a lie about something on his own screen.
    $this->actingAs($this->tapu)
        ->put(MEETING_DETAIL_url($this->meeting, '/notes'), ['notes' => 'Mine now.'])
        ->assertForbidden();

    expect($this->meeting->fresh()->note?->notes)->toBe('What the room decided.');

    // And he reads what was written — the pad is rendered read-only for him, not hidden. A
    // record of a meeting he sat in is not a secret from him.
    $props = MEETING_DETAIL_propsFor($this, $this->tapu, $this->meeting);

    expect($props['notes'])->toBe('What the room decided.')
        ->and($props['decisions'])->toBe('Thursday.')
        ->and($props['permissions']['can_update'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| action items → tasks
|--------------------------------------------------------------------------
*/

it('turns an action item into a real task on the meeting\'s project, stamped with where it came from', function () {
    $this->actingAs($this->shahadat)
        ->post(MEETING_DETAIL_url($this->meeting, '/action-items'), [
            'title' => 'Rewrite the service-page titles',
            'assignee_id' => $this->tapu->employee->id,
            'due_date' => now()->addWeek()->toDateString(),
        ])
        ->assertRedirect();

    $task = Task::where('title', 'Rewrite the service-page titles')->firstOrFail();

    expect((int) $task->source_meeting_id)->toBe($this->meeting->id)
        ->and((int) $task->project_id)->toBe($this->project->id)
        ->and($task->status)->toBe(TaskStatus::Todo)
        ->and($task->assignees->pluck('id')->all())->toBe([$this->tapu->employee->id])
        // It went THROUGH TaskService::create() rather than round it, which is what buys the
        // task its discussion, its board position and its audit row. The conversation is the
        // cheapest of the three to see from here.
        ->and(Conversation::where('linked_task_id', $task->id)->exists())->toBeTrue();

    // The relation slice 1 built for exactly this reads it back.
    expect($this->meeting->fresh()->actionItemTasks->pluck('id')->all())->toContain($task->id);

    // And the screen shows it, with a link to the task on the reader's own surface.
    $row = collect(MEETING_DETAIL_propsFor($this, $this->shahadat, $this->meeting)['action_items'])
        ->firstWhere('id', $task->id);

    expect($row)->not->toBeNull()
        ->and($row['href'])->toBe('/admin/tasks/'.$task->id)
        ->and($row['status_label'])->toBe(TaskStatus::Todo->label());

    $this->actingAs($this->shahadat)->get($row['href'])->assertOk();
});

it('offers no convert control on a meeting with no project a viewer may open, and says so in the same words either way', function () {
    // Yaseen is in the room and not on the project, so `linkedContextFor()` gives him no
    // `project` key — and a task has to go on a project.
    $hidden = MEETING_DETAIL_propsFor($this, $this->yaseen, $this->meeting);

    $unlinked = $this->meetings->schedule($this->shahadat, [
        'title' => 'A chat about nothing in particular',
        'start_at' => now()->addDays(2)->setTime(11, 0),
        'end_at' => now()->addDays(2)->setTime(11, 30),
    ], [$this->tapu->id]);

    $none = MEETING_DETAIL_propsFor($this, $this->shahadat, $unlinked);

    expect($hidden['can_convert_action_items'])->toBeFalse()
        ->and($none['can_convert_action_items'])->toBeFalse()
        // **The same sentence, word for word.** A participant who may not see the linked
        // project is told exactly what somebody on a meeting with no project at all is told,
        // because the difference between those two is the fact being withheld. Two different
        // explanations here would be the leak spelled out in prose.
        ->and($hidden['action_item_note'])->toBe($none['action_item_note'])
        ->and($hidden['action_item_note'])->not->toBeNull();
});

it('gives its own reason to somebody who may not create tasks at all, rather than blaming the project', function () {
    // Tapu is on Buffalo Modular and is looking straight at its name. What he cannot do is mint
    // work for people, so the refusal says that — telling him there is no project he can add to
    // would be explaining a refusal with a reason he can see is false.
    $props = MEETING_DETAIL_propsFor($this, $this->tapu, $this->meeting);

    expect(array_key_exists('project', $props))->toBeTrue()
        ->and($props['can_convert_action_items'])->toBeFalse()
        ->and($props['action_item_note'])->toContain('creating tasks is not something you do here');

    // Nothing about the reason is cosmetic: the endpoint refuses him too.
    $this->actingAs($this->tapu)
        ->post(MEETING_DETAIL_url($this->meeting, '/action-items'), ['title' => 'Not mine to give'])
        ->assertForbidden();

    expect(Task::where('title', 'Not mine to give')->exists())->toBeFalse();

    // The premise, asserted rather than assumed: if the catalogue ever gives a remote employee
    // `tasks.create`, this test should fail loudly here and be re-pointed at somebody who still
    // does not hold it, not silently start proving nothing.
    expect(Gate::forUser($this->tapu)->allows('create', Task::class))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| cancel
|--------------------------------------------------------------------------
*/

it('cancels a meeting, tells the room, keeps the tasks it produced, and refuses a second cancel', function () {
    $this->actingAs($this->shahadat)
        ->post(MEETING_DETAIL_url($this->meeting, '/action-items'), ['title' => 'Survives the cancellation'])
        ->assertRedirect();

    $task = Task::where('title', 'Survives the cancellation')->firstOrFail();

    Notification::query()->delete();

    $this->actingAs($this->shahadat)
        ->post(MEETING_DETAIL_url($this->meeting, '/cancel'))
        ->assertRedirect();

    expect($this->meeting->fresh()->isCancelled())->toBeTrue();

    // Part D §12: "participants notified". Everybody but the person who just did it.
    $told = Notification::query()
        ->where('type', NotificationType::MeetingCancelled->value)
        ->pluck('user_id')
        ->all();

    expect($told)->toContain($this->tapu->id)
        ->and($told)->toContain($this->yaseen->id)
        ->and($told)->not->toContain($this->shahadat->id);

    // "linked tasks not deleted" — and not detached either. The provenance is the whole reason
    // `source_meeting_id` is `nullOnDelete` rather than cascading.
    expect(Task::whereKey($task->id)->exists())->toBeTrue()
        ->and((int) $task->fresh()->source_meeting_id)->toBe($this->meeting->id);

    // A second cancel is a refusal, not a second round of notifications for no new fact.
    $this->actingAs($this->shahadat)
        ->post(MEETING_DETAIL_url($this->meeting, '/cancel'))
        ->assertForbidden();

    // And the meeting is still there to look at. Cancelled is a state, not a deletion.
    $this->actingAs($this->tapu)
        ->get(MEETING_DETAIL_url($this->meeting))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('meeting.state', 'cancelled')
            ->where('meeting.state_label', 'Cancelled')
        );
});

it('makes a cancelled meeting read-only: no RSVP, no notes, no new action items', function () {
    $this->actingAs($this->shahadat)->post(MEETING_DETAIL_url($this->meeting, '/cancel'))->assertRedirect();

    $this->actingAs($this->tapu)
        ->post(MEETING_DETAIL_url($this->meeting, '/rsvp'), ['status' => RsvpStatus::Accepted->value])
        ->assertForbidden();

    $this->actingAs($this->shahadat)
        ->put(MEETING_DETAIL_url($this->meeting, '/notes'), ['notes' => 'Too late.'])
        ->assertForbidden();

    $this->actingAs($this->shahadat)
        ->post(MEETING_DETAIL_url($this->meeting, '/action-items'), ['title' => 'Too late.'])
        ->assertForbidden();

    expect(Task::where('title', 'Too late.')->exists())->toBeFalse()
        ->and(MEETING_DETAIL_rsvpOf($this->meeting, $this->tapu))->toBe(RsvpStatus::Pending->value);

    // The screen says so too, so nothing is drawn that the endpoint would refuse.
    $props = MEETING_DETAIL_propsFor($this, $this->shahadat, $this->meeting);

    expect($props['permissions']['can_update'])->toBeFalse()
        ->and($props['permissions']['can_cancel'])->toBeFalse()
        ->and($props['permissions']['can_rsvp'])->toBeFalse()
        ->and($props['can_convert_action_items'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Who may not be here at all
|--------------------------------------------------------------------------
*/

it('refuses the Accountant every one of the four routes, by the key and not by the name', function () {
    $calls = [
        fn () => $this->get(MEETING_DETAIL_url($this->meeting)),
        fn () => $this->post(MEETING_DETAIL_url($this->meeting, '/cancel')),
        fn () => $this->post(MEETING_DETAIL_url($this->meeting, '/rsvp'), ['status' => 'accepted']),
        fn () => $this->put(MEETING_DETAIL_url($this->meeting, '/notes'), ['notes' => 'x']),
        fn () => $this->post(MEETING_DETAIL_url($this->meeting, '/action-items'), ['title' => 'x']),
    ];

    foreach ($calls as $call) {
        $this->actingAs($this->accountant);
        $call()->assertForbidden();
    }
});

it('sends a signed-out visitor to log in rather than answering any of it', function () {
    $this->get(MEETING_DETAIL_url($this->meeting))->assertRedirect('/login');
    $this->post(MEETING_DETAIL_url($this->meeting, '/cancel'))->assertRedirect('/login');
    $this->post(MEETING_DETAIL_url($this->meeting, '/rsvp'), ['status' => 'accepted'])->assertRedirect('/login');
    $this->put(MEETING_DETAIL_url($this->meeting, '/notes'), ['notes' => 'x'])->assertRedirect('/login');
    $this->post(MEETING_DETAIL_url($this->meeting, '/action-items'), ['title' => 'x'])->assertRedirect('/login');
});

/*
|--------------------------------------------------------------------------
| The dashboard card
|--------------------------------------------------------------------------
*/

it('puts only the meetings a person is on into their dashboard card', function () {
    // Tapu is in the review and not in "Admins only". One query, scoped by
    // `Meeting::visibleTo()`, so the second one is not in the result rather than filtered out
    // of it afterwards.
    $rows = collect($this->actingAs($this->tapu)->get('/employee/dashboard')->assertOk()
        ->inertiaPage()['props']['upcomingMeetings']);

    expect($rows->pluck('id')->all())->toBe([$this->meeting->id])
        ->and($rows->first()['has_meet_link'])->toBeTrue()
        // The word beside the colour, never the colour alone.
        ->and($rows->first()['state_label'])->toBe('Scheduled');

    // The Admin's card is the same question with a wider scope — they see both.
    $admin = collect($this->actingAs($this->shahadat)->get('/admin/dashboard')->assertOk()
        ->inertiaPage()['props']['upcomingMeetings']);

    expect($admin->pluck('id')->all())->toContain($this->meeting->id)
        ->and($admin->pluck('id')->all())->toContain($this->outsiders->id);
});

it('drops a cancelled meeting off the dashboard card and keeps the card capped', function () {
    $this->actingAs($this->shahadat)->post(MEETING_DETAIL_url($this->meeting, '/cancel'))->assertRedirect();

    $rows = collect($this->actingAs($this->tapu)->get('/employee/dashboard')
        ->inertiaPage()['props']['upcomingMeetings']);

    expect($rows)->toHaveCount(0);

    // Eight meetings, five rows. The cap is the server's, not a `slice()` in Vue.
    for ($i = 1; $i <= 8; $i++) {
        $this->meetings->schedule($this->shahadat, [
            'title' => 'Stand-up '.$i,
            'start_at' => now()->addDays($i)->setTime(9, 0),
            'end_at' => now()->addDays($i)->setTime(9, 15),
        ], [$this->tapu->id]);
    }

    $rows = collect($this->actingAs($this->tapu)->get('/employee/dashboard')
        ->inertiaPage()['props']['upcomingMeetings']);

    expect($rows)->toHaveCount(5)
        // And in the order a diary is read: soonest first.
        ->and($rows->pluck('title')->all())->toBe(['Stand-up 1', 'Stand-up 2', 'Stand-up 3', 'Stand-up 4', 'Stand-up 5']);
});
