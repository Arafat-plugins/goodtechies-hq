<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The thread's context panel
|--------------------------------------------------------------------------
|
| `GET /messages/{conversation}/context` is what sits beside a thread: who is
| in the room, what has been posted into it, and the work it is about.
|
| Two rules are asserted here more than once, because they are the ones a
| redesign would quietly break:
|
|   - `project` and `tasks` are ABSENT when there is no project or the reader
|     may not see it. Not null. A null says "there is a project here and you
|     may not have it", which is the sentence Part C exists to avoid.
|   - reading the panel does not mark the thread read. A side panel is not a
|     visit, and one that moved the unread line would make the list row
|     disagree with what the reader has actually seen.
|
| Constants here are global in Pest, so they are prefixed CONVERSATION_CONTEXT_.
|
*/

const CONVERSATION_CONTEXT_URL = '/messages';

function conversation_context_path(int $id): string
{
    return CONVERSATION_CONTEXT_URL.'/'.$id.'/context';
}

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->conversations = app(ConversationService::class);
    $this->messages = app(MessageService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->team = $this->conversations->team();

    $this->tapusTask = Task::query()
        ->forEmployee($this->tapu->employee)
        ->notArchived()
        ->orderBy('id')
        ->firstOrFail();

    $this->tapusProject = Project::findOrFail($this->tapusTask->project_id);
    $this->projectChannel = $this->conversations->forProject($this->tapusProject);
});

/*
|--------------------------------------------------------------------------
| The team channel: members, and no project key at all
|--------------------------------------------------------------------------
*/

it('lists who is in the team channel and carries no project key whatsoever', function () {
    $response = $this->actingAs($this->tapu)
        ->getJson(conversation_context_path($this->team->id))
        ->assertOk();

    $payload = $response->json();

    expect(array_keys($payload))->toEqualCanonicalizing([
        'conversation_id', 'type', 'members', 'files',
    ])
        ->and($payload['conversation_id'])->toBe($this->team->id)
        ->and($payload['type'])->toBe('team')
        // The KEY is gone, not set to null.
        ->and(array_key_exists('project', $payload))->toBeFalse()
        ->and(array_key_exists('tasks', $payload))->toBeFalse();

    $response->assertJsonMissingPath('project')->assertJsonMissingPath('tasks');

    $ids = array_column($payload['members'], 'id');

    expect($ids)->toContain($this->admin->id)
        ->toContain($this->yaseen->id)
        // The requester is in it: the panel answers "who is in this room".
        ->toContain($this->tapu->id)
        // The Accountant holds no `messages.use`, so they are in no room — and no line of this
        // application had to name them to say so.
        ->not->toContain($this->accountant->id);
})->group('phase6');

it('sends a name and an id per member and nothing else', function () {
    // A members list is not a second, unpoliced copy of the Team directory's payload. No role,
    // no availability, no email.
    $members = $this->actingAs($this->tapu)
        ->getJson(conversation_context_path($this->team->id))
        ->assertOk()
        ->json('members');

    foreach ($members as $member) {
        expect(array_keys($member))->toEqualCanonicalizing(['id', 'name']);
    }
})->group('phase6');

it('shrinks the member list to two people in a DM', function () {
    $dm = $this->conversations->dmBetween($this->tapu, $this->admin);

    $payload = $this->actingAs($this->tapu)
        ->getJson(conversation_context_path($dm->id))
        ->assertOk()
        ->json();

    expect(array_column($payload['members'], 'id'))
        ->toEqualCanonicalizing([$this->tapu->id, $this->admin->id])
        ->and(array_key_exists('project', $payload))->toBeFalse();
})->group('phase6');

/*
|--------------------------------------------------------------------------
| A project channel: the project, and only the tasks this reader may see
|--------------------------------------------------------------------------
*/

it('carries the project and its tasks on a project channel', function () {
    $payload = $this->actingAs($this->tapu)
        ->getJson(conversation_context_path($this->projectChannel->id))
        ->assertOk()
        ->json();

    expect(array_keys($payload))->toEqualCanonicalizing([
        'conversation_id', 'type', 'members', 'files', 'project', 'tasks',
    ])
        ->and(array_keys($payload['project']))->toEqualCanonicalizing(['id', 'name', 'status', 'href'])
        ->and($payload['project']['id'])->toBe($this->tapusProject->id)
        ->and($payload['project']['name'])->toBe($this->tapusProject->name)
        ->and($payload['project']['status'])->toBe($this->tapusProject->status->value)
        // Into the shell this reader is actually in. An employee sent to /admin/... would meet
        // a 403 dressed up as a link.
        ->and($payload['project']['href'])->toBe('/employee/projects/'.$this->tapusProject->id)
        ->and(array_column($payload['tasks'], 'id'))->toContain($this->tapusTask->id);

    foreach ($payload['tasks'] as $task) {
        expect(array_keys($task))->toEqualCanonicalizing(['id', 'title', 'status', 'href'])
            ->and($task['href'])->toBe('/employee/tasks/'.$task['id']);
    }
})->group('phase6');

it('links an admin into the admin shell instead', function () {
    $href = $this->actingAs($this->admin)
        ->getJson(conversation_context_path($this->projectChannel->id))
        ->assertOk()
        ->json('project.href');

    expect($href)->toBe('/admin/projects/'.$this->tapusProject->id);
})->group('phase6');

it('lists only the tasks this reader may see, not every task on the project', function () {
    // Being on a project channel is not being on every task in it. Task::visibleTo() is the
    // narrower question underneath ProjectPolicy::view, and the panel is a shortcut into work
    // rather than a second, unpoliced copy of the Tasks list.
    $someoneElses = Task::factory()
        ->assignedTo($this->yaseen->employee)
        ->create(['project_id' => $this->tapusProject->getKey()]);

    $mine = $this->actingAs($this->tapu)
        ->getJson(conversation_context_path($this->projectChannel->id))
        ->assertOk()
        ->json('tasks');

    expect(array_column($mine, 'id'))
        ->not->toContain($someoneElses->id)
        ->toContain($this->tapusTask->id);

    // The Admin sees the whole agency, so the same channel answers them differently — which is
    // the proof that the list is scoped by the reader and not by the project.
    $theirs = $this->actingAs($this->admin)
        ->getJson(conversation_context_path($this->projectChannel->id))
        ->assertOk()
        ->json('tasks');

    expect(array_column($theirs, 'id'))->toContain($someoneElses->id);
})->group('phase6');

/*
|--------------------------------------------------------------------------
| Attachments
|--------------------------------------------------------------------------
*/

it('lists an attachment posted into the conversation, with its kind', function () {
    $this->messages->post(
        $this->tapu,
        $this->team,
        'The crawl.',
        UploadedFile::fake()->create('crawl.pdf', 20, 'application/pdf'),
    );

    $this->messages->post(
        $this->tapu,
        $this->team,
        'And a screenshot.',
        UploadedFile::fake()->image('serp.png'),
    );

    $files = $this->actingAs($this->tapu)
        ->getJson(conversation_context_path($this->team->id))
        ->assertOk()
        ->json('files');

    expect($files)->toHaveCount(2)
        // Newest first.
        ->and($files[0]['name'])->toBe('serp.png')
        // Read off the `message_attachments` pivot, not re-derived here.
        ->and($files[0]['kind'])->toBe('image')
        ->and($files[1]['name'])->toBe('crawl.pdf')
        ->and($files[1]['kind'])->toBe('file')
        // Through FileResource unchanged: a signed link into the application, re-checked by
        // FilePolicy on every fetch, and the same permissions block the bubble gets.
        ->and($files[0]['url'])->toContain('/files/')
        ->and($files[0]['url'])->toContain('signature=')
        ->and($files[0])->toHaveKey('permissions')
        ->and($files[0])->not->toHaveKey('path')
        ->and($files[0])->not->toHaveKey('disk');
})->group('phase6');

it('sends an empty file list for a conversation nobody has attached anything to', function () {
    expect($this->actingAs($this->tapu)
        ->getJson(conversation_context_path($this->team->id))
        ->assertOk()
        ->json('files'))->toBe([]);
})->group('phase6');

/*
|--------------------------------------------------------------------------
| Refusals: 404 for the record, 403 for the capability
|--------------------------------------------------------------------------
*/

it('answers 404 for a project channel the requester is not on', function () {
    $this->actingAs($this->yaseen)
        ->getJson(conversation_context_path($this->projectChannel->id))
        ->assertNotFound();
})->group('phase6');

it('answers 404 for a DM between two other people, even to an admin', function () {
    $dm = $this->conversations->dmBetween($this->tapu, $this->yaseen);

    $this->actingAs($this->admin)
        ->getJson(conversation_context_path($dm->id))
        ->assertNotFound();
})->group('phase6');

it('answers 404 for a conversation id that does not exist', function () {
    // The same number as "not yours", which is the point: the requester never learns which of
    // the two it was (Part C).
    $this->actingAs($this->tapu)
        ->getJson(conversation_context_path(999999))
        ->assertNotFound();
})->group('phase6');

it('refuses the accountant, because the route group is gated on messages.use', function () {
    $this->actingAs($this->accountant)
        ->getJson(conversation_context_path($this->team->id))
        ->assertForbidden();
})->group('phase6');

it('sends a guest to the login page', function () {
    $this->get(conversation_context_path($this->team->id))->assertRedirect('/login');
})->group('phase6');

/*
|--------------------------------------------------------------------------
| A panel is not a visit
|--------------------------------------------------------------------------
*/

it('does not move the unread line', function () {
    $this->messages->post($this->admin, $this->team, 'Something to read.');
    $this->conversations->markRead($this->tapu, $this->team);

    // Both timestamp columns are second-precision, so the second message has to land in a
    // later second than the read line or it is not unread at all and this test would pass
    // while proving nothing.
    $this->travel(2)->seconds();

    $this->messages->post($this->admin, $this->team, 'And another.');

    $before = DB::table('conversation_members')
        ->where('conversation_id', $this->team->id)
        ->where('user_id', $this->tapu->id)
        ->value('last_read_at');

    expect($before)->not->toBeNull()
        ->and($this->conversations->readState($this->tapu, $this->team)['unread_count'])->toBe(1);

    $this->actingAs($this->tapu)
        ->getJson(conversation_context_path($this->team->id))
        ->assertOk();

    $after = DB::table('conversation_members')
        ->where('conversation_id', $this->team->id)
        ->where('user_id', $this->tapu->id)
        ->value('last_read_at');

    expect($after)->toBe($before)
        ->and($this->conversations->readState($this->tapu, $this->team)['unread_count'])->toBe(1);
})->group('phase6');

it('does not create a read-state row for somebody who has never opened the thread', function () {
    $this->messages->post($this->admin, $this->team, 'Hello everybody.');

    $this->actingAs($this->yaseen)
        ->getJson(conversation_context_path($this->team->id))
        ->assertOk();

    expect(DB::table('conversation_members')
        ->where('conversation_id', $this->team->id)
        ->where('user_id', $this->yaseen->id)
        ->exists())->toBeFalse();
})->group('phase6');
