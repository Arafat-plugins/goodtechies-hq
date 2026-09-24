<?php

use App\Models\Conversation;
use App\Models\Task;
use App\Models\User;
use App\Support\UserStatus;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Broadcast authorisation — the security test of Phase 6
|--------------------------------------------------------------------------
|
| Part E, Phase 6: "non-member cannot subscribe to a channel (403 from
| Laravel's broadcast auth)". Three channels, and for each one a person the
| policy allows, a person it does not, and the Accountant.
|
| A channel is a second door into the same rooms, so the answers here have to
| be the answers the first door gives. They are, because the callbacks in
| `app/Broadcasting/` ask the SAME policies the HTTP routes ask
| (`routes/channels.php` lists which):
|
|   conversation.{id}   → ConversationPolicy::view     (decision 2-24)
|   notifications.{id}  → NotificationPolicy::viewAny + "is this channel yours"
|   task.{id}           → TaskPolicy::view
|
| ## Why this file pins the connection to `reverb`
|
| **Laravel's `log` and `null` broadcasters do not authorise at all** — both
| override `auth()` with an empty body, so `POST /broadcasting/auth` answers an
| empty 200 to anybody signed in, whatever channel they name. `phpunit.xml`
| pins `null` (correctly: a test that means to assert a broadcast fakes the
| dispatcher rather than writing to a log file), so a test of the callbacks
| that did not say otherwise would be testing nothing and passing.
|
| That is not a hole in the fallback deployment — with `BROADCAST_CONNECTION=log`
| there is no Reverb to connect to, the endpoint hands back an empty body and
| the bell is polling — but it IS the reason this file configures the real
| connection before it asks a question about authorisation.
|
| `socket_id` is the format Pusher's protocol requires (`\d+\.\d+`); the
| broadcaster refuses a malformed one before any callback is reached, which
| would have made every row below pass for the wrong reason.
|
*/

const REALTIME_SOCKET_ID = '12345.67890';

/** POST the handshake Echo sends, as this user. */
function realtimeAuth(?User $user, string $channel): TestResponse
{
    /** @var TestCase $test */
    $test = test();

    if ($user !== null) {
        $test->actingAs($user);
    }

    return $test->postJson('/broadcasting/auth', [
        'socket_id' => REALTIME_SOCKET_ID,
        'channel_name' => $channel,
    ]);
}

beforeEach(function () {
    $this->seed();

    // The real connection. See the file header: `null` and `log` authorise nothing.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-app-key',
        'broadcasting.connections.reverb.secret' => 'test-app-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app-id',
    ]);

    // …and the channels have to be registered onto it. `Broadcast::channel()` is not a central
    // registry: it forwards to whichever driver is the default WHEN IT RUNS, and
    // `bootstrap/app.php` ran `routes/channels.php` at boot, against the `null` driver
    // phpunit.xml pins. Switching the default above makes a fresh driver that knows no
    // channels, and an unknown channel is refused — so without this line every row in this
    // file would answer 403 and half of them would pass for entirely the wrong reason. (It
    // did, before this comment existed.)
    require base_path('routes/channels.php');

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    // One of Tapu's own tasks: he is assigned, Yaseen is not, and the Accountant holds no
    // tasks.* key at all — the three cases every row below needs.
    $this->task = Task::query()->forEmployee($this->tapu->employee)->notArchived()->orderBy('id')->firstOrFail();
    $this->conversation = Conversation::query()->where('linked_task_id', $this->task->getKey())->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| task.{task} — TaskPolicy::view
|--------------------------------------------------------------------------
*/

it('lets an assignee subscribe to their own task channel', function () {
    realtimeAuth($this->tapu, 'private-task.'.$this->task->getKey())->assertOk();
})->group('phase6', 'realtime');

it('refuses an employee who is not assigned to the task', function () {
    // Yaseen may hold `tasks.view`, and that is not enough: TaskPolicy::view wants assignment
    // for an employee, and the channel asks the policy rather than the permission.
    realtimeAuth($this->yaseen, 'private-task.'.$this->task->getKey())->assertForbidden();
})->group('phase6', 'realtime');

it('refuses the Accountant on a task channel', function () {
    realtimeAuth($this->accountant, 'private-task.'.$this->task->getKey())->assertForbidden();
})->group('phase6', 'realtime');

it('refuses a task channel whose task does not exist', function () {
    // Implicit binding: an id that resolves to nothing is 403 before join() runs. A channel
    // name is not a record — the subscriber supplied it — so there is nothing to conceal by
    // answering 404, and broadcast auth has no 404 to give.
    realtimeAuth($this->admin, 'private-task.999999')->assertForbidden();
})->group('phase6', 'realtime');

/*
|--------------------------------------------------------------------------
| notifications.{user} — NotificationPolicy::viewAny, plus "is this you"
|--------------------------------------------------------------------------
*/

it('lets somebody subscribe to their own notification channel', function () {
    realtimeAuth($this->tapu, 'private-notifications.'.$this->tapu->getKey())->assertOk();
})->group('phase6', 'realtime');

it('refuses somebody else\'s notification channel, even an Admin\'s', function () {
    // The widest role in the application, on one line of somebody else's mail. The HTTP route
    // answers 404 for the same reason; a channel has only 403 to say.
    realtimeAuth($this->admin, 'private-notifications.'.$this->tapu->getKey())->assertForbidden();
    realtimeAuth($this->tapu, 'private-notifications.'.$this->admin->getKey())->assertForbidden();
})->group('phase6', 'realtime');

it('gives the Accountant their own notification channel and no other', function () {
    // Decision 5-14: they have a mailbox because `leave.apply` is a key Part C gives every
    // role, so NotificationPolicy::viewAny stops refusing them — here as well as over HTTP,
    // with nothing carved out in either place.
    realtimeAuth($this->accountant, 'private-notifications.'.$this->accountant->getKey())->assertOk();
    realtimeAuth($this->accountant, 'private-notifications.'.$this->admin->getKey())->assertForbidden();
})->group('phase6', 'realtime');

it('refuses a notification channel for a user who does not exist', function () {
    realtimeAuth($this->admin, 'private-notifications.999999')->assertForbidden();
})->group('phase6', 'realtime');

/*
|--------------------------------------------------------------------------
| conversation.{conversation} — ConversationPolicy::view
|--------------------------------------------------------------------------
*/

it('lets a member of a task conversation subscribe to it', function () {
    realtimeAuth($this->tapu, 'private-conversation.'.$this->conversation->getKey())->assertOk();
})->group('phase6', 'realtime');

it('refuses a non-member on a conversation channel', function () {
    realtimeAuth($this->yaseen, 'private-conversation.'.$this->conversation->getKey())->assertForbidden();
    realtimeAuth($this->accountant, 'private-conversation.'.$this->conversation->getKey())->assertForbidden();
})->group('phase6', 'realtime');

it('drops somebody off a task conversation channel the moment the task is reassigned', function () {
    // Decision 2-24, asked of the socket instead of the endpoint: membership is COMPUTED, so
    // there is no list to sync and the next subscription simply gets a different answer.
    $channel = 'private-conversation.'.$this->conversation->getKey();

    realtimeAuth($this->tapu, $channel)->assertOk();

    $this->task->assignees()->sync([$this->yaseen->employee->getKey() => ['is_primary' => true]]);

    realtimeAuth($this->tapu->fresh(), $channel)->assertForbidden();
    realtimeAuth($this->yaseen->fresh(), $channel)->assertOk();
})->group('phase6', 'realtime');

/*
|--------------------------------------------------------------------------
| The door itself
|--------------------------------------------------------------------------
*/

it('sends a guest to the login page rather than authorising a channel', function () {
    realtimeAuth(null, 'private-notifications.1')->assertUnauthorized();
})->group('phase6', 'realtime');

it('refuses a deactivated user who still holds a session', function () {
    // `active` is on the route AND inside every policy the callbacks ask. This asserts the
    // outer one: a person deactivated while connected is off every channel at their next
    // subscription, which is every reconnect.
    $this->actingAs($this->tapu);
    $this->tapu->forceFill(['status' => UserStatus::Inactive->value])->save();

    $this->postJson('/broadcasting/auth', [
        'socket_id' => REALTIME_SOCKET_ID,
        'channel_name' => 'private-notifications.'.$this->tapu->getKey(),
    ])->assertStatus(403);
})->group('phase6', 'realtime');

it('refuses a request that names no channel at all', function () {
    $this->actingAs($this->admin)
        ->postJson('/broadcasting/auth', ['socket_id' => REALTIME_SOCKET_ID])
        ->assertForbidden();
})->group('phase6', 'realtime');
