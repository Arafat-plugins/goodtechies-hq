<?php

use App\Events\ConversationActivity;
use App\Models\AuditLog;
use App\Models\File;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Edit and delete-for-everyone (decision 12-79)
|--------------------------------------------------------------------------
|
| The author may edit or delete their own message at any time; a holder of `messages.manage`
| may delete anybody's outside a DM. The original survives in the audit log. Every change rings
| the conversation's live channel with its `kind`.
*/

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->conversations = app(ConversationService::class);
    $this->messages = app(MessageService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $this->team = $this->conversations->team();
});

function EDIT_url(object $message, string $suffix = ''): string
{
    return '/messages/'.$message->conversation_id.'/messages/'.$message->id.$suffix;
}

it('lets the author edit their message, audits the old body and rings the room', function () {
    $message = $this->messages->post($this->tapu, $this->team, 'Frist draft');

    Event::fake([ConversationActivity::class]);

    $this->actingAs($this->tapu)
        ->patchJson(EDIT_url($message), ['body' => '  First draft  '])
        ->assertOk()
        ->assertJsonPath('message.id', $message->id)
        ->assertJsonPath('message.body', 'First draft')
        ->assertJsonPath('message.is_deleted', false)
        ->assertJsonPath('message.can_edit', true);

    $message->refresh();

    expect($message->body)->toBe('First draft')
        ->and($message->edited_at)->not->toBeNull();

    $audit = AuditLog::where('event', 'message.edited')->get();

    expect($audit)->toHaveCount(1)
        ->and($audit->first()->old_value)->toBe(['body' => 'Frist draft'])
        ->and($audit->first()->new_value)->toBe(['body' => 'First draft']);

    Event::assertDispatched(
        ConversationActivity::class,
        fn (ConversationActivity $event): bool => $event->kind === 'edited'
            && $event->messageId === $message->id
            && $event->conversationId === $this->team->id,
    );
})->group('messaging-12-79');

it('refuses an edit by somebody who did not write the message', function () {
    $message = $this->messages->post($this->tapu, $this->team, 'Mine');

    $this->actingAs($this->yaseen)
        ->patchJson(EDIT_url($message), ['body' => 'Not yours'])
        ->assertForbidden();

    expect($message->refresh()->body)->toBe('Mine');
})->group('messaging-12-79');

it('refuses to edit a deleted message', function () {
    $message = $this->messages->post($this->tapu, $this->team, 'Gone soon');

    $this->actingAs($this->tapu)->deleteJson(EDIT_url($message))->assertOk();

    $this->actingAs($this->tapu)
        ->patchJson(EDIT_url($message), ['body' => 'Back again'])
        ->assertForbidden();
})->group('messaging-12-79');

it('deletes for everyone, blanks the content and keeps the original in the audit log', function () {
    $message = $this->messages->post(
        $this->tapu,
        $this->team,
        'Here is the crawl',
        UploadedFile::fake()->create('crawl.pdf', 10, 'application/pdf'),
    );
    $fileId = $message->attachments->first()->id;

    Event::fake([ConversationActivity::class]);

    $this->actingAs($this->tapu)
        ->deleteJson(EDIT_url($message))
        ->assertOk()
        ->assertJsonPath('message.is_deleted', true)
        ->assertJsonPath('message.body', null)
        ->assertJsonPath('message.attachments', [])
        ->assertJsonPath('message.mentions', [])
        ->assertJsonPath('message.reactions', [])
        ->assertJsonPath('message.can_edit', false)
        ->assertJsonPath('message.can_delete', false);

    $audit = AuditLog::where('event', 'message.deleted')->sole();

    expect($audit->old_value)->toBe(['body' => 'Here is the crawl', 'attachments' => [$fileId]])
        ->and($audit->new_value)->toBe(['deleted_by' => $this->tapu->id])
        ->and(File::find($fileId))->toBeNull()
        ->and($message->refresh()->deleted_by)->toBe($this->tapu->id);

    Event::assertDispatched(ConversationActivity::class, fn (ConversationActivity $event): bool => $event->kind === 'deleted');
})->group('messaging-12-79');

it('lets an Admin delete somebody else\'s message in the team channel', function () {
    $message = $this->messages->post($this->tapu, $this->team, 'Off topic');

    $this->actingAs($this->admin)
        ->deleteJson(EDIT_url($message))
        ->assertOk()
        ->assertJsonPath('message.is_deleted', true);
})->group('messaging-12-79');

it('hides a DM the Admin is not in behind a 404', function () {
    $dm = $this->conversations->dmBetween($this->tapu, $this->yaseen);
    $message = $this->messages->post($this->tapu, $dm, 'Just between us');

    $this->actingAs($this->admin)->deleteJson(EDIT_url($message))->assertNotFound();

    expect($message->refresh()->deleted_at)->toBeNull();
})->group('messaging-12-79');

it('refuses an Admin deleting the other person\'s message in their own DM', function () {
    $dm = $this->conversations->dmBetween($this->tapu, $this->admin);
    $message = $this->messages->post($this->tapu, $dm, 'My words');

    $this->actingAs($this->admin)->deleteJson(EDIT_url($message))->assertForbidden();
})->group('messaging-12-79');

it('answers 404 for a message from another conversation', function () {
    $dm = $this->conversations->dmBetween($this->tapu, $this->yaseen);
    $message = $this->messages->post($this->tapu, $dm, 'Elsewhere');

    $this->actingAs($this->tapu)
        ->deleteJson('/messages/'.$this->team->id.'/messages/'.$message->id)
        ->assertNotFound();

    $this->actingAs($this->tapu)
        ->patchJson('/messages/'.$this->team->id.'/messages/'.$message->id, ['body' => 'x'])
        ->assertNotFound();
})->group('messaging-12-79');

it('answers a JSON post with 201 and the message', function () {
    $this->actingAs($this->tapu)
        ->postJson('/messages/'.$this->team->id, ['body' => 'Optimistic hello'])
        ->assertCreated()
        ->assertJsonPath('message.body', 'Optimistic hello')
        ->assertJsonPath('message.is_mine', true)
        ->assertJsonStructure(['message' => ['id']]);
})->group('messaging-12-79');

it('still redirects a form post with the flash', function () {
    $this->actingAs($this->tapu)
        ->from('/messages')
        ->post('/messages/'.$this->team->id, ['body' => 'Classic hello'])
        ->assertRedirect('/messages')
        ->assertSessionHas('success', 'Message sent.');
})->group('messaging-12-79');
