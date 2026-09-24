<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\FileService;
use App\Services\MessageService;
use App\Services\ProjectService;
use App\Support\ConversationType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Messages, at the service — one door for five kinds of room
|--------------------------------------------------------------------------
|
| Phase 6's promise is that the other four conversation types are the SAME
| tables, the same writer and the same membership rule as the task discussion
| Phase 2 built. So the first thing worth asserting is that a message sends
| and reads in every one of them, and that who may do it comes from the same
| computed answer every time.
|
| Constants and functions in a Pest file are global, so everything here is
| prefixed MSG_.
|
*/

const MSG_BODY = 'The canonical tags are done on the county pages.';

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->conversations = app(ConversationService::class);
    $this->messages = app(MessageService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->tapusTask = Task::query()
        ->forEmployee($this->tapu->employee)
        ->notArchived()
        ->orderBy('id')
        ->firstOrFail();

    $this->tapusProject = Project::findOrFail($this->tapusTask->project_id);
});

/*
|--------------------------------------------------------------------------
| One message, in each of the four kinds of room
|--------------------------------------------------------------------------
*/

it('sends and reads a message in every conversation type', function (string $kind) {
    $conversation = msgRoom($this, $kind);

    $message = $this->messages->post($this->tapu, $conversation, MSG_BODY);

    expect($message->body)->toBe(MSG_BODY)
        ->and($message->conversation_id)->toBe($conversation->id);

    // Read back through the same service the screens read through.
    $thread = $this->conversations->messages($conversation->fresh());

    expect($thread)->toHaveCount(1)
        ->and($thread->first()->body)->toBe(MSG_BODY);
})->with([
    'team' => ['team'],
    'project' => ['project'],
    'task' => ['task'],
    'dm' => ['dm'],
])->group('phase6');

/**
 * One of each kind of room, from Tapu's point of view.
 */
function msgRoom($test, string $kind): Conversation
{
    return match ($kind) {
        'team' => $test->conversations->team(),
        'project' => $test->conversations->forProject($test->tapusProject),
        'task' => $test->conversations->forTask($test->tapusTask),
        'dm' => $test->conversations->dmBetween($test->tapu, $test->admin),
        'announcement' => $test->conversations->announcements(),
    };
}

/*
|--------------------------------------------------------------------------
| Where each type's audience comes from
|--------------------------------------------------------------------------
|
| This is the decision the phase turns on. Decision 2-24 said a task
| conversation's membership is COMPUTED and `conversation_members` grants
| nothing. Phase 6 keeps that sentence true of every type by giving each one a
| source that is not that table — see App\Support\ConversationType.
|
*/

it('lets everybody who may use messaging into the team channel', function () {
    $team = $this->conversations->team();

    expect($this->admin->can('view', $team))->toBeTrue()
        ->and($this->tapu->can('view', $team))->toBeTrue()
        ->and($this->yaseen->can('view', $team))->toBeTrue()
        // Not the Accountant, and not because anybody named them: they hold no `messages.use`.
        ->and($this->accountant->can('view', $team))->toBeFalse();
})->group('phase6');

it('lets a project channel follow project access, with no list synced anywhere', function () {
    $channel = $this->conversations->forProject($this->tapusProject);

    expect($this->tapu->can('view', $channel))->toBeTrue()
        ->and($this->admin->can('view', $channel))->toBeTrue();

    // Yaseen is on neither of Tapu's SEO projects, so ProjectPolicy::view says no — and that
    // is the whole of the channel's rule. Nothing was synced when the project was created and
    // nothing will be synced when its members change.
    expect($this->yaseen->can('view', $channel))->toBeFalse()
        ->and($this->accountant->can('view', $channel))->toBeFalse();

    expect(fn () => $this->messages->post($this->yaseen, $channel, 'Let me in'))
        ->toThrow(AuthorizationException::class);
})->group('phase6');

it('closes a project channel the moment somebody comes off the project', function () {
    $channel = $this->conversations->forProject($this->tapusProject);

    expect($this->tapu->can('view', $channel))->toBeTrue();

    // Off the project. No conversation was touched, no membership synced, nothing ran.
    $this->tapusProject->members()->detach($this->tapu->employee->getKey());

    expect($this->tapu->fresh()->can('view', $channel->fresh()))->toBeFalse();
})->group('phase6');

it('lets only the two people in a DM into it, from the columns on the row', function () {
    $dm = $this->conversations->dmBetween($this->tapu, $this->admin);

    expect($this->tapu->can('view', $dm))->toBeTrue()
        ->and($this->admin->can('view', $dm))->toBeTrue()
        // An Admin who can see every task, every project and every client in the agency can
        // not see two other people's DM.
        ->and($this->yaseen->can('view', $dm))->toBeFalse();

    // And the rule is the COLUMNS, not `conversation_members`: a row of exactly the shape a
    // departure leaves behind buys Yaseen nothing.
    $dm->members()->attach($this->yaseen->getKey(), ['last_read_at' => now()]);

    expect($this->yaseen->fresh()->can('view', $dm->fresh()))->toBeFalse();
})->group('phase6');

it('gives two people one DM however many times either of them opens it', function () {
    $first = $this->conversations->dmBetween($this->tapu, $this->admin);
    $second = $this->conversations->dmBetween($this->admin, $this->tapu);

    expect($second->id)->toBe($first->id)
        // Stored as an ordered pair, so "between A and B" is one row and not two.
        ->and((int) $first->dm_one_id)->toBeLessThan((int) $first->dm_two_id)
        ->and(Conversation::query()->dmsFor($this->tapu)->count())->toBe(1);
})->group('phase6');

it('refuses a DM with yourself', function () {
    expect($this->conversations->dmBetween($this->tapu, $this->tapu))->toBeNull();
})->group('phase6');

/*
|--------------------------------------------------------------------------
| Announcements: everybody reads, one permission writes
|--------------------------------------------------------------------------
*/

it('lets everybody read the announcements channel and only announcers post', function () {
    $channel = $this->conversations->announcements();

    expect($this->tapu->can('view', $channel))->toBeTrue()
        ->and($this->tapu->can('post', $channel))->toBeFalse()
        // `announcements.send` is Part C's own key, and the Admin is the only seeded holder.
        ->and($this->admin->can('post', $channel))->toBeTrue()
        ->and($this->accountant->can('view', $channel))->toBeFalse();

    expect(fn () => $this->messages->post($this->tapu, $channel, 'Free lunch, everybody'))
        ->toThrow(AuthorizationException::class);

    expect($this->messages->post($this->admin, $channel, 'Office closed on Thursday.')->body)
        ->toBe('Office closed on Thursday.');
})->group('phase6');

/*
|--------------------------------------------------------------------------
| Mentions
|--------------------------------------------------------------------------
*/

it('records a mention of somebody who can read the conversation and is named in the body', function () {
    $team = $this->conversations->team();

    $message = $this->messages->post(
        $this->admin,
        $team,
        '@'.$this->tapu->name.' can you look at the county pages?',
        mentionIds: [$this->tapu->id],
    );

    expect($message->mentions->pluck('id')->all())->toBe([$this->tapu->id]);
})->group('phase6');

it('drops a mention of somebody who cannot read the conversation', function () {
    // A project channel Yaseen is not in. The id is sent anyway, the way a hand-built request
    // would send it — and a mention row for somebody who cannot open the thread would be a
    // record nothing could ever act on, so none is written.
    $channel = $this->conversations->forProject($this->tapusProject);

    $message = $this->messages->post(
        $this->tapu,
        $channel,
        '@'.$this->yaseen->name.' take a look',
        mentionIds: [$this->yaseen->id],
    );

    expect($message->mentions)->toHaveCount(0);
})->group('phase6');

it('drops a mention the body does not actually make', function () {
    // `message_mentions` is the record that somebody was ADDRESSED, so it has to agree with
    // what every reader of the thread can see. An id without the text names nobody.
    $team = $this->conversations->team();

    $message = $this->messages->post(
        $this->admin,
        $team,
        'Somebody should look at the county pages.',
        mentionIds: [$this->tapu->id],
    );

    expect($message->mentions)->toHaveCount(0);
})->group('phase6');

it('treats a name in ordinary prose as no mention at all', function () {
    $team = $this->conversations->team();

    $message = $this->messages->post(
        $this->admin,
        $team,
        'Ask '.$this->tapu->name.' about it.',
        mentionIds: [$this->tapu->id],
    );

    expect($message->mentions)->toHaveCount(0);
})->group('phase6');

it('records one mention however many times a message names the same person', function () {
    $team = $this->conversations->team();
    $first = explode(' ', (string) $this->tapu->name)[0];

    $message = $this->messages->post(
        $this->admin,
        $team,
        "@{$first} — and again, @{$first}",
        mentionIds: [$this->tapu->id, $this->tapu->id],
    );

    expect($message->mentions)->toHaveCount(1);
})->group('phase6');

it('never records a mention of yourself', function () {
    $team = $this->conversations->team();

    $message = $this->messages->post(
        $this->admin,
        $team,
        '@'.$this->admin->name.' note to self',
        mentionIds: [$this->admin->id],
    );

    expect($message->mentions)->toHaveCount(0);
})->group('phase6');

it('never offers the accountant as somebody to mention, anywhere', function (string $kind) {
    // The picker's option list and the mention rule are the same set. The Accountant is absent
    // from every one of them without their role being named in any of the code that builds it.
    $conversation = msgRoom($this, $kind);

    expect($this->conversations->mentionableIn($conversation)->pluck('id')->all())
        ->not->toContain($this->accountant->id);
})->with([
    'team' => ['team'],
    'project' => ['project'],
    'task' => ['task'],
    'announcement' => ['announcement'],
])->group('phase6');

/*
|--------------------------------------------------------------------------
| Attachments, and the voice seam
|--------------------------------------------------------------------------
*/

it('stores an attachment on a channel message through FileService, like any other', function () {
    $team = $this->conversations->team();

    $message = $this->messages->post(
        $this->tapu,
        $team,
        'The crawl export.',
        UploadedFile::fake()->create('crawl.pdf', 30, 'application/pdf'),
    );

    $file = $message->attachments->first();

    expect($file->name)->toBe('crawl.pdf')
        ->and($file->message_id)->toBe($message->id)
        ->and($file->pivot->kind)->toBe('file')
        ->and($file->pivot->duration_seconds)->toBeNull();

    // A signed link into the application, never a bearer URL at the disk (decision 2-25).
    expect(app(FileService::class)->url($file))
        ->toContain('/files/')
        ->toContain('signature=');
})->group('phase6');

/*
|--------------------------------------------------------------------------
| The rooms themselves
|--------------------------------------------------------------------------
*/

it('gives a project its channel the moment it is created', function () {
    $project = app(ProjectService::class)->create($this->admin, [
        'client_id' => Project::query()->value('client_id'),
        'name' => 'A project born after Phase 6',
        'project_type' => $this->tapusProject->project_type,
        'billing_type' => $this->tapusProject->billing_type,
    ]);

    expect(Conversation::query()->forProject($project)->count())->toBe(1);
})->group('phase6');

it('has a channel for every project that existed before Phase 6', function () {
    // The backfill's result. Every project in the seeded database — archived ones included,
    // because an archived project's conversation is the history worth keeping readable.
    $projects = Project::query()->count();

    expect(Conversation::query()->ofType(ConversationType::Project)->count())->toBe($projects)
        ->and(Project::query()->whereNotIn(
            'id',
            Conversation::query()->ofType(ConversationType::Project)->select('linked_project_id'),
        )->count())->toBe(0);
})->group('phase6');

it('answers with the same room however many callers ask for it', function () {
    // Every `firstOrCreate` in ConversationService is behind a partial unique index, which is
    // what makes it safe to call from a controller, a seeder, a backfill or a job without any
    // of them knowing whether somebody else got there first.
    expect($this->conversations->team()->id)->toBe($this->conversations->team()->id)
        ->and($this->conversations->announcements()->id)->toBe($this->conversations->announcements()->id)
        ->and($this->conversations->forProject($this->tapusProject)->id)
        ->toBe($this->conversations->forProject($this->tapusProject)->id)
        ->and(Conversation::query()->ofType(ConversationType::Team)->count())->toBe(1)
        ->and(Conversation::query()->ofType(ConversationType::Announcement)->count())->toBe(1);
})->group('phase6');

/*
|--------------------------------------------------------------------------
| The inbox
|--------------------------------------------------------------------------
*/

it('lists only the conversations a person may actually open', function () {
    $this->conversations->dmBetween($this->tapu, $this->admin);

    $tapus = $this->conversations->inboxFor($this->tapu);
    $yaseens = $this->conversations->inboxFor($this->yaseen);

    // Tapu's SEO project is in his; it is not in Yaseen's, who is on neither.
    $channel = $this->conversations->forProject($this->tapusProject);

    expect($tapus->pluck('id')->all())->toContain($channel->id)
        ->and($yaseens->pluck('id')->all())->not->toContain($channel->id)
        // The DM is Tapu's and the Admin's, and nobody else's.
        ->and($yaseens->where('type', ConversationType::Dm))->toHaveCount(0)
        // The task discussions are not in anybody's: there is one per task, they are read
        // inside their task, and an inbox holding three hundred of them is not an inbox.
        ->and($tapus->where('type', ConversationType::Task))->toHaveCount(0);
})->group('phase6');

it('gives the accountant no inbox at all', function () {
    expect($this->conversations->inboxFor($this->accountant))->toHaveCount(0);
})->group('phase6');

it('counts what somebody has not read in each room, and never counts their own', function () {
    $team = $this->conversations->team();

    $this->messages->post($this->admin, $team, 'One');
    $this->messages->post($this->admin, $team, 'Two');
    $this->messages->post($this->tapu, $team, 'Mine');

    $counts = $this->conversations->unreadCounts($this->tapu, [$team]);

    // Two of the Admin's. Tapu's own is excluded, and `post()` moved his line to now — so the
    // count is what arrived before he spoke.
    expect($counts[$team->id] ?? 0)->toBe(0);

    $fresh = $this->conversations->unreadCounts($this->yaseen, [$team]);

    expect($fresh[$team->id] ?? 0)->toBe(3);
})->group('phase6');

it('keeps a member row as read state and nothing else, in every type', function () {
    $team = $this->conversations->team();

    Message::factory()->inConversation($team)->by($this->admin)->create();

    $this->conversations->markRead($this->tapu, $team);

    $state = $this->conversations->readState($this->tapu, $team);

    expect($state['last_read_at'])->not->toBeNull()
        ->and($state['unread_count'])->toBe(0)
        // And reading it changed nothing about what anybody may do.
        ->and($this->tapu->can('post', $team))->toBeTrue();
})->group('phase6');
