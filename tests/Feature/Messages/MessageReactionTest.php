<?php

use App\Events\ConversationActivity;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Emoji reactions (decision 12-79)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->seed();

    $this->conversations = app(ConversationService::class);
    $this->messages = app(MessageService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $this->team = $this->conversations->team();
    $this->message = $this->messages->post($this->tapu, $this->team, 'Shipped it');
    $this->url = '/messages/'.$this->team->id.'/messages/'.$this->message->id.'/reactions';
});

it('adds a reaction and takes it off again', function () {
    Event::fake([ConversationActivity::class]);

    $this->actingAs($this->yaseen)
        ->postJson($this->url, ['emoji' => '👍'])
        ->assertOk()
        ->assertJsonPath('message.reactions', [
            ['emoji' => '👍', 'count' => 1, 'mine' => true, 'names' => [$this->yaseen->name]],
        ]);

    Event::assertDispatched(ConversationActivity::class, fn (ConversationActivity $event): bool => $event->kind === 'reaction');

    $this->actingAs($this->admin)
        ->postJson($this->url, ['emoji' => '👍'])
        ->assertOk()
        ->assertJsonPath('message.reactions.0.count', 2)
        ->assertJsonPath('message.reactions.0.mine', true)
        ->assertJsonPath('message.reactions.0.names', [$this->yaseen->name, $this->admin->name]);

    $this->actingAs($this->yaseen)
        ->postJson($this->url, ['emoji' => '👍'])
        ->assertOk()
        ->assertJsonPath('message.reactions', [
            ['emoji' => '👍', 'count' => 1, 'mine' => false, 'names' => [$this->admin->name]],
        ]);
})->group('messaging-12-79');

it('refuses a ninth distinct emoji from one person', function () {
    foreach (['👍', '❤️', '😂', '😮', '😢', '🙏', '🔥', '🎉'] as $emoji) {
        $this->actingAs($this->yaseen)->postJson($this->url, ['emoji' => $emoji])->assertOk();
    }

    $this->actingAs($this->yaseen)
        ->postJson($this->url, ['emoji' => '👀'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('emoji');
})->group('messaging-12-79');

it('refuses something that is not an emoji', function () {
    $this->actingAs($this->yaseen)
        ->postJson($this->url, ['emoji' => 'abc'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('emoji');
})->group('messaging-12-79');

it('refuses somebody who cannot see the conversation', function () {
    $dm = $this->conversations->dmBetween($this->tapu, $this->admin);
    $message = $this->messages->post($this->tapu, $dm, 'Private');

    $response = $this->actingAs($this->yaseen)
        ->postJson('/messages/'.$dm->id.'/messages/'.$message->id.'/reactions', ['emoji' => '👍']);

    expect($response->getStatusCode())->not->toBe(200);
    $response->assertNotFound();
})->group('messaging-12-79');
