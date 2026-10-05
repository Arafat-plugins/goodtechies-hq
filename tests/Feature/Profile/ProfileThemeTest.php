<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| Light / Dark / System on the account (2026-10-05)
|--------------------------------------------------------------------------
|
| The Android app has two browsers (Chrome and its own WebView), each with its own storage, so a
| theme kept only in the browser opened the app dark, then light, then dark. The account keeps it.
*/

beforeEach(function () {
    $this->seed();

    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
});

it('saves the theme on the signed-in person and nobody else', function () {
    $this->actingAs($this->tapu)->putJson('/profile/theme', ['theme' => 'dark'])->assertNoContent();

    expect($this->tapu->fresh()->theme)->toBe('dark')
        ->and($this->admin->fresh()->theme)->toBeNull();
});

it('accepts only light, dark or system', function (mixed $value) {
    $this->actingAs($this->tapu)
        ->putJson('/profile/theme', ['theme' => $value])
        ->assertStatus(422)
        ->assertJsonValidationErrors('theme');

    expect($this->tapu->fresh()->theme)->toBeNull();
})->with([['purple'], [''], [null], [['dark']]]);

it('does not let a guest save anything', function () {
    $this->putJson('/profile/theme', ['theme' => 'dark'])->assertUnauthorized();
});

it('prints the account theme on <html> so the first paint uses it', function () {
    $this->tapu->forceFill(['theme' => 'dark'])->save();

    $html = $this->actingAs($this->tapu)->get('/messages')->getContent();

    expect($html)->toContain('data-theme="dark"');
});

it('prints nothing when the person has never chosen', function () {
    $html = $this->actingAs($this->tapu)->get('/messages')->getContent();

    expect($html)->not->toContain('data-theme=');
});

it('shares the account theme with every page', function () {
    $this->tapu->forceFill(['theme' => 'light'])->save();

    $this->actingAs($this->tapu)
        ->get('/messages')
        ->assertInertia(fn ($page) => $page->where('auth.user.theme', 'light'));
});
