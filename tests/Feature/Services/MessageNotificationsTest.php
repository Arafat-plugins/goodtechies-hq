<?php

use App\Models\Conversation;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use App\Services\SettingsService;
use App\Support\NotificationTab;
use App\Support\NotificationType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| What a message tells people, and what it deliberately does not
|--------------------------------------------------------------------------
|
| Three types land on the Messages tab and there is a fourth thing this phase
| does on purpose: **a message in the team or a project channel notifies
| nobody**. Five to fifteen people are replacing a Telegram group with the
| Messages page open all day; a bell row per channel line is a bell nobody
| reads by Tuesday. If you want somebody's attention in a channel you name
| them, which is what makes an @mention worth typing.
|
| NotificationService is still the only thing that writes a notification, and
| every recipient still passes the same two filters: the type's required
| permission, and `view` on the object.
|
| Constants here are global in Pest, so they are prefixed MENTION_.
|
*/

const MENTION_TAB = 'messages';

beforeEach(function () {
    $this->seed();

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

    // These tests count rows absolutely, and the seeder leaves a demo thread on some tasks.
    Notification::query()->delete();
});

/**
 * Every notification of one type belonging to one person.
 *
 * @return Collection<int, Notification>
 */
function mentionRows(User $user, NotificationType $type)
{
    return Notification::query()
        ->where('user_id', $user->getKey())
        ->where('type', $type->value)
        ->get();
}

/*
|--------------------------------------------------------------------------
| A mention
|--------------------------------------------------------------------------
*/

it('notifies somebody who is named in a message', function () {
    $team = $this->conversations->team();

    $this->messages->post(
        $this->admin,
        $team,
        '@'.$this->tapu->name.' can you take the county pages?',
        mentionIds: [$this->tapu->id],
    );

    $rows = mentionRows($this->tapu, NotificationType::MessageMentioned);

    expect($rows)->toHaveCount(1)
        // The sentence says WHO named you, which is the first thing you want and the thing
        // that tells a mention apart from the comment notification it sits beside.
        ->and($rows->first()->summary())->toContain($this->admin->name)
        ->and($rows->first()->summary())->toContain('mentioned you')
        // Its tab is Messages, which was empty in Phases 2–5.
        ->and($rows->first()->type->tab())->toBe(NotificationTab::Messages)
        // And it deep-links to the conversation, resolved at read time against the reader's
        // own surface — a shared route, so both surfaces reach it at the same URL.
        ->and($rows->first()->target()['type'])->toBe(Conversation::class);
})->group('phase6');

it('tells nobody but the people a channel message named', function () {
    $team = $this->conversations->team();

    $this->messages->post(
        $this->admin,
        $team,
        '@'.$this->tapu->name.' one for you',
        mentionIds: [$this->tapu->id],
    );

    // Tapu was named. Yaseen, who can read the team channel perfectly well, was not — and gets
    // nothing, which is the whole anti-flood design. His unread count on the channel is what
    // tells him there is something there.
    expect(mentionRows($this->tapu, NotificationType::MessageMentioned))->toHaveCount(1)
        ->and(Notification::query()->where('user_id', $this->yaseen->id)->count())->toBe(0);
})->group('phase6');

it('writes no notification at all for an ordinary channel message', function (string $kind) {
    $conversation = $kind === 'team'
        ? $this->conversations->team()
        : $this->conversations->forProject($this->tapusProject);

    $this->messages->post($this->admin, $conversation, 'Morning, all.');

    expect(Notification::query()->count())->toBe(0);
})->with([
    'the team channel' => ['team'],
    'a project channel' => ['project'],
])->group('phase6');

it('never lets a mention reach somebody who cannot read the conversation', function () {
    // The id is sent, the body names him, and he still gets nothing: MessageService refuses to
    // write the mention row at all, and `NotificationType::requires()` would drop it anyway.
    $channel = $this->conversations->forProject($this->tapusProject);

    $this->messages->post(
        $this->tapu,
        $channel,
        '@'.$this->yaseen->name.' look at this',
        mentionIds: [$this->yaseen->id],
    );

    expect(Notification::query()->where('user_id', $this->yaseen->id)->count())->toBe(0);
})->group('phase6');

it('never puts a message row in the accountant mailbox', function () {
    // The Accountant has had a mailbox since Phase 5 (decision 5-14) and it holds leave rows.
    // It can never hold a message row: every messaging type requires `messages.use`, which
    // they do not hold — and nobody was named to arrange that.
    $team = $this->conversations->team();

    $this->messages->post(
        $this->admin,
        $team,
        '@'.$this->accountant->name.' invoices?',
        mentionIds: [$this->accountant->id],
    );

    expect(Notification::query()->where('user_id', $this->accountant->id)->count())->toBe(0);

    foreach ([
        NotificationType::MessageReceived,
        NotificationType::MessageMentioned,
        NotificationType::AnnouncementPosted,
    ] as $type) {
        expect($this->accountant->hasPermission($type->requires()))->toBeFalse();
    }
})->group('phase6');

/*
|--------------------------------------------------------------------------
| A mention in a task discussion, and the row it replaces
|--------------------------------------------------------------------------
*/

it('gives somebody mentioned in a comment one row and not two', function () {
    // Tapu is an assignee of this task, so a comment on it would ordinarily notify him. He is
    // also named in it. He gets the MENTION — which says more — and not the comment as well:
    // two rows in one bell about one sentence would be saying the weaker thing twice.
    $conversation = $this->conversations->forTask($this->tapusTask);

    $this->messages->post(
        $this->admin,
        $conversation,
        '@'.$this->tapu->name.' is this one done?',
        mentionIds: [$this->tapu->id],
    );

    expect(mentionRows($this->tapu, NotificationType::MessageMentioned))->toHaveCount(1)
        ->and(mentionRows($this->tapu, NotificationType::TaskCommented))->toHaveCount(0);
})->group('phase6');

it('still tells everybody else on the task there is a new comment', function () {
    // The drop is at the RECIPIENT list, not by suppressing the type — so naming one person
    // does not silence the thread for the rest of it.
    $conversation = $this->conversations->forTask($this->tapusTask);

    // A second assignee who is not named, plus the person who asked for the task.
    $creator = User::findOrFail($this->tapusTask->created_by);

    $this->messages->post(
        $creator,
        $conversation,
        '@'.$this->tapu->name.' one for you',
        mentionIds: [$this->tapu->id],
    );

    $this->messages->post($creator, $conversation, 'And an ordinary comment.');

    expect(mentionRows($this->tapu, NotificationType::MessageMentioned))->toHaveCount(1)
        ->and(mentionRows($this->tapu, NotificationType::TaskCommented))->toHaveCount(1);
})->group('phase6');

/*
|--------------------------------------------------------------------------
| A DM
|--------------------------------------------------------------------------
*/

it('tells the other half of a DM, and only them', function () {
    $dm = $this->conversations->dmBetween($this->admin, $this->tapu);

    $this->messages->post($this->admin, $dm, 'Got a minute?');

    $rows = mentionRows($this->tapu, NotificationType::MessageReceived);

    expect($rows)->toHaveCount(1)
        // The title is the sender's name, which is what a DM is called from the receiving end.
        ->and($rows->first()->summary())->toBe('New message from '.$this->admin->name)
        ->and(Notification::query()->where('user_id', $this->admin->id)->count())->toBe(0)
        ->and(Notification::query()->where('user_id', $this->yaseen->id)->count())->toBe(0);
})->group('phase6');

it('gives one row for a DM that also names its recipient', function () {
    $dm = $this->conversations->dmBetween($this->admin, $this->tapu);

    $this->messages->post(
        $this->admin,
        $dm,
        '@'.$this->tapu->name.' got a minute?',
        mentionIds: [$this->tapu->id],
    );

    expect(mentionRows($this->tapu, NotificationType::MessageMentioned))->toHaveCount(1)
        ->and(mentionRows($this->tapu, NotificationType::MessageReceived))->toHaveCount(0);
})->group('phase6');

/*
|--------------------------------------------------------------------------
| An announcement
|--------------------------------------------------------------------------
*/

it('tells everybody who can read the announcements channel', function () {
    $channel = $this->conversations->announcements();

    $this->messages->post($this->admin, $channel, 'The office is closed on Thursday.');

    expect(mentionRows($this->tapu, NotificationType::AnnouncementPosted))->toHaveCount(1)
        ->and(mentionRows($this->yaseen, NotificationType::AnnouncementPosted))->toHaveCount(1)
        // Not the sender, and not the Accountant.
        ->and(Notification::query()->where('user_id', $this->admin->id)->count())->toBe(0)
        ->and(Notification::query()->where('user_id', $this->accountant->id)->count())->toBe(0)
        // Nothing of what was said is in the payload: it is one click away, in a conversation
        // that re-checks the policy.
        ->and(mentionRows($this->tapu, NotificationType::AnnouncementPosted)->first()->payload)
        ->not->toHaveKey('body');
})->group('phase6');

/*
|--------------------------------------------------------------------------
| Grouping — decision 2-33, on the new types
|--------------------------------------------------------------------------
*/

it('groups a burst of direct messages into one row with a count', function () {
    $dm = $this->conversations->dmBetween($this->admin, $this->tapu);

    for ($i = 1; $i <= 12; $i++) {
        $this->messages->post($this->admin, $dm, 'Message number '.$i);
    }

    $rows = mentionRows($this->tapu, NotificationType::MessageReceived);

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->count)->toBe(12)
        // The grouped wording is the visible half of the rule, written by the server.
        ->and($rows->first()->summary())->toBe('12 new messages from '.$this->admin->name);
})->group('phase6');

it('groups a burst of mentions in one conversation', function () {
    $team = $this->conversations->team();

    for ($i = 1; $i <= 3; $i++) {
        $this->messages->post(
            $this->admin,
            $team,
            '@'.$this->tapu->name.' number '.$i,
            mentionIds: [$this->tapu->id],
        );
    }

    $rows = mentionRows($this->tapu, NotificationType::MessageMentioned);

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->count)->toBe(3)
        ->and($rows->first()->summary())->toContain('3 mentions of you');
})->group('phase6');

it('does not let a row the recipient has already read absorb the next message', function () {
    // Decision 2-33's second half, which is this codebase's rule rather than the spec's:
    // reading "3 new messages" must not silently swallow the fourth. Reading closes the group.
    $dm = $this->conversations->dmBetween($this->admin, $this->tapu);

    $this->messages->post($this->admin, $dm, 'One');

    Notification::query()->where('user_id', $this->tapu->id)->update([
        'is_read' => true,
        'read_at' => now(),
    ]);

    $this->messages->post($this->admin, $dm, 'Two');

    $rows = mentionRows($this->tapu, NotificationType::MessageReceived);

    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn (Notification $row): bool => (int) $row->count === 1))->toBeTrue();
})->group('phase6');

it('keeps the twelve-comments-one-row rule intact on a task discussion', function () {
    // The Phase 2 example, re-asserted here because Phase 6 changed the event it travels on.
    // Twelve real comments are still one row reading "12 new comments in …".
    $conversation = $this->conversations->forTask($this->tapusTask);
    $creator = User::findOrFail($this->tapusTask->created_by);

    for ($i = 1; $i <= 12; $i++) {
        $this->messages->post($creator, $conversation, 'Comment number '.$i);
    }

    $rows = mentionRows($this->tapu, NotificationType::TaskCommented);

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->count)->toBe(12)
        ->and($rows->first()->summary())->toContain('12 new comments');
})->group('phase6');

it('stops grouping messages once the window has passed', function () {
    $dm = $this->conversations->dmBetween($this->admin, $this->tapu);
    $minutes = (int) app(SettingsService::class)->get('notification_group_window_minutes');

    $this->messages->post($this->admin, $dm, 'One');

    // Older than the window, by the same setting the engine reads on every call.
    DB::table('notifications')
        ->where('user_id', $this->tapu->id)
        ->update(['created_at' => now()->subMinutes($minutes + 1)]);

    $this->messages->post($this->admin, $dm, 'Two');

    expect(mentionRows($this->tapu, NotificationType::MessageReceived))->toHaveCount(2);
})->group('phase6');

/*
|--------------------------------------------------------------------------
| The catalogue and the CHECK constraint (decision 3-6)
|--------------------------------------------------------------------------
*/

it('lets the notifications table hold every type the enum knows', function () {
    // The trap decision 3-6 says will be stepped in again: the Phase 2 migration generated
    // `notifications_type_is_known` from the enum AT THE TIME IT RAN, so a migration has to
    // rewrite it whenever a phase adds a type. Without it the client's own database refuses
    // the first @mention while every test on a fresh database passes.
    foreach (NotificationType::cases() as $type) {
        $id = DB::table('notifications')->insertGetId([
            'user_id' => $this->tapu->id,
            'type' => $type->value,
            'payload' => json_encode(['title' => 'Probe']),
            'group_key' => 'probe:'.$type->value,
            'count' => 1,
            'is_read' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect($id)->toBeGreaterThan(0);
    }
})->group('phase6');

it('puts the three messaging types on the Messages tab and nowhere else', function () {
    $onTab = array_map(
        fn (NotificationType $type): string => $type->value,
        NotificationTab::Messages->types(),
    );

    expect($onTab)->toEqualCanonicalizing([
        'message.received', 'message.mentioned', 'announcement.posted',
    ])
        // The tab was empty until this phase, and the Center said so; it is built now.
        ->and(NotificationTab::Messages->isBuilt())->toBeTrue()
        ->and(NotificationTab::Messages->value)->toBe(MENTION_TAB);
})->group('phase6');
