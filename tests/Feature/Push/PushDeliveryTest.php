<?php

use App\Jobs\SendWebPush;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use App\Services\NotificationService;
use App\Services\PushService;
use App\Support\NotificationChannel;
use App\Support\NotificationType;
use App\Support\PushCategory;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Push notifications: who is pushed, as what
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->seed();

    $this->conversations = app(ConversationService::class);
    $this->messages = app(MessageService::class);
    $this->notifications = app(NotificationService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    $this->team = $this->conversations->team();
    $this->dm = $this->conversations->dmBetween($this->tapu, $this->admin);

    Queue::fake();
});

it('pushes a DM to the other person as a Message, titled with the sender, never to the sender', function () {
    $message = $this->messages->post($this->admin, $this->dm, 'Hello Tapu');

    Queue::assertPushed(SendWebPush::class, fn (SendWebPush $job): bool => $job->userId === $this->tapu->id
        && $job->category === PushCategory::Messages
        && $job->message['title'] === $this->admin->name
        && $job->message['body'] === 'Hello Tapu'
        && $job->message['sender'] === $this->admin->name
        && str_contains($job->message['url'], '/messages?conversation='.$this->dm->id)
        && $job->messageId === (int) $message->getKey());

    expect(Queue::pushed(SendWebPush::class)->filter(fn (SendWebPush $job): bool => $job->userId === $this->tapu->id && $job->category === PushCategory::Messages)->count())->toBe(1);

    Queue::assertNotPushed(SendWebPush::class, fn (SendWebPush $job): bool => $job->userId === $this->admin->id);
});

it('does not also push a DM as an Alert', function () {
    $this->messages->post($this->admin, $this->dm, 'Hello Tapu');

    Queue::assertNotPushed(SendWebPush::class, fn (SendWebPush $job): bool => $job->category === PushCategory::Alerts);
});

it('pushes a team channel line to every other active messaging user', function () {
    $this->messages->post($this->admin, $this->team, 'Morning, team');

    // Telegram's form for a group chat (client reference, 2026-10-04): the chat as the title,
    // "Sender: text" as the body, and the sender for the avatar.
    Queue::assertPushed(SendWebPush::class, fn (SendWebPush $job): bool => $job->userId === $this->tapu->id
        && $job->category === PushCategory::Messages
        && $job->message['title'] === $this->team->labelFor($this->tapu)
        && $job->message['body'] === $this->admin->name.': Morning, team'
        && $job->message['sender'] === $this->admin->name);

    Queue::assertNotPushed(SendWebPush::class, fn (SendWebPush $job): bool => $job->userId === $this->admin->id);
});

it('shortens a long message to 120 characters plus the ellipsis', function () {
    $this->messages->post($this->admin, $this->dm, Str::repeat('a', 300));

    Queue::assertPushed(SendWebPush::class, fn (SendWebPush $job): bool => $job->userId === $this->tapu->id
        && mb_strlen($job->message['body']) <= 123);
});

it('pushes a bell notification as an Alert', function () {
    $task = Task::query()->firstOrFail();

    app(NotificationService::class)->notify(NotificationType::TaskCommented, $task, [$this->tapu], ['title' => $task->title], $this->admin);

    Queue::assertPushed(SendWebPush::class, fn (SendWebPush $job): bool => $job->userId === $this->tapu->id
        && $job->category === PushCategory::Alerts
        && $job->message['title'] === $task->title
        && $job->message['body'] !== ''
        && str_starts_with($job->message['tag'], 'notification-'));
});

it('links an alert by path only, whatever host the actor used', function () {
    $task = Task::query()->firstOrFail();

    app(NotificationService::class)->notify(NotificationType::TaskCommented, $task, [$this->tapu], ['title' => $task->title], $this->admin);

    Queue::assertPushed(SendWebPush::class, fn (SendWebPush $job): bool => $job->userId === $this->tapu->id
        && $job->category === PushCategory::Alerts
        && str_starts_with($job->message['url'], '/')
        && ! str_contains($job->message['url'], '://'));
});

it('stops the push when an Admin switches browser push off for that kind', function () {
    $task = Task::query()->firstOrFail();

    NotificationPreference::query()->create([
        'type' => NotificationType::TaskCommented->value,
        'channel' => NotificationChannel::WebPush->value,
        'enabled' => false,
    ]);

    app(NotificationService::class)->notify(NotificationType::TaskCommented, $task, [$this->tapu], ['title' => $task->title], $this->admin);

    Queue::assertNotPushed(SendWebPush::class, fn (SendWebPush $job): bool => $job->category === PushCategory::Alerts);

    expect(Notification::query()
        ->where('user_id', $this->tapu->id)
        ->where('type', NotificationType::TaskCommented->value)
        ->exists())->toBeTrue();
});

it('honours the person\'s switch, and sends nothing without keys', function () {
    config(['webpush.vapid.public_key' => null]);

    $push = app(PushService::class);

    expect($push->isConfigured())->toBeFalse()
        ->and($push->send($this->tapu, PushCategory::Alerts, ['title' => 'T', 'body' => 'B', 'url' => '/', 'tag' => 'x']))->toBe(0);

    $this->tapu->forceFill(['push_messages' => false])->save();
    $tapu = $this->tapu->fresh();

    expect($push->wants($tapu, PushCategory::Messages))->toBeFalse()
        ->and($push->wants($tapu, PushCategory::Alerts))->toBeTrue();
});
