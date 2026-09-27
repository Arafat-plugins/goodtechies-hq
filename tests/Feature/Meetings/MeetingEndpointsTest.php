<?php

use App\Models\Meeting;
use App\Models\Project;
use App\Models\User;
use App\Services\Calendar\MeetLink;
use App\Services\MeetingService;

/*
|--------------------------------------------------------------------------
| The Meetings endpoints — list, calendar and the create/edit form
|--------------------------------------------------------------------------
|
| Five shared routes, because whose calendar a meeting is on belongs to the
| PERSON and not to the shell they are looking at — the same reasoning that
| put /messages, /notifications, /attendance and /leave in routes/shared.php.
|
| Three things here are load-bearing rather than incidental:
|
|   1. **The linked project is ABSENT from the payload of a participant who
|      may not see it.** Not null. Yaseen is invited to the Buffalo Modular
|      review and is not on Buffalo Modular, so he may open the meeting and
|      must not learn the project's name — and a `project: null` would say
|      "there is a project here and you may not have it", which is the exact
|      sentence Part C exists to avoid. `array_key_exists` is the only
|      assertion that can tell the right answer from the wrong one.
|
|   2. **The month view is a WINDOW.** A calendar page that loads every
|      meeting ever is invisible in a seeded database and fatal in a
|      year-old one, so a meeting three months out is asserted absent from
|      this month and present in its own.
|
|   3. **404 and 403 are not interchangeable.** A meeting this person may
|      not see is 404 — they do not learn it exists. One they can see and
|      may not edit is 403 — the act is refused, not the record.
|
| Constants and helpers here are global in Pest, so they are prefixed
| MEETING_ENDPOINTS_.
|
*/

const MEETING_ENDPOINTS_URL = '/meetings';

const MEETING_ENDPOINTS_GOOD_LINK = 'https://meet.google.com/abc-defg-hij';

/** Every meeting id in a `sections` payload (the List). */
function MEETING_ENDPOINTS_section_ids(array $sections): array
{
    $ids = [];

    foreach ($sections as $section) {
        $ids = array_merge($ids, MEETING_ENDPOINTS_day_ids($section['days']));
    }

    return $ids;
}

/** Every meeting id in a `days` payload (the month and the week). */
function MEETING_ENDPOINTS_day_ids(array $days): array
{
    $ids = [];

    foreach ($days as $day) {
        foreach ($day['meetings'] as $meeting) {
            $ids[] = $meeting['id'];
        }
    }

    return $ids;
}

/** One meeting's payload out of a `sections` block, by id. */
function MEETING_ENDPOINTS_find(array $sections, int $id): ?array
{
    foreach ($sections as $section) {
        foreach ($section['days'] as $day) {
            foreach ($day['meetings'] as $meeting) {
                if ($meeting['id'] === $id) {
                    return $meeting;
                }
            }
        }
    }

    return null;
}

/** A complete, valid create payload, with whatever the caller wants changed. */
function MEETING_ENDPOINTS_payload(array $overrides = []): array
{
    $start = now()->addDays(5)->setTime(11, 0);

    return array_merge([
        'title' => 'Sprint planning',
        'start_at' => $start->format('Y-m-d\TH:i'),
        'end_at' => $start->copy()->addMinutes(45)->format('Y-m-d\TH:i'),
        'project_id' => null,
        'task_id' => null,
        'agenda' => 'What we are doing this week.',
        'meet_link' => null,
        'participants' => [],
    ], $overrides);
}

beforeEach(function () {
    $this->seed();

    $this->meetings = app(MeetingService::class);

    $this->shahadat = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->faruk = User::where('email', 'faruk@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    // Tapu is a member of this project. Yaseen is not. That is the whole privacy case.
    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();

    Meeting::query()->delete();

    // Organised by an Admin, both employees in the room, about a project only one of them is on.
    $this->review = $this->meetings->schedule(
        $this->shahadat,
        [
            'title' => 'Buffalo Modular — quarterly review',
            'start_at' => now()->addDays(3)->setTime(15, 0),
            'end_at' => now()->addDays(3)->setTime(16, 0),
            'project_id' => $this->project->id,
            'meet_link' => MEETING_ENDPOINTS_GOOD_LINK,
        ],
        [$this->tapu->id, $this->yaseen->id],
    );

    // Organised by an employee, with another employee in it — the meeting the 403/404 split
    // is measured on, because neither participant is an Admin.
    $this->standup = $this->meetings->schedule(
        $this->tapu,
        [
            'title' => 'Stand-up',
            'start_at' => now()->addDays(2)->setTime(9, 30),
            'end_at' => now()->addDays(2)->setTime(9, 45),
        ],
        [$this->yaseen->id],
    );

    // A meeting neither employee is in at all.
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

/*
|--------------------------------------------------------------------------
| The page
|--------------------------------------------------------------------------
*/

it('renders the Meetings page for everybody who holds meetings.use', function (string $email) {
    $user = User::where('email', $email)->firstOrFail();

    $page = $this->actingAs($user)->get(MEETING_ENDPOINTS_URL)->assertOk()->inertiaPage();

    expect($page['component'])->toBe('Shared/Meetings/Index')
        ->and($page['props']['view'])->toBe('list')
        ->and($page['props']['hasAny'])->toBeTrue();
})->with([
    'shahadat@goodtechies.test',
    'faruk@goodtechies.test',
    'tapu@goodtechies.test',
    'yaseen@goodtechies.test',
])->group('phase7');

it('shows a person only the meetings they may see', function () {
    $props = $this->actingAs($this->yaseen)
        ->get(MEETING_ENDPOINTS_URL.'?scope=all')
        ->assertOk()
        ->inertiaPage()['props'];

    $ids = MEETING_ENDPOINTS_section_ids($props['sections']);

    expect($ids)->toContain($this->review->id)
        ->toContain($this->standup->id)
        // Organised by an Admin, with another Admin in it. Yaseen is neither.
        ->not->toContain($this->outsiders->id);
})->group('phase7');

it('gives an Admin every meeting when they ask for everyone, and only theirs by default', function () {
    $everyone = $this->actingAs($this->shahadat)
        ->get(MEETING_ENDPOINTS_URL.'?scope=all')
        ->assertOk()
        ->inertiaPage()['props'];

    $mine = $this->actingAs($this->shahadat)
        ->get(MEETING_ENDPOINTS_URL)
        ->assertOk()
        ->inertiaPage()['props'];

    expect(MEETING_ENDPOINTS_section_ids($everyone['sections']))->toContain($this->standup->id)
        ->and($everyone['scopeOffered'])->toBeTrue()
        // `mine` is the default, and Shahadat is not in the stand-up.
        ->and(MEETING_ENDPOINTS_section_ids($mine['sections']))->not->toContain($this->standup->id)
        ->and(MEETING_ENDPOINTS_section_ids($mine['sections']))->toContain($this->review->id);
})->group('phase7');

it('does not offer the scope filter to somebody who can only see their own meetings', function () {
    $props = $this->actingAs($this->yaseen)->get(MEETING_ENDPOINTS_URL)->assertOk()->inertiaPage()['props'];

    expect($props['scopeOffered'])->toBeFalse();
})->group('phase7');

it('says nothing-at-all and nothing-this-month with different answers', function () {
    Meeting::query()->delete();

    $props = $this->actingAs($this->yaseen)->get(MEETING_ENDPOINTS_URL)->assertOk()->inertiaPage()['props'];

    expect($props['hasAny'])->toBeFalse();
})->group('phase7');

/*
|--------------------------------------------------------------------------
| The window really is a window
|--------------------------------------------------------------------------
*/

it('fetches a month and not the table', function () {
    $far = now()->addMonths(3)->startOfMonth()->addDays(9)->setTime(10, 0);

    $distant = Meeting::factory()
        ->organisedBy($this->shahadat)
        ->withParticipants([$this->yaseen])
        ->at($far)
        ->create(['title' => 'A long way off']);

    $thisMonth = $this->actingAs($this->yaseen)
        ->get(MEETING_ENDPOINTS_URL.'?view=month&month='.$this->review->start_at->format('Y-m'))
        ->assertOk()
        ->inertiaPage()['props'];

    $itsMonth = $this->actingAs($this->yaseen)
        ->get(MEETING_ENDPOINTS_URL.'?view=month&month='.$far->format('Y-m'))
        ->assertOk()
        ->inertiaPage()['props'];

    expect(MEETING_ENDPOINTS_day_ids($thisMonth['days']))->not->toContain($distant->id)
        ->and(MEETING_ENDPOINTS_day_ids($thisMonth['days']))->toContain($this->review->id)
        ->and(MEETING_ENDPOINTS_day_ids($itsMonth['days']))->toContain($distant->id)
        ->and($itsMonth['window']['key'])->toBe($far->format('Y-m'));
})->group('phase7');

it('fetches a week and not the table', function () {
    $week = $this->review->start_at->copy()->startOfWeek(Carbon\Carbon::MONDAY);

    $props = $this->actingAs($this->yaseen)
        ->get(MEETING_ENDPOINTS_URL.'?view=week&week='.$week->toDateString())
        ->assertOk()
        ->inertiaPage()['props'];

    $nextWeek = $this->actingAs($this->yaseen)
        ->get(MEETING_ENDPOINTS_URL.'?view=week&week='.$week->copy()->addWeeks(2)->toDateString())
        ->assertOk()
        ->inertiaPage()['props'];

    expect($props['window']['days'])->toHaveCount(7)
        // Today rides on the window and not on the day rows, so a today with nothing on it is
        // still marked. Reading it off `days` was the bug this asserts against.
        ->and($props['window']['today'])->toBe(now()->toDateString())
        ->and(MEETING_ENDPOINTS_day_ids($props['days']))->toContain($this->review->id)
        ->and(MEETING_ENDPOINTS_day_ids($nextWeek['days']))->not->toContain($this->review->id);
})->group('phase7');

it('falls back to this month rather than refusing a hand-edited window', function () {
    $props = $this->actingAs($this->yaseen)
        ->get(MEETING_ENDPOINTS_URL.'?view=month&month=not-a-month')
        ->assertOk()
        ->inertiaPage()['props'];

    expect($props['window']['key'])->toBe(now()->format('Y-m'));
})->group('phase7');

/*
|--------------------------------------------------------------------------
| The privacy case this slice is really about
|--------------------------------------------------------------------------
*/

it('leaves the linked project ABSENT for a participant who may not see it', function () {
    $props = $this->actingAs($this->yaseen)
        ->get(MEETING_ENDPOINTS_URL.'?scope=all')
        ->assertOk()
        ->inertiaPage()['props'];

    $payload = MEETING_ENDPOINTS_find($props['sections'], $this->review->id);

    expect($payload)->not->toBeNull()
        // NOT `toBeNull()` on the value — that passes for the wrong answer too.
        ->and(array_key_exists('project', $payload))->toBeFalse()
        ->and(array_key_exists('project_id', $payload))->toBeFalse();
})->group('phase7');

it('sends the linked project to a participant who is on it', function () {
    $props = $this->actingAs($this->tapu)
        ->get(MEETING_ENDPOINTS_URL.'?scope=all')
        ->assertOk()
        ->inertiaPage()['props'];

    $payload = MEETING_ENDPOINTS_find($props['sections'], $this->review->id);

    expect(array_key_exists('project', $payload))->toBeTrue()
        ->and($payload['project']['name'])->toBe($this->project->name);
})->group('phase7');

it('resolves permissions per requester on every row', function () {
    $forOrganiser = MEETING_ENDPOINTS_find(
        $this->actingAs($this->tapu)->get(MEETING_ENDPOINTS_URL)->assertOk()->inertiaPage()['props']['sections'],
        $this->standup->id,
    );

    $forParticipant = MEETING_ENDPOINTS_find(
        $this->actingAs($this->yaseen)->get(MEETING_ENDPOINTS_URL)->assertOk()->inertiaPage()['props']['sections'],
        $this->standup->id,
    );

    expect($forOrganiser['permissions']['can_update'])->toBeTrue()
        ->and($forOrganiser['is_organizer'])->toBeTrue()
        ->and($forParticipant['permissions']['can_update'])->toBeFalse()
        ->and($forParticipant['is_participant'])->toBeTrue()
        ->and($forParticipant['my_rsvp'])->toBe('pending');
})->group('phase7');

/*
|--------------------------------------------------------------------------
| Create → store
|--------------------------------------------------------------------------
*/

it('renders the create form with the people, projects and calendar driver on it', function () {
    $page = $this->actingAs($this->tapu)
        ->get(MEETING_ENDPOINTS_URL.'/create')
        ->assertOk()
        ->inertiaPage();

    $names = array_column($page['props']['people'], 'name');

    expect($page['component'])->toBe('Shared/Meetings/Form')
        ->and($page['props']['meeting'])->toBeNull()
        ->and($page['props']['organizer']['id'])->toBe($this->tapu->id)
        // The picker is people who hold `meetings.use`, and the Accountant holds none.
        ->and($names)->toContain($this->yaseen->name)
        ->and($names)->not->toContain($this->accountant->name)
        ->and($names)->not->toContain($this->tapu->name)
        ->and($page['props']['calendar']['creates_itself'])->toBeFalse()
        ->and($page['props']['calendar']['start_url'])->toBe(MeetLink::NEW_MEETING_URL);
})->group('phase7');

it('schedules a meeting with its participants, the organiser among them', function () {
    $this->actingAs($this->tapu)
        ->post(MEETING_ENDPOINTS_URL, MEETING_ENDPOINTS_payload([
            'participants' => [$this->yaseen->id],
            'meet_link' => MEETING_ENDPOINTS_GOOD_LINK,
        ]))
        ->assertRedirect(MEETING_ENDPOINTS_URL);

    $meeting = Meeting::where('title', 'Sprint planning')->firstOrFail();

    $seated = $meeting->participantSeats->pluck('user_id')->map(fn ($id) => (int) $id)->all();

    expect($meeting->organizer_id)->toBe($this->tapu->id)
        ->and($meeting->meet_link)->toBe(MEETING_ENDPOINTS_GOOD_LINK)
        ->and($seated)->toContain($this->yaseen->id)
        // Calling a meeting is already saying you will be at it.
        ->and($seated)->toContain($this->tapu->id);
})->group('phase7');

it('refuses to link a project the organiser cannot see', function () {
    $this->actingAs($this->yaseen)
        ->post(MEETING_ENDPOINTS_URL, MEETING_ENDPOINTS_payload(['project_id' => $this->project->id]))
        ->assertSessionHasErrors('project_id');

    expect(Meeting::where('title', 'Sprint planning')->exists())->toBeFalse();
})->group('phase7');

/*
|--------------------------------------------------------------------------
| The two 422s the form exists to prevent
|--------------------------------------------------------------------------
*/

it('refuses a Meet link that is not one, with a sentence', function () {
    $response = $this->actingAs($this->tapu)
        ->post(MEETING_ENDPOINTS_URL, MEETING_ENDPOINTS_payload(['meet_link' => 'https://zoom.us/j/123456']));

    $response->assertSessionHasErrors('meet_link');

    expect(session('errors')->first('meet_link'))->toContain('meet.google.com')
        ->and(Meeting::where('title', 'Sprint planning')->exists())->toBeFalse();
})->group('phase7');

it('refuses the Create Meet Link button’s own address specifically', function () {
    $response = $this->actingAs($this->tapu)
        ->post(MEETING_ENDPOINTS_URL, MEETING_ENDPOINTS_payload(['meet_link' => MeetLink::NEW_MEETING_URL]));

    $response->assertSessionHasErrors('meet_link');

    // Not the generic "that is not a Meet link" — the one that says what to do instead.
    expect(session('errors')->first('meet_link'))->toContain('Create Meet Link button');
})->group('phase7');

it('refuses a meeting that ends before it starts', function () {
    $start = now()->addDays(5)->setTime(14, 0);

    $this->actingAs($this->tapu)
        ->post(MEETING_ENDPOINTS_URL, MEETING_ENDPOINTS_payload([
            'start_at' => $start->format('Y-m-d\TH:i'),
            'end_at' => $start->copy()->subHour()->format('Y-m-d\TH:i'),
        ]))
        ->assertSessionHasErrors('end_at');

    expect(Meeting::where('title', 'Sprint planning')->exists())->toBeFalse();
})->group('phase7');

it('refuses a meeting of no length at all', function () {
    $start = now()->addDays(5)->setTime(14, 0);

    $this->actingAs($this->tapu)
        ->post(MEETING_ENDPOINTS_URL, MEETING_ENDPOINTS_payload([
            'start_at' => $start->format('Y-m-d\TH:i'),
            'end_at' => $start->format('Y-m-d\TH:i'),
        ]))
        ->assertSessionHasErrors('end_at');
})->group('phase7');

/*
|--------------------------------------------------------------------------
| Edit → update, and the 403 / 404 split
|--------------------------------------------------------------------------
*/

it('lets the organiser open and save the edit form', function () {
    $page = $this->actingAs($this->tapu)
        ->get(MEETING_ENDPOINTS_URL.'/'.$this->standup->id.'/edit')
        ->assertOk()
        ->inertiaPage();

    expect($page['component'])->toBe('Shared/Meetings/Form')
        ->and($page['props']['meeting']['id'])->toBe($this->standup->id)
        ->and($page['props']['initial']['title'])->toBe('Stand-up')
        ->and($page['props']['initial']['participants'])->toBe([$this->yaseen->id]);

    $this->actingAs($this->tapu)
        ->put(MEETING_ENDPOINTS_URL.'/'.$this->standup->id, MEETING_ENDPOINTS_payload([
            'title' => 'Stand-up (moved)',
            'participants' => [],
        ]))
        ->assertRedirect(MEETING_ENDPOINTS_URL);

    $this->standup->refresh();

    expect($this->standup->title)->toBe('Stand-up (moved)')
        // An empty list is "nobody but the organiser", and that is what it did.
        ->and($this->standup->participantSeats->pluck('user_id')->map(fn ($id) => (int) $id)->all())
        ->toBe([$this->tapu->id]);
})->group('phase7');

it('lets an Admin edit somebody else’s meeting', function () {
    $this->actingAs($this->shahadat)
        ->get(MEETING_ENDPOINTS_URL.'/'.$this->standup->id.'/edit')
        ->assertOk();
})->group('phase7');

it('gives a participant who is not the organiser 403 on edit, not 404', function () {
    $this->actingAs($this->yaseen)
        ->get(MEETING_ENDPOINTS_URL.'/'.$this->standup->id.'/edit')
        ->assertForbidden();

    $this->actingAs($this->yaseen)
        ->put(MEETING_ENDPOINTS_URL.'/'.$this->standup->id, MEETING_ENDPOINTS_payload())
        ->assertForbidden();

    $this->standup->refresh();

    expect($this->standup->title)->toBe('Stand-up');
})->group('phase7');

it('gives a stranger 404 on edit, so they never learn the meeting exists', function () {
    $this->actingAs($this->tapu)
        ->get(MEETING_ENDPOINTS_URL.'/'.$this->outsiders->id.'/edit')
        ->assertNotFound();

    $this->actingAs($this->tapu)
        ->put(MEETING_ENDPOINTS_URL.'/'.$this->outsiders->id, MEETING_ENDPOINTS_payload())
        ->assertNotFound();
})->group('phase7');

it('gives 404 for a meeting that does not exist at all — the same answer', function () {
    $this->actingAs($this->tapu)
        ->get(MEETING_ENDPOINTS_URL.'/999999/edit')
        ->assertNotFound();
})->group('phase7');

it('refuses to edit a cancelled meeting', function () {
    app(MeetingService::class)->cancel($this->tapu, $this->standup);

    $this->actingAs($this->tapu)
        ->get(MEETING_ENDPOINTS_URL.'/'.$this->standup->id.'/edit')
        ->assertForbidden();
})->group('phase7');

/*
|--------------------------------------------------------------------------
| Who may not be here at all
|--------------------------------------------------------------------------
*/

it('gives the Accountant 403 on every meetings route', function () {
    $id = $this->review->id;

    $this->actingAs($this->accountant)->get(MEETING_ENDPOINTS_URL)->assertForbidden();
    $this->actingAs($this->accountant)->get(MEETING_ENDPOINTS_URL.'/create')->assertForbidden();
    $this->actingAs($this->accountant)->post(MEETING_ENDPOINTS_URL, MEETING_ENDPOINTS_payload())->assertForbidden();
    $this->actingAs($this->accountant)->get(MEETING_ENDPOINTS_URL.'/'.$id.'/edit')->assertForbidden();
    $this->actingAs($this->accountant)->put(MEETING_ENDPOINTS_URL.'/'.$id, MEETING_ENDPOINTS_payload())->assertForbidden();
})->group('phase7');

it('sends a guest to log in from every meetings route', function () {
    $id = $this->review->id;

    $this->get(MEETING_ENDPOINTS_URL)->assertRedirect('/login');
    $this->get(MEETING_ENDPOINTS_URL.'/create')->assertRedirect('/login');
    $this->post(MEETING_ENDPOINTS_URL, MEETING_ENDPOINTS_payload())->assertRedirect('/login');
    $this->get(MEETING_ENDPOINTS_URL.'/'.$id.'/edit')->assertRedirect('/login');
    $this->put(MEETING_ENDPOINTS_URL.'/'.$id, MEETING_ENDPOINTS_payload())->assertRedirect('/login');
})->group('phase7');
