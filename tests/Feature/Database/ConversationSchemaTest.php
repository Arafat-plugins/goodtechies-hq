<?php

use App\Models\Conversation;
use App\Models\File;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Support\ConversationType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The communication tables
|--------------------------------------------------------------------------
|
| Phase 2 builds the minimum of them — text and file attachments, `task` type
| only — and Phase 6 adds the other types, voice, mentions and realtime on the
| same tables. So the thing worth asserting is not that today's feature works:
| it is that the SHAPE leaves room for that without a migration, and that the
| rules the shape depends on are the database's rather than a service's.
|
| Everything here goes through DB::table() where it can, because a constraint
| nobody has ever violated is indistinguishable from a comment.
|
*/

beforeEach(fn () => $this->seed());

it('has the columns the spec names', function () {
    expect(Schema::hasColumns('conversations', [
        'id', 'type', 'linked_project_id', 'linked_task_id', 'title', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('conversation_members', [
            'conversation_id', 'user_id', 'last_read_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('messages', [
            'id', 'conversation_id', 'author_id', 'body', 'created_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('message_attachments', [
            'message_id', 'file_id', 'kind', 'duration_seconds',
        ]))->toBeTrue();
})->group('phase2');

it('does not create task_comments', function () {
    // The recorded decision: one store for the discussion. The spec lists both a
    // `task_comments` table and a `task` conversation, and building both would mean two stores
    // and a migration later to fold one into the other.
    expect(Schema::hasTable('task_comments'))->toBeFalse();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Room for Phase 6, built for Phase 2
|--------------------------------------------------------------------------
*/

it('already accepts every conversation type Phase 6 will add', function (ConversationType $type) {
    // The point of this test is that Phase 6 inserts a row rather than altering a column. If
    // `type` had been a three-value enum or a narrower CHECK, this would fail — which is the
    // failure that would have cost that phase a migration.
    $id = DB::table('conversations')->insertGetId([
        'type' => $type->value,
        'linked_project_id' => $type === ConversationType::Project ? Project::query()->value('id') : null,
        'linked_task_id' => null,
        'title' => 'Probe',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($id)->toBeGreaterThan(0);
})->with([
    'team' => [ConversationType::Team],
    'project' => [ConversationType::Project],
    'dm' => [ConversationType::Dm],
    'announcement' => [ConversationType::Announcement],
])->group('phase2');

it('refuses a type nothing has ever defined', function () {
    DB::table('conversations')->insert([
        'type' => 'gossip',
        'linked_project_id' => null,
        'linked_task_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class)->group('phase2');

it('insists a conversation is linked through exactly the column its type names', function (array $row) {
    DB::table('conversations')->insert([
        'linked_project_id' => null,
        'linked_task_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$row,
    ]);
})->with([
    // A task conversation with no task: a discussion about nothing.
    'task without a task' => [['type' => 'task']],
    // A task conversation that also claims a project: a second link nothing would ever read,
    // and which every later query would have to defend against.
    'task with a project too' => [fn () => [
        'type' => 'task',
        'linked_task_id' => Task::query()->value('id'),
        'linked_project_id' => Project::query()->value('id'),
    ]],
    'project without a project' => [['type' => 'project']],
    // A team channel is about nothing in particular, so it may not carry a link.
    'team with a task' => [fn () => ['type' => 'team', 'linked_task_id' => Task::query()->value('id')]],
])->throws(QueryException::class)->group('phase2');

it('allows exactly one discussion per task', function () {
    $task = Task::factory()->create();
    $first = Conversation::factory()->forTask($task)->create();

    expect($first->exists)->toBeTrue();

    // `conversations_one_per_task` is what makes ConversationService::forTask() safe to call
    // from the seeder, the migration backfill and a controller without any of them knowing
    // whether somebody else already did.
    expect(fn () => DB::table('conversations')->insert([
        'type' => 'task',
        'linked_task_id' => $task->id,
        'linked_project_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The fourth owner of a file
|--------------------------------------------------------------------------
*/

it('lets a file belong to a message, and to nothing else at the same time', function () {
    $message = Message::factory()->create();
    $file = File::factory()->forMessage($message)->create();

    expect($file->message_id)->toBe($message->id)
        ->and($file->task_id)->toBeNull()
        ->and($file->owner()?->getKey())->toBe($message->id);

    // The arc is one term wider, not a term looser.
    expect(fn () => DB::table('files')->insert([
        'task_id' => Task::query()->value('id'),
        'message_id' => $message->id,
        'project_id' => null,
        'client_id' => null,
        'disk' => 'local',
        'path' => 'files/probe-'.uniqid().'.pdf',
        'name' => 'probe.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'size' => 10,
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->group('phase2');

it('refuses an attachment row whose file belongs to a different message', function () {
    // The two `message_id`s — one on `files`, one here — cannot disagree, because the primary
    // key is also a composite foreign key into `files (message_id, id)`. This is the only
    // reason the split between ownership and presentation is safe.
    $mine = Message::factory()->create();
    $theirs = Message::factory()->create();
    $file = File::factory()->forMessage($theirs)->create();

    DB::table('message_attachments')->insert([
        'message_id' => $mine->id,
        'file_id' => $file->id,
        'kind' => 'file',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class)->group('phase2');

it('accepts the attachment kinds Phase 6 needs and refuses the rest', function () {
    $message = Message::factory()->create();

    foreach (['file', 'image', 'voice'] as $kind) {
        $file = File::factory()->forMessage($message)->create();

        DB::table('message_attachments')->insert([
            'message_id' => $message->id,
            'file_id' => $file->id,
            'kind' => $kind,
            // A duration is a property of a recording, and of nothing else.
            'duration_seconds' => $kind === 'voice' ? 12 : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    expect(DB::table('message_attachments')->where('message_id', $message->id)->count())->toBe(3);

    $extra = File::factory()->forMessage($message)->create();

    expect(fn () => DB::table('message_attachments')->insert([
        'message_id' => $message->id,
        'file_id' => $extra->id,
        'kind' => 'hologram',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->group('phase2');

it('refuses a duration on anything that is not a voice note', function () {
    $message = Message::factory()->create();
    $file = File::factory()->forMessage($message)->create();

    DB::table('message_attachments')->insert([
        'message_id' => $message->id,
        'file_id' => $file->id,
        'kind' => 'image',
        'duration_seconds' => 30,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class)->group('phase2');
