<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

beforeEach(function () {
    Http::preventStrayRequests();

    $hash = strtoupper(sha1('password-that-is-pwned'));

    Http::fake([
        'api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(
            "0018A45C4D1DEF81644B54AB7F969B88D65:3\r\n".substr($hash, 5).":42\r\n",
        ),
        'api.pwnedpasswords.com/range/*' => Http::response("0018A45C4D1DEF81644B54AB7F969B88D65:3\r\n"),
    ]);
});

it('rejects a breached password', function () {
    $validator = Validator::make(['p' => 'password-that-is-pwned'], ['p' => Password::defaults()]);

    expect($validator->fails())->toBeTrue();
    Http::assertSentCount(1);
})->group('phase0');

it('accepts an unlisted password of twelve or more characters', function () {
    $validator = Validator::make(['p' => 'correct horse'], ['p' => Password::defaults()]);

    expect($validator->passes())->toBeTrue();
})->group('phase0');

it('rejects a password shorter than twelve characters', function () {
    $validator = Validator::make(['p' => 'elevenchars'], ['p' => Password::defaults()]);

    expect(strlen('elevenchars'))->toBe(11)
        ->and($validator->fails())->toBeTrue();
})->group('phase0');
