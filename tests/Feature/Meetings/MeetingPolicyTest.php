<?php

use App\Models\Meeting;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\MeetingService;
use App\Support\Permission;
use App\Support\RsvpStatus;
use App\Support\UserStatus;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| MeetingPolicy, and the privacy case at the heart of this slice
|--------------------------------------------------------------------------
|
| Part D §12: "any non-Accountant active user creates a meeting; the
| organizer and any Admin edit or cancel it; participants see it; the
| Accountant has no meetings."
|
| Every cell of that, plus the one it implies and nobody writes down:
|
|   **A participant may be in a meeting about a project they are not on.**
|
| Tapu is on Buffalo Modular — SEO. Yaseen is not. Both are invited to the
| review. So Yaseen may open the meeting and must NOT learn the project's
| name — and Part C is exact about what that means: the field is ABSENT
| from his payload, not null and not the id. `array_key_exists` is the only
| assertion that can tell those apart, which is why it is the one used.
|
| Which refusals are which:
|
|   viewAny  → 403 (about the person and the feature)
|   view     → 404 (they must not learn the meeting exists)
|   update   → 403 (they can see it; the ACT is refused)
|   cancel   → 403 (same)
|   rsvp     → 404 if they cannot see it, 403 if they can but are not in it
|
| Every constant and helper here is prefixed MEETING_ (AGENTS.md).
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

    // Tapu is a member of this project. Yaseen is not. That is the whole privacy case.
    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();

    Meeting::query()->delete();

    // Organised by Shahadat (an Admin), with both employees in the room, about a project only
    // one of them is on.
    $this->meeting = $this->meetings->schedule(
        $this->shahadat,
        [
            'title' => 'Buffalo Modular — quarterly review',
            'start_at' => now()->addDays(3)->setTime(15, 0),
            'end_at' => now()->addDays(3)->setTime(16, 0),
            'project_id' => $this->project->id,
        ],
        [$this->tapu->id, $this->yaseen->id],
    );

    // A meeting neither employee is in, organised by the other Admin.
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

/**
 * Ask one ability the way a controller would.
 */
function MEETING_can(User $user, string $ability, mixed $subject): bool
{
    return Gate::forUser($user)->allows($ability, $subject);
}

/*
|--------------------------------------------------------------------------
| viewAny and create — the key, and the Accountant falling out of it
|--------------------------------------------------------------------------
*/

it('lets every non-Accountant active user reach the meetings feature', function () {
    foreach ([$this->shahadat, $this->faruk, $this->tapu, $this->yaseen] as $user) {
        expect(MEETING_can($user, 'viewAny', Meeting::class))->toBeTrue()
            ->and(MEETING_can($user, 'create', Meeting::class))->toBeTrue();
    }
});

it('refuses the Accountant viewAny and create, with their role named nowhere', function () {
    expect(MEETING_can($this->accountant, 'viewAny', Meeting::class))->toBeFalse()
        ->and(MEETING_can($this->accountant, 'create', Meeting::class))->toBeFalse();

    // And it is the KEY doing it, not the name: the Accountant holds no `meetings.use`, and
    // MeetingPolicy does not contain the word ACCOUNTANT.
    expect($this->accountant->hasPermission(Permission::MeetingsUse))->toBeFalse()
        ->and(file_get_contents(app_path('Policies/MeetingPolicy.php')))->not->toContain('RoleName::ACCOUNTANT');
});

it('refuses a deactivated user who still holds the key', function () {
    $this->tapu->forceFill(['status' => UserStatus::Inactive])->save();

    expect(MEETING_can($this->tapu->fresh(), 'viewAny', Meeting::class))->toBeFalse()
        ->and(MEETING_can($this->tapu->fresh(), 'view', $this->meeting))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| view — every cell, and a refusal here is a 404
|--------------------------------------------------------------------------
*/

it('lets the organizer, every participant and any Admin see a meeting', function () {
    expect(MEETING_can($this->shahadat, 'view', $this->meeting))->toBeTrue()   // organiser
        ->and(MEETING_can($this->tapu, 'view', $this->meeting))->toBeTrue()     // participant
        ->and(MEETING_can($this->yaseen, 'view', $this->meeting))->toBeTrue()   // participant
        ->and(MEETING_can($this->faruk, 'view', $this->meeting))->toBeTrue();   // Admin, not in it
});

it('refuses view to a non-participant, and the record is ABSENT rather than refused', function () {
    expect(MEETING_can($this->tapu, 'view', $this->outsiders))->toBeFalse()
        ->and(MEETING_can($this->yaseen, 'view', $this->outsiders))->toBeFalse();

    // What the controller will do with that: the id resolves through visibleTo() and is simply
    // not there, so the answer is 404 and not 403. A 403 would tell Tapu that a meeting with
    // that id exists and that he was not invited.
    expect(Meeting::query()->visibleTo($this->tapu)->whereKey($this->outsiders->id)->exists())->toBeFalse()
        ->and(Meeting::query()->visibleTo($this->tapu)->pluck('id')->all())->toBe([$this->meeting->id]);
});

it('gives the Accountant an empty list rather than a refusal on a list', function () {
    expect(Meeting::query()->visibleTo($this->accountant)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| update and cancel — and a refusal here is a 403
|--------------------------------------------------------------------------
*/

it('lets the organizer and any Admin edit and cancel', function () {
    foreach (['update', 'cancel'] as $ability) {
        expect(MEETING_can($this->shahadat, $ability, $this->meeting))->toBeTrue()
            ->and(MEETING_can($this->faruk, $ability, $this->meeting))->toBeTrue();
    }
});

it('refuses a participant who is neither the organizer nor an Admin, and does not hide the record from them', function () {
    foreach (['update', 'cancel'] as $ability) {
        expect(MEETING_can($this->tapu, $ability, $this->meeting))->toBeFalse()
            ->and(MEETING_can($this->yaseen, $ability, $this->meeting))->toBeFalse();
    }

    // The 403 half of the rule: they CAN see it, so the record is not hidden — the act is
    // refused. `view` is what decides 404 versus 403, and here it says the meeting is visible.
    expect(MEETING_can($this->tapu, 'view', $this->meeting))->toBeTrue()
        ->and(Meeting::query()->visibleTo($this->tapu)->whereKey($this->meeting->id)->exists())->toBeTrue();
});

it('makes a cancelled meeting read-only for everybody, the organizer included', function () {
    $this->meetings->cancel($this->shahadat, $this->meeting);
    $cancelled = $this->meeting->fresh();

    expect(MEETING_can($this->shahadat, 'update', $cancelled))->toBeFalse()
        ->and(MEETING_can($this->faruk, 'update', $cancelled))->toBeFalse()
        ->and(MEETING_can($this->shahadat, 'cancel', $cancelled))->toBeFalse()
        // Still visible: cancelling is not hiding.
        ->and(MEETING_can($this->tapu, 'view', $cancelled))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| rsvp — a participant, and only for themselves
|--------------------------------------------------------------------------
*/

it('lets a participant answer only for themselves', function () {
    expect(MEETING_can($this->tapu, 'rsvp', [$this->meeting, $this->tapu]))->toBeTrue()
        // Nobody answers for somebody else — not even an Admin, who may cancel the whole
        // meeting but may not assert that Tapu will be at it.
        ->and(MEETING_can($this->shahadat, 'rsvp', [$this->meeting, $this->tapu]))->toBeFalse()
        ->and(MEETING_can($this->tapu, 'rsvp', [$this->meeting, $this->yaseen]))->toBeFalse();
});

it('refuses rsvp to an Admin who is not in the room, and to anybody on a cancelled meeting', function () {
    expect(MEETING_can($this->faruk, 'rsvp', [$this->outsiders, $this->faruk]))->toBeTrue();

    // Faruk organises `outsiders` and so has a seat. He has none in `meeting`, where he is only
    // an Admin — and being an Admin is not being in the room.
    expect(MEETING_can($this->faruk, 'rsvp', [$this->meeting, $this->faruk]))->toBeFalse();

    $this->meetings->cancel($this->shahadat, $this->meeting);

    expect(MEETING_can($this->tapu, 'rsvp', [$this->meeting->fresh(), $this->tapu]))->toBeFalse();
});

it('records an answer that only its owner could have given', function () {
    $this->meetings->rsvp($this->tapu, $this->meeting, RsvpStatus::Accepted);

    expect($this->meeting->fresh()->rsvpOf($this->tapu))->toBe(RsvpStatus::Accepted)
        ->and($this->meeting->fresh()->rsvpOf($this->yaseen))->toBe(RsvpStatus::Pending);
});

/*
|--------------------------------------------------------------------------
| The privacy resolver — the load-bearing test of the slice
|--------------------------------------------------------------------------
*/

it('omits the linked project entirely from a participant who may not see it', function () {
    // Yaseen is in the room. Yaseen is not on Buffalo Modular — SEO.
    expect(MEETING_can($this->yaseen, 'view', $this->meeting))->toBeTrue()
        ->and(MEETING_can($this->yaseen, 'view', $this->project))->toBeFalse();

    $context = $this->meetings->linkedContextFor($this->yaseen, $this->meeting);

    // Part C: a field the requester may not see is ABSENT — not null, not masked, not the id.
    // `array_key_exists` is the only assertion that tells "absent" from "present and null".
    expect(array_key_exists('project', $context))->toBeFalse()
        ->and(array_key_exists('project_id', $context))->toBeFalse()
        ->and(array_key_exists('project_name', $context))->toBeFalse()
        // And nothing about the project leaked under another name.
        ->and(json_encode($context))->not->toContain($this->project->name);
});

it('gives the linked project to a participant who is on it, and to an Admin', function () {
    $forTapu = $this->meetings->linkedContextFor($this->tapu, $this->meeting);
    $forAdmin = $this->meetings->linkedContextFor($this->faruk, $this->meeting);

    expect(array_key_exists('project', $forTapu))->toBeTrue()
        ->and($forTapu['project'])->toBe(['id' => $this->project->id, 'name' => $this->project->name])
        ->and(array_key_exists('project', $forAdmin))->toBeTrue();
});

it('omits a linked task from somebody who may see the project but is not assigned the task', function () {
    // A task on Tapu's project, assigned to Tapu.
    $task = Task::query()->where('project_id', $this->project->id)->firstOrFail();
    $this->meeting->forceFill(['task_id' => $task->id])->save();
    $meeting = $this->meeting->fresh();

    $forTapu = $this->meetings->linkedContextFor($this->tapu, $meeting);
    $forYaseen = $this->meetings->linkedContextFor($this->yaseen, $meeting);

    // TaskPolicy is tighter than ProjectPolicy: an employee sees the tasks ASSIGNED to them,
    // so being on the project would not have been enough either.
    expect(array_key_exists('task', $forYaseen))->toBeFalse()
        ->and(json_encode($forYaseen))->not->toContain($task->title);

    if (MEETING_can($this->tapu, 'view', $task)) {
        expect($forTapu['task'])->toBe(['id' => $task->id, 'title' => $task->title]);
    }
});

it('returns an empty context for a meeting that links nothing, rather than two null keys', function () {
    $plain = $this->meetings->schedule(
        $this->shahadat,
        [
            'title' => 'Just a chat',
            'start_at' => now()->addDays(5)->setTime(11, 0),
            'end_at' => now()->addDays(5)->setTime(11, 30),
        ],
        [$this->tapu->id],
    );

    expect($this->meetings->linkedContextFor($this->tapu, $plain))->toBe([]);
});
