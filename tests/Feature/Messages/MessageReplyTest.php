<?php

use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Replies (decision 12-82)
|--------------------------------------------------------------------------
|
| A message may reply to a live message in the same conversation. The relationship is stored
| as `messages.reply_to_id` and printed as `reply_to` on the message payload.
|
| Constants and helpers are global in Pest, so every one here is prefixed MREPLY_.
|
*/

const MREPLY_URL = '/messages';

function mreply_payload(Message $message, User $viewer): array
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => $viewer);

    return (new MessageResource($message->fresh()->load(ConversationService::MESSAGE_RELATIONS)))->resolve($request);
}

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->conversations = app(ConversationService::class);
    $this->messages = app(MessageService::class);

    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $this->team = $this->conversations->team();
    $this->dm = $this->conversations->dmBetween($this->tapu, $this->yaseen);
});

it('stores a reply in the same conversation and prints reply_to with author and excerpt', function () {
    $original = $this->messages->post($this->yaseen, $this->team, str_repeat('a', 130));

    $this->actingAs($this->tapu)
        ->from(MREPLY_URL)
        ->post(MREPLY_URL.'/'.$this->team->id, ['body' => 'On it', 'reply_to_id' => $original->id])
        ->assertRedirect(MREPLY_URL)
        ->assertSessionHasNoErrors();

    $reply = Message::query()->where('body', 'On it')->firstOrFail();

    expect($reply->reply_to_id)->toBe($original->id);

    expect(mreply_payload($reply, $this->tapu)['reply_to'])->toBe([
        'id' => $original->id,
        'author' => ['id' => $this->yaseen->id, 'name' => $this->yaseen->name],
        'excerpt' => str_repeat('a', 120),
        'kind' => 'text',
        'is_deleted' => false,
    ]);
});

it('quotes a file reply by its file name and a photo as "Photo"', function () {
    $file = $this->messages->post($this->yaseen, $this->team, null, UploadedFile::fake()->create('brief.pdf', 8, 'application/pdf'));
    $photo = $this->messages->post($this->yaseen, $this->team, null, UploadedFile::fake()->create('shot.png', 8, 'image/png'));

    $toFile = $this->messages->post($this->tapu, $this->team, 'Read it', replyToId: $file->id);
    $toPhoto = $this->messages->post($this->tapu, $this->team, 'Nice', replyToId: $photo->id);

    expect(mreply_payload($toFile, $this->tapu)['reply_to'])
        ->toMatchArray(['kind' => 'file', 'excerpt' => 'brief.pdf'])
        ->and(mreply_payload($toPhoto, $this->tapu)['reply_to'])
        ->toMatchArray(['kind' => 'image', 'excerpt' => 'Photo']);
});

it('refuses a reply to a message from another conversation', function () {
    $elsewhere = $this->messages->post($this->yaseen, $this->dm, 'Private');

    $this->actingAs($this->tapu)
        ->postJson(MREPLY_URL.'/'.$this->team->id, ['body' => 'Leak', 'reply_to_id' => $elsewhere->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reply_to_id' => 'You can only reply to a message in this conversation.']);

    expect(Message::query()->where('body', 'Leak')->exists())->toBeFalse();
});

it('refuses a reply to a deleted message', function () {
    $original = $this->messages->post($this->yaseen, $this->team, 'Gone soon');
    $this->messages->delete($this->yaseen, $original);

    $this->actingAs($this->tapu)
        ->postJson(MREPLY_URL.'/'.$this->team->id, ['body' => 'Too late', 'reply_to_id' => $original->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reply_to_id');
});

it('keeps the reply when the original is deleted, with reply_to.is_deleted true', function () {
    $original = $this->messages->post($this->yaseen, $this->team, 'Original');
    $reply = $this->messages->post($this->tapu, $this->team, 'Reply', replyToId: $original->id);

    $this->messages->delete($this->yaseen, $original);

    expect(mreply_payload($reply, $this->tapu)['reply_to'])->toMatchArray([
        'id' => $original->id,
        'excerpt' => '',
        'is_deleted' => true,
    ]);
});

it('answers a JSON post with reply_to_id with 201 and the quoted original', function () {
    $original = $this->messages->post($this->yaseen, $this->dm, 'Can you check?');

    $this->actingAs($this->tapu)
        ->postJson(MREPLY_URL.'/'.$this->dm->id, ['body' => 'Checking', 'reply_to_id' => $original->id])
        ->assertCreated()
        ->assertJsonPath('message.reply_to.id', $original->id)
        ->assertJsonPath('message.reply_to.author.id', $this->yaseen->id)
        ->assertJsonPath('message.reply_to.excerpt', 'Can you check?')
        ->assertJsonPath('message.reply_to.kind', 'text');
});
