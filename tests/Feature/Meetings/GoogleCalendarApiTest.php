<?php

use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Calendar\CalendarApiOneWay;
use App\Services\Calendar\CalendarLink;
use App\Services\MeetingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| Polish 030: the `api` driver creates the Google Calendar event and its Meet link inside
| goodERP. Google is faked; the real thing needs the server's GOOGLE_* keys and a connected
| account (Admin → Settings → Connect Google).
*/

beforeEach(function () {
    $this->seed();

    config([
        'services.google_calendar.driver' => 'api',
        'services.google_calendar.client_id' => 'client-id.apps.googleusercontent.com',
        'services.google_calendar.client_secret' => 'test-secret',
        'services.google_calendar.redirect' => 'http://localhost/admin/google/callback',
    ]);
    app()->forgetInstance(CalendarLink::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->start = Carbon::now()->addDay()->setTime(10, 0);
});

function GOOGLE_connect(): GoogleAccount
{
    return GoogleAccount::query()->create([
        'email' => 'agency@example.com',
        'refresh_token' => 'refresh-1',
        'access_token' => 'access-1',
        'access_token_expires_at' => now()->addHour(),
    ]);
}

it('binds the api driver, which falls back to pasting by hand until Google is connected', function () {
    $driver = app(CalendarLink::class);

    expect($driver)->toBeInstanceOf(CalendarApiOneWay::class)
        ->and($driver->createsLinksItself())->toBeFalse()
        ->and($driver->startUrl())->toBe('https://meet.google.com/new');

    GOOGLE_connect();

    expect($driver->createsLinksItself())->toBeTrue()
        ->and($driver->startUrl())->toBeNull();
});

it('creates the event and stores its Meet link when a meeting is scheduled', function () {
    GOOGLE_connect();

    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
            'id' => 'evt123',
            'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
        ]),
    ]);

    $meeting = app(MeetingService::class)->schedule($this->admin, [
        'title' => 'Weekly sync',
        'start_at' => $this->start,
        'end_at' => $this->start->copy()->addHour(),
    ]);

    expect($meeting->meet_link)->toBe('https://meet.google.com/abc-defg-hij')
        ->and($meeting->google_event_id)->toBe('evt123');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer access-1')
        && str_contains($request->url(), 'conferenceDataVersion=1')
        && $request['conferenceData']['createRequest']['conferenceSolutionKey']['type'] === 'hangoutsMeet'
        && $request['summary'] === 'Weekly sync');
});

it('refreshes an expired access token before calling Google', function () {
    GOOGLE_connect()->forceFill(['access_token_expires_at' => now()->subMinute()])->save();

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-2', 'expires_in' => 3600]),
        'www.googleapis.com/*' => Http::response(['id' => 'evt9', 'hangoutLink' => 'https://meet.google.com/xyz-abcd-efg']),
    ]);

    $meeting = app(MeetingService::class)->schedule($this->admin, [
        'title' => 'Refresh me',
        'start_at' => $this->start,
        'end_at' => $this->start->copy()->addHour(),
    ]);

    expect($meeting->meet_link)->toBe('https://meet.google.com/xyz-abcd-efg')
        ->and(GoogleAccount::current()->access_token)->toBe('access-2');
});

it('keeps the meeting when Google fails, with no link', function () {
    GOOGLE_connect();

    Http::fake(['www.googleapis.com/*' => Http::response(['error' => 'boom'], 500)]);

    $meeting = app(MeetingService::class)->schedule($this->admin, [
        'title' => 'Still saved',
        'start_at' => $this->start,
        'end_at' => $this->start->copy()->addHour(),
    ]);

    expect($meeting->exists)->toBeTrue()
        ->and($meeting->meet_link)->toBeNull();
});

it('stores the tokens encrypted', function () {
    GOOGLE_connect();

    $raw = DB::table('google_accounts')->value('refresh_token');

    expect($raw)->not->toBe('refresh-1')
        ->and(GoogleAccount::current()->refresh_token)->toBe('refresh-1');
});

it('sends the Admin to Google with a state, and refuses a callback whose state does not match', function () {
    $response = $this->actingAs($this->admin)->get('/admin/google/connect');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('https://accounts.google.com/o/oauth2/v2/auth')
        ->toContain('calendar.events');

    $this->actingAs($this->admin)
        ->get('/admin/google/callback?state=wrong&code=abc')
        ->assertRedirect('/admin/settings')
        ->assertSessionHas('error');

    expect(GoogleAccount::count())->toBe(0);
});

it('connects on a valid callback', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600]),
        'openidconnect.googleapis.com/*' => Http::response(['email' => 'agency@example.com']),
    ]);

    $this->actingAs($this->admin)->withSession(['google_oauth_state' => 'S1'])
        ->get('/admin/google/callback?state=S1&code=abc')
        ->assertRedirect('/admin/settings')
        ->assertSessionHas('success');

    expect(GoogleAccount::current()?->email)->toBe('agency@example.com');
});
