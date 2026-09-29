<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Who marks a thread read — reliability slice 5, as superseded by messaging polish
|--------------------------------------------------------------------------
|
| Slice 5 gated mark-read on an `X-HQ-Focused` header. Messaging polish replaced that model
| (it wins on read state — see `MessageAttentionTest.php`):
|
| - Rendering `/messages` NEVER marks the open thread read: not a visit, not a browser load,
|   not the rail's or the shell's partial reload, whatever header they carry.
| - The thread marks itself: `GET /messages/{id}?read=1` marks, `?read=0` does not, `?before=`
|   never does, and no parameter keeps decision 6-31 (it marks).
| - The task discussion keeps "every fetch reads".
| - `X-HQ-Focused` is no longer read by the server at all.
|
| Constants and functions here are global in Pest, so they are prefixed FOCUS_.
|
*/

function FOCUS_lastRead(int $conversationId, int $userId): ?string
{
    $value = DB::table('conversation_members')
        ->where('conversation_id', $conversationId)
        ->where('user_id', $userId)
        ->value('last_read_at');

    return $value === null ? null : (string) $value;
}

function FOCUS_inertia(array $extra = []): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Requested-With' => 'XMLHttpRequest',
        ...$extra,
    ];
}

/** What `reload.ts` and the Messages rail send (the old focus flag optional, to prove it is ignored). */
function FOCUS_partial(?string $focused, string $only = 'conversations,announcement'): array
{
    $headers = [
        'X-Inertia-Partial-Component' => 'Shared/Messages',
        'X-Inertia-Partial-Data' => $only,
    ];

    if ($focused !== null) {
        $headers['X-HQ-Focused'] = $focused;
    }

    return FOCUS_inertia($headers);
}

beforeEach(function () {
    $this->seed();

    $this->conversations = app(ConversationService::class);
    $this->messages = app(MessageService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $this->team = $this->conversations->team();

    // Tapu has read up to a first message; a second one, a later second, is unread.
    $this->messages->post($this->admin, $this->team, 'Something to read.');
    $this->conversations->markRead($this->tapu, $this->team);
    $this->travel(2)->seconds();
    $this->messages->post($this->admin, $this->team, 'And another.');

    $this->before = FOCUS_lastRead($this->team->id, $this->tapu->id);

    expect($this->before)->not->toBeNull()
        ->and($this->conversations->readState($this->tapu, $this->team)['unread_count'])->toBe(1);

    $this->travel(2)->seconds();
});

/*
|--------------------------------------------------------------------------
| GET /messages — the page never marks
|--------------------------------------------------------------------------
*/

it('does not mark the open thread read on a background partial reload, focused or not', function (?string $focused) {
    $this->actingAs($this->tapu)
        ->get('/messages?conversation='.$this->team->id, FOCUS_partial($focused))
        ->assertOk();

    expect(FOCUS_lastRead($this->team->id, $this->tapu->id))->toBe($this->before)
        ->and($this->conversations->readState($this->tapu, $this->team)['unread_count'])->toBe(1);
})->with(['focused' => '1', 'unfocused' => '0', 'no flag' => null]);

it('does not mark it read on the shell poll either (partial reload of `shell` only)', function () {
    $this->actingAs($this->tapu)
        ->get('/messages?conversation='.$this->team->id, FOCUS_partial('1', 'shell'))
        ->assertOk();

    expect(FOCUS_lastRead($this->team->id, $this->tapu->id))->toBe($this->before);
});

it('does not mark it read on a visit either, Inertia or a full page load', function (bool $inertia) {
    $headers = $inertia ? FOCUS_inertia() : [];

    $this->actingAs($this->tapu)
        ->get('/messages?conversation='.$this->team->id, $headers)
        ->assertOk();

    expect(FOCUS_lastRead($this->team->id, $this->tapu->id))->toBe($this->before)
        ->and($this->conversations->readState($this->tapu, $this->team)['unread_count'])->toBe(1);
})->with(['inertia visit' => true, 'browser load' => false]);

it('keeps the unread counts in the rail as the server has them', function () {
    $rows = $this->actingAs($this->tapu)
        ->get('/messages?conversation='.$this->team->id, FOCUS_partial('1'))
        ->assertOk()
        ->json('props.conversations');

    $row = collect($rows)->firstWhere('id', $this->team->id);

    expect($row['unread_count'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| GET /messages/{conversation} — the thread decides, with `read`
|--------------------------------------------------------------------------
*/

it('does not mark read from the thread refresh with read=0, whatever the old header says', function () {
    $this->actingAs($this->tapu)
        ->getJson('/messages/'.$this->team->id.'?read=0', ['X-HQ-Focused' => '1'])
        ->assertOk();

    expect(FOCUS_lastRead($this->team->id, $this->tapu->id))->toBe($this->before);
});

it('marks read from the thread refresh with read=1', function () {
    $this->actingAs($this->tapu)
        ->getJson('/messages/'.$this->team->id.'?read=1')
        ->assertOk();

    expect($this->conversations->readState($this->tapu, $this->team)['unread_count'])->toBe(0);
});

it('never marks read while walking back through the history', function () {
    $newest = (int) DB::table('messages')->where('conversation_id', $this->team->id)->max('id');

    $this->actingAs($this->tapu)
        ->getJson('/messages/'.$this->team->id.'?before='.$newest)
        ->assertOk();

    expect(FOCUS_lastRead($this->team->id, $this->tapu->id))->toBe($this->before);
});

it('keeps decision 6-31: a plain GET with no parameter still marks read, and ignores the old header', function () {
    $this->actingAs($this->tapu)
        ->getJson('/messages/'.$this->team->id, ['X-HQ-Focused' => '0'])
        ->assertOk();

    expect($this->conversations->readState($this->tapu, $this->team)['unread_count'])->toBe(0);
});

it('does not let a read reach a conversation the policy refuses', function () {
    $tapusTask = Task::query()->forEmployee($this->tapu->employee)->notArchived()->orderBy('id')->firstOrFail();
    $channel = $this->conversations->forProject($tapusTask->project);

    $this->actingAs($this->yaseen)
        ->getJson('/messages/'.$channel->id.'?read=1')
        ->assertNotFound();

    $this->actingAs($this->yaseen)
        ->postJson('/messages/'.$channel->id.'/read', ['through' => 1])
        ->assertNotFound();

    $this->actingAs($this->yaseen)
        ->get('/messages?conversation='.$channel->id, FOCUS_partial('1'))
        ->assertOk();

    expect(FOCUS_lastRead($channel->id, $this->yaseen->id))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The task discussion — the old rule: every fetch reads
|--------------------------------------------------------------------------
*/

it('marks the task discussion read on every fetch, focused or not', function () {
    $task = Task::query()->forEmployee($this->tapu->employee)->notArchived()->orderBy('id')->firstOrFail();
    $conversation = $this->conversations->forTask($task);

    $this->messages->post($this->admin, $conversation, 'On the task.');
    $this->travel(2)->seconds();

    expect($this->conversations->readState($this->tapu, $conversation)['unread_count'])->toBe(1);

    $this->actingAs($this->tapu)
        ->getJson('/employee/tasks/'.$task->id.'/discussion', ['X-HQ-Focused' => '0'])
        ->assertOk();

    expect($this->conversations->readState($this->tapu, $conversation)['unread_count'])->toBe(0);
});
