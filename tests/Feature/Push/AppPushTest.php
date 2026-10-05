<?php

use App\Http\Middleware\RememberAppPushToken;
use App\Models\AppPushToken;
use App\Models\User;
use App\Services\AppPushService;
use App\Services\PushService;
use App\Support\PushCategory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Notifications inside the goodERP Android app (Firebase), 2026-10-05
|--------------------------------------------------------------------------
|
| The app's WebView backup screen has no Web Push, so the app registers with Firebase and hands
| its token over in a cookie only the app can set. These pin who the token is filed under, that
| signing out forgets it, and what is sent to Google.
*/

const APP_TOKEN = 'fKq1:APA91bHk_example-token_0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJ';

beforeEach(function () {
    $this->seed();

    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
});

function APP_credentials(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    $path = tempnam(sys_get_temp_dir(), 'fcm');
    file_put_contents($path, json_encode([
        'type' => 'service_account',
        'project_id' => 'gooderp-test',
        'client_email' => 'push@gooderp-test.iam.gserviceaccount.com',
        'private_key' => $pem,
        // Ignored on purpose: the token always goes to Google's own endpoint.
        'token_uri' => 'https://evil.example/token',
    ]));

    config(['services.fcm.credentials' => $path]);
    Cache::flush();

    return $path;
}

it('files the app token under whoever is signed in inside the app', function () {
    $this->actingAs($this->tapu)
        ->withUnencryptedCookie(RememberAppPushToken::COOKIE, APP_TOKEN)
        ->get('/profile')
        ->assertOk();

    $row = AppPushToken::query()->sole();

    expect($row->user_id)->toBe($this->tapu->id)
        ->and($row->token)->toBe(APP_TOKEN);

    // Again in the same session: still one row.
    $this->actingAs($this->tapu)
        ->withUnencryptedCookie(RememberAppPushToken::COOKIE, APP_TOKEN)
        ->get('/profile');

    expect(AppPushToken::query()->count())->toBe(1);
});

it('moves the phone to the next person who signs in on it', function () {
    $this->actingAs($this->tapu)->withUnencryptedCookie(RememberAppPushToken::COOKIE, APP_TOKEN)->get('/profile');
    $this->actingAs($this->admin)->withUnencryptedCookie(RememberAppPushToken::COOKIE, APP_TOKEN)->get('/profile');

    expect(AppPushToken::query()->sole()->user_id)->toBe($this->admin->id);
});

it('ignores a guest and anything that is not a token', function (string $value, bool $signedIn) {
    $request = $signedIn ? $this->actingAs($this->tapu) : $this;

    $request->withUnencryptedCookie(RememberAppPushToken::COOKIE, $value)->get($signedIn ? '/profile' : '/login');

    expect(AppPushToken::query()->count())->toBe(0);
})->with([
    'guest with a real-looking token' => [APP_TOKEN, false],
    'too short' => ['abc', true],
    'markup' => ['<script>alert(1)</script>xxxxxxxxxxxxxxxxxxxxxxxxxxxx', true],
    'spaces' => [str_repeat('a b', 20), true],
]);

it('forgets this phone when the person signs out inside the app', function () {
    AppPushToken::query()->create(['user_id' => $this->tapu->id, 'token' => APP_TOKEN]);
    AppPushToken::query()->create(['user_id' => $this->tapu->id, 'token' => APP_TOKEN.'other']);

    $this->actingAs($this->tapu)
        ->withUnencryptedCookie(RememberAppPushToken::COOKIE, APP_TOKEN)
        ->post('/logout')
        ->assertRedirect();

    expect(AppPushToken::query()->pluck('token')->all())->toBe([APP_TOKEN.'other']);
});

it('forgets every phone with the rest of the devices', function () {
    AppPushToken::query()->create(['user_id' => $this->tapu->id, 'token' => APP_TOKEN]);

    expect(app(PushService::class)->forgetAllDevices($this->tapu))->toBe(1)
        ->and(AppPushToken::query()->count())->toBe(0);
});

it('sends nothing and calls nobody when Firebase is not set up', function () {
    config(['services.fcm.credentials' => null]);
    Http::fake();
    AppPushToken::query()->create(['user_id' => $this->tapu->id, 'token' => APP_TOKEN]);

    expect(app(AppPushService::class)->isConfigured())->toBeFalse()
        ->and(app(AppPushService::class)->send($this->tapu, PushCategory::Messages, ['title' => 'T', 'body' => 'B']))->toBe(0);

    Http::assertNothingSent();
});

it('sends a data message to each of the person\'s phones through FCM v1', function () {
    APP_credentials();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/gooderp-test/messages/1']),
    ]);
    AppPushToken::query()->create(['user_id' => $this->tapu->id, 'token' => APP_TOKEN]);

    $sent = app(AppPushService::class)->send($this->tapu, PushCategory::Messages, [
        'title' => 'Shahadat Hossain',
        'body' => 'Standup at 10',
        'url' => '/messages?conversation=4',
        'tag' => 'conversation-4',
        'sender' => 'Shahadat Hossain',
    ]);

    expect($sent)->toBe(1);

    Http::assertSent(function (HttpRequest $request) {
        if (! str_starts_with($request->url(), 'https://oauth2.googleapis.com/token')) {
            return false;
        }

        // A JWT signed for the FCM scope, sent to Google — not to the file's own token_uri.
        [$header, $claims] = array_map(
            fn ($part) => json_decode(base64_decode(strtr($part, '-_', '+/')), true),
            array_slice(explode('.', $request['assertion']), 0, 2),
        );

        return $header['alg'] === 'RS256'
            && $claims['scope'] === 'https://www.googleapis.com/auth/firebase.messaging'
            && $claims['iss'] === 'push@gooderp-test.iam.gserviceaccount.com';
    });

    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/gooderp-test/messages:send'
        && $request->hasHeader('Authorization', 'Bearer ya29.test')
        && $request['message']['token'] === APP_TOKEN
        && $request['message']['android']['priority'] === 'HIGH'
        && $request['message']['data'] === [
            'title' => 'Shahadat Hossain',
            'body' => 'Standup at 10',
            'url' => '/messages?conversation=4',
            'tag' => 'conversation-4',
            'sender' => 'Shahadat Hossain',
            'category' => 'messages',
        ]);

    Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'evil.example'));
});

it('forgets a phone whose app was uninstalled', function () {
    APP_credentials();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test']),
        'fcm.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 404,
                'status' => 'NOT_FOUND',
                'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']],
            ],
        ], 404),
    ]);
    AppPushToken::query()->create(['user_id' => $this->tapu->id, 'token' => APP_TOKEN]);

    expect(app(AppPushService::class)->send($this->tapu, PushCategory::Alerts, ['title' => 'T', 'body' => 'B']))->toBe(0)
        ->and(AppPushToken::query()->count())->toBe(0);
});

it('still honours the person\'s Messages switch for the app', function () {
    APP_credentials();
    Http::fake();
    AppPushToken::query()->create(['user_id' => $this->tapu->id, 'token' => APP_TOKEN]);
    $this->tapu->forceFill(['push_messages' => false])->save();

    expect(app(PushService::class)->send($this->tapu->fresh(), PushCategory::Messages, ['title' => 'T', 'body' => 'B']))->toBe(0);

    Http::assertNothingSent();
});

it('reaches the app even where Web Push is not set up', function () {
    APP_credentials();
    config(['webpush.vapid.public_key' => null]);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test']),
        'fcm.googleapis.com/*' => Http::response(['name' => 'ok']),
    ]);
    AppPushToken::query()->create(['user_id' => $this->tapu->id, 'token' => APP_TOKEN]);

    expect(app(PushService::class)->send($this->tapu, PushCategory::Alerts, ['title' => 'T', 'body' => 'B', 'url' => '/']))->toBe(1);
});
