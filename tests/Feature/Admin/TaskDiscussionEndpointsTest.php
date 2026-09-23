<?php

use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The task discussion over HTTP
|--------------------------------------------------------------------------
|
| Two endpoints per surface and no more: read the thread, post to it. There is
| deliberately no route to edit or delete a message — a message is what
| somebody said at a time, and the next message is answering it.
|
| The panel that will draw this is a later brief's Vue file, so the list is
| JSON and the same payload is inlined into the task detail page's props. Both
| come from one builder, which is what stops the two surfaces growing a field
| apiece.
|
*/

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->conversations = app(ConversationService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    $this->task = Task::query()->forEmployee($this->tapu->employee)->notArchived()->orderBy('id')->firstOrFail();
    $this->conversation = $this->conversations->forTask($this->task);

    // The seeder puts a demo thread on some of these tasks. This file counts messages
    // absolutely, so it starts from an empty one — the conversation itself is still the one
    // the task was born with.
    $this->conversation->messages()->delete();
});

/*
|--------------------------------------------------------------------------
| Posting
|--------------------------------------------------------------------------
*/

it('posts a message from the Admin surface', function () {
    $this->actingAs($this->admin)
        ->post("/admin/tasks/{$this->task->id}/discussion", ['body' => 'Any movement on this?'])
        ->assertRedirect()
        ->assertSessionHas('success', 'Message posted.');

    expect($this->conversation->messages()->pluck('body'))->toContain('Any movement on this?');
})->group('phase2');

it('posts the same message the same way from the Employee surface', function () {
    // One service call behind both, so the two surfaces cannot be given different rules about
    // what a message is.
    $this->actingAs($this->tapu)
        ->post("/employee/tasks/{$this->task->id}/discussion", ['body' => 'Half of them are done.'])
        ->assertRedirect();

    expect($this->conversation->messages()->latest('id')->first()->author_id)->toBe($this->tapu->id);
})->group('phase2');

it('refuses a message with neither text nor a file', function () {
    $this->actingAs($this->admin)
        ->post("/admin/tasks/{$this->task->id}/discussion", ['body' => '   '])
        ->assertSessionHasErrors('body');

    expect($this->conversation->messages()->count())->toBe(0);
})->group('phase2');

it('accepts a message that is only a file', function () {
    $this->actingAs($this->admin)
        ->post("/admin/tasks/{$this->task->id}/discussion", [
            'file' => UploadedFile::fake()->image('screenshot.png'),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $message = $this->conversation->messages()->latest('id')->firstOrFail();

    expect($message->body)->toBeNull()
        ->and($message->attachments)->toHaveCount(1);
})->group('phase2');

it('refuses a file the application would not store, with a sentence under the field', function () {
    $this->actingAs($this->admin)
        ->post("/admin/tasks/{$this->task->id}/discussion", [
            'body' => 'Here you go',
            'file' => UploadedFile::fake()->create('payload.php', 2, 'text/x-php'),
        ])
        ->assertSessionHasErrors('file');

    expect($this->conversation->messages()->count())->toBe(0);
})->group('phase2');

it('has no route to edit or delete a message', function () {
    $message = Message::factory()->inConversation($this->conversation)->by($this->admin)->create();

    foreach ([
        ['PUT', "/admin/tasks/{$this->task->id}/discussion/{$message->id}"],
        ['DELETE', "/admin/tasks/{$this->task->id}/discussion/{$message->id}"],
        ['PUT', "/employee/tasks/{$this->task->id}/discussion/{$message->id}"],
        ['DELETE', "/employee/tasks/{$this->task->id}/discussion/{$message->id}"],
    ] as [$method, $uri]) {
        $this->actingAs($this->admin)->call($method, $uri)->assertNotFound();
    }

    expect(Message::whereKey($message->id)->exists())->toBeTrue();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The payload
|--------------------------------------------------------------------------
*/

it('sends exactly the documented discussion keys', function () {
    $this->conversations->post($this->admin, $this->conversation, 'One');

    $payload = $this->actingAs($this->admin)
        ->getJson("/admin/tasks/{$this->task->id}/discussion")
        ->assertOk()
        ->json();

    expect(array_keys($payload))->toEqualCanonicalizing([
        'conversation_id', 'messages', 'can_post', 'last_read_at', 'unread_count',
    ])
        ->and(array_keys($payload['messages'][0]))->toEqualCanonicalizing([
            'id', 'body', 'author', 'is_mine', 'created_at', 'attachments',
        ])
        // Resolved on the server, so the panel does not compare ids to decide which side of
        // the thread a bubble sits on.
        ->and($payload['messages'][0]['is_mine'])->toBeTrue()
        ->and($payload['messages'][0]['author'])->toBe(['id' => $this->admin->id, 'name' => $this->admin->name]);
})->group('phase2');

it('sends an attachment through FileResource, with how it rides on the bubble beside it', function () {
    $this->conversations->post(
        $this->tapu,
        $this->conversation,
        'The crawl export.',
        UploadedFile::fake()->create('crawl.pdf', 30, 'application/pdf'),
    );

    $attachment = $this->actingAs($this->admin)
        ->getJson("/admin/tasks/{$this->task->id}/discussion")
        ->assertOk()
        ->json('messages.0.attachments.0');

    expect($attachment['name'])->toBe('crawl.pdf')
        // FileResource's own fields, unchanged: the same signed expiring URL the attachment
        // panel gets, because it is the same file table and the same service.
        ->and($attachment['url'])->toContain('/files/')
        ->and($attachment['url'])->toContain('signature=')
        ->and($attachment['is_previewable'])->toBeTrue()
        ->and($attachment['is_pdf'])->toBeTrue()
        // …and what FileResource must never carry.
        ->and($attachment)->not->toHaveKey('path')
        ->and($attachment)->not->toHaveKey('disk')
        // How it rides on the message. `voice` and a duration are Phase 6's.
        ->and($attachment['kind'])->toBe('file')
        ->and($attachment['duration_seconds'])->toBeNull()
        // A message attachment is never replaced, by anybody.
        ->and($attachment['permissions']['can_replace'])->toBeFalse();
})->group('phase2');

it('inlines the same payload into both task detail pages', function () {
    $this->conversations->post($this->tapu, $this->conversation, 'On the detail page.');

    foreach ([
        [$this->admin, "/admin/tasks/{$this->task->id}"],
        [$this->tapu, "/employee/tasks/{$this->task->id}"],
    ] as [$user, $url]) {
        $discussion = $this->actingAs($user)->get($url)->assertOk()->inertiaPage()['props']['discussion'];

        expect(array_keys($discussion))->toEqualCanonicalizing([
            'conversation_id', 'messages', 'can_post', 'last_read_at', 'unread_count',
        ])
            ->and($discussion['messages'])->toHaveCount(1)
            ->and($discussion['can_post'])->toBeTrue();
    }
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Read state
|--------------------------------------------------------------------------
*/

it('marks the thread read when the panel fetches it, and not when the page renders', function () {
    $this->conversations->post($this->admin, $this->conversation, 'Unread until somebody looks');

    // Rendering a page that happens to contain a panel is not the same as reading it.
    $props = $this->actingAs($this->tapu)
        ->get("/employee/tasks/{$this->task->id}")
        ->assertOk()
        ->inertiaPage()['props']['discussion'];

    expect($props['unread_count'])->toBe(1)
        ->and($props['last_read_at'])->toBeNull()
        ->and(DB::table('conversation_members')->where('user_id', $this->tapu->id)->count())->toBe(0);

    // Fetching it IS the panel saying it has displayed the thread, which is exactly the event
    // `last_read_at` records.
    $payload = $this->actingAs($this->tapu)
        ->getJson("/employee/tasks/{$this->task->id}/discussion")
        ->assertOk()
        ->json();

    expect($payload['unread_count'])->toBe(0)
        ->and($payload['last_read_at'])->not->toBeNull();
})->group('phase2');
