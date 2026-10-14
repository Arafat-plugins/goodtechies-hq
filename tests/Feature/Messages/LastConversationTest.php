<?php

use App\Models\User;
use App\Services\ConversationService;

/*
|--------------------------------------------------------------------------
| Messages reopens the conversation the person last had open (2026-10-14)
|--------------------------------------------------------------------------
|
| Opening `/messages` with nothing asked for used to land on the team channel every time.
| Now it reopens the person or group last opened — remembered on the account, so every
| device agrees — and falls back to the team channel when there is nothing remembered or
| the remembered conversation is no longer theirs to see.
*/

const LAST_URL = '/messages';

beforeEach(function () {
    $this->seed();

    $this->conversations = app(ConversationService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->faruk = User::where('email', 'faruk@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    $this->team = $this->conversations->team();
    $this->dm = $this->conversations->dmBetween($this->tapu, $this->admin);
});

function LAST_activeId(object $test): ?int
{
    // A fresh instance: actingAs() hands the request this very model, and the remembered id was
    // written by an UPDATE query, not through it.
    $props = $test->actingAs($test->tapu->fresh())->get(LAST_URL)->assertOk()->inertiaPage()['props'];

    return $props['active']['conversation_id'] ?? null;
}

it('reopens the DM the person last opened', function () {
    $this->actingAs($this->tapu)->getJson(LAST_URL.'/'.$this->dm->id)->assertOk();

    expect($this->tapu->fresh()->last_conversation_id)->toBe($this->dm->id)
        ->and(LAST_activeId($this))->toBe($this->dm->id);
})->group('messaging-polish');

it('does not change the remembered conversation when loading older messages', function () {
    $this->actingAs($this->tapu)->getJson(LAST_URL.'/'.$this->dm->id)->assertOk();
    $this->actingAs($this->tapu)->getJson(LAST_URL.'/'.$this->team->id.'?before=999999999')->assertOk();

    expect($this->tapu->fresh()->last_conversation_id)->toBe($this->dm->id)
        ->and(LAST_activeId($this))->toBe($this->dm->id);
})->group('messaging-polish');

it('falls back to the team channel when the remembered conversation is no longer visible', function () {
    // A DM between two other people: never in Tapu's inbox.
    $elsewhere = $this->conversations->dmBetween($this->admin, $this->faruk);

    $this->tapu->update(['last_conversation_id' => $elsewhere->id]);

    expect(LAST_activeId($this))->toBe($this->team->id);
})->group('messaging-polish');

it('opens the team channel for somebody with nothing remembered', function () {
    expect($this->tapu->fresh()->last_conversation_id)->toBeNull()
        ->and(LAST_activeId($this))->toBe($this->team->id);
})->group('messaging-polish');
