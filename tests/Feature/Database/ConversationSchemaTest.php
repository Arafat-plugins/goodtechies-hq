<?php

use App\Models\Conversation;
use App\Models\File;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
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

it('starts with exactly one team channel, one announcements channel and one per project', function () {
    // The Phase 6 backfill's result, and the shape the four partial unique indexes below
    // protect: one company-wide channel of each kind, and one per project including the ones
    // that predate the feature.
    expect(DB::table('conversations')->where('type', 'team')->count())->toBe(1)
        ->and(DB::table('conversations')->where('type', 'announcement')->count())->toBe(1)
        ->and(DB::table('conversations')->where('type', 'project')->count())
        ->toBe(Project::query()->count());
})->group('phase6');

it('refuses a second channel where only one can exist', function (string $type) {
    // Phase 2 promised Phase 6 would INSERT a row rather than alter a column, and it kept that
    // promise: `type` already knew all five values. What Phase 6 added is the uniqueness each
    // kind of channel needs — which is what makes every `firstOrCreate` in ConversationService
    // mean "the" literally, however many callers race for it.
    DB::table('conversations')->insert([
        'type' => $type,
        'linked_project_id' => $type === 'project' ? Project::query()->value('id') : null,
        'linked_task_id' => null,
        'title' => 'A second one',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->with([
    'team' => ['team'],
    'announcement' => ['announcement'],
    'project' => ['project'],
])->throws(QueryException::class)->group('phase6');

it('stores a DM as an ordered pair of columns on the conversation row', function () {
    // The Phase 6 membership decision, in the schema. A DM's two people are COLUMNS, not
    // `conversation_members` rows — which is what keeps "membership is computed, and the
    // membership table grants nothing" true of all five types with no exception.
    $ids = User::query()->orderBy('id')->limit(2)->pluck('id')->all();

    $id = DB::table('conversations')->insertGetId([
        'type' => 'dm',
        'linked_project_id' => null,
        'linked_task_id' => null,
        'dm_one_id' => $ids[0],
        'dm_two_id' => $ids[1],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($id)->toBeGreaterThan(0);
})->group('phase6');

it('refuses a second DM for a pair, however the pair is written', function (bool $reversed) {
    $ids = User::query()->orderBy('id')->limit(2)->pluck('id')->all();

    $row = fn (int $one, int $two): array => [
        'type' => 'dm',
        'linked_project_id' => null,
        'linked_task_id' => null,
        'dm_one_id' => $one,
        'dm_two_id' => $two,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('conversations')->insert($row($ids[0], $ids[1]));

    // Again as (A, B) is the unique index refusing it; again as (B, A) is the CHECK refusing
    // it, because the pair is ORDERED — so two people can never end up with two threads
    // between them by one of them clicking first.
    DB::table('conversations')->insert($reversed ? $row($ids[1], $ids[0]) : $row($ids[0], $ids[1]));
})->with([
    'the same way round' => [false],
    'the other way round' => [true],
])->throws(QueryException::class)->group('phase6');

it('refuses a DM that does not name two different people', function (string $shape) {
    $id = (int) User::query()->value('id');

    $pair = match ($shape) {
        'neither' => [null, null],
        'only one' => [$id, null],
        // A DM with yourself is not a conversation, and `dm_one_id < dm_two_id` says so.
        'the same person twice' => [$id, $id],
    };

    DB::table('conversations')->insert([
        'type' => 'dm',
        'linked_project_id' => null,
        'linked_task_id' => null,
        'dm_one_id' => $pair[0],
        'dm_two_id' => $pair[1],
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->with([
    'neither' => ['neither'],
    'only one' => ['only one'],
    'the same person twice' => ['the same person twice'],
])->throws(QueryException::class)->group('phase6');

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
