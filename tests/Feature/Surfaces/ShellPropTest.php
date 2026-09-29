<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/*
|--------------------------------------------------------------------------
| The shell's live prop — the announcement banner, app-wide, and the
| Messages nav row's unread indicator
|--------------------------------------------------------------------------
|
| Two lines of POLISH-BACKLOG §A.3 at once — *"Messages — anywhere in the app:
| the Messages nav row's unread indicator"* and *"Announcement banner: a new
| announcement appears without a reload"* — and §C.1's item 6-18, which priced a
| global banner at *"one prop in `HandleInertiaRequests` plus the prop-shape tests
| that come with it (2-42's warning)"* and stopped there.
|
| This is that test. What it is guarding:
|
| ## The prop is OPTIONAL and must stay optional
|
| Resolving it is the announcements channel, the policy-checked inbox with its
| eager loads, one grouped unread count and the newest announcement with its
| author. A prop resolved on every response is a prop every response PAYS for —
| on a catalogue page that otherwise runs three statements, on every request in
| the application, for two facts that are re-read on a timer anyway. So it is
| `Inertia::optional()`: absent from an ordinary render, resolved only on a
| partial reload that names it. Both halves of that are asserted, because the
| cheap mistake here is a closure that quietly becomes eager.
|
| ## The SHAPE does not depend on the reader
|
| 2-42's warning. A payload whose keys change with who is asking is a payload no
| screen can be typed against, and the Accountant — who holds no `messages.use`
| — is the reader that would have shown it. They get the zero shape, not a
| missing key and not a half-filled one.
|
| ## The two numbers are the policy's
|
| `Components/Realtime/shell.ts` lets the Messages page push its own sum into the
| badge (`adoptInbox()`) rather than spending a request per navigation on it. That
| is only sound while the two sums are the SAME arithmetic over the same
| policy-checked list, so the parity is asserted here rather than assumed there.
|
| ## Constants and helpers are global in Pest
|
| So everything defined here is prefixed SHELL_PROP_.
|
*/

const SHELL_PROP_ANNOUNCEMENT = 'Office closed on Thursday for the public holiday.';

/** The keys `HandleInertiaRequests::sharedShell()` promises, whoever is asking. */
const SHELL_PROP_KEYS = ['unreadMessages', 'unreadByConversation', 'announcement', 'announcementChannelId'];

/** The keys of the banner itself — the same five `MessageController@index` has always sent. */
const SHELL_PROP_BANNER_KEYS = ['conversation_id', 'body', 'author', 'created_at', 'is_unread'];

beforeEach(function () {
    $this->seed();

    $this->conversations = app(ConversationService::class);
    $this->messages = app(MessageService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
});

/**
 * The asset version the middleware will compare against.
 *
 * NOT `Inertia::getVersion()`: the version is computed per request from the Vite manifest, and
 * reading the facade before the request has run answers the empty string — which is a **409**
 * and not a failed assertion, so it reads as the route being broken.
 */
function SHELL_PROP_version(): string
{
    return (string) app(HandleInertiaRequests::class)->version(Request::create('/'));
}

/**
 * One partial reload, exactly as `Components/Realtime/reload.ts` makes it: the current page's
 * component, and `shell` as the only prop asked for.
 *
 * @return array<string, mixed>
 */
function SHELL_PROP_reload(object $test, string $url, string $component): array
{
    $response = $test->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => SHELL_PROP_version(),
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => 'shell',
    ])->assertOk();

    return $response->json('props.shell');
}

/** Fails when any key at any depth of the payload is a secret-bearing key. */
function SHELL_PROP_assertNoSecrets(array $payload): void
{
    $keys = array_map(
        fn (string $path): string => (string) last(explode('.', $path)),
        array_keys(Arr::dot($payload)),
    );

    foreach (['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'] as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }
}

/*
|--------------------------------------------------------------------------
| Optional means absent
|--------------------------------------------------------------------------
*/

it('leaves the shell prop out of an ordinary page render', function (string $email, string $url) {
    $this->actingAs(User::where('email', $email)->firstOrFail());

    $response = $this->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => SHELL_PROP_version(),
    ])->assertOk();

    // Not null — ABSENT. A null here would mean the closure ran and answered nothing, which is
    // the cost this prop was made optional to avoid.
    $response->assertJsonMissingPath('props.shell');
})->with([
    'admin dashboard' => ['shahadat@goodtechies.test', '/admin/dashboard'],
    'admin board' => ['shahadat@goodtechies.test', '/admin/tasks/board'],
    'employee dashboard' => ['yaseen@goodtechies.test', '/employee/dashboard'],
    'accountant dashboard' => ['accountant@goodtechies.test', '/accountant/dashboard'],
])->group('phase12', 'realtime');

it('resolves the shell prop on a partial reload from any screen in the application', function (string $url, string $component) {
    $this->actingAs($this->admin);

    $shell = SHELL_PROP_reload($this, $url, $component);

    // The point of the line: the banner and the badge are the CHROME, so they are answerable
    // from every screen, not only from Messages. §C.1's 6-18 is this assertion.
    expect(array_keys($shell))->toEqualCanonicalizing(SHELL_PROP_KEYS);
})->with([
    'dashboard' => ['/admin/dashboard', 'Admin/Dashboard'],
    'board' => ['/admin/tasks/board', 'Admin/Tasks/Board'],
    'attendance' => ['/admin/attendance', 'Admin/Attendance/Index'],
    'clients' => ['/admin/clients', 'Admin/Clients/Index'],
])->group('phase12', 'realtime');

/*
|--------------------------------------------------------------------------
| The shape, and that it does not move with the reader
|--------------------------------------------------------------------------
*/

it('gives every reader the same three keys, including one who may not use messages', function (string $email, string $url, string $component) {
    $this->actingAs(User::where('email', $email)->firstOrFail());

    $shell = SHELL_PROP_reload($this, $url, $component);

    expect(array_keys($shell))->toEqualCanonicalizing(SHELL_PROP_KEYS)
        ->and($shell['unreadMessages'])->toBeInt()
        ->and($shell['unreadMessages'])->toBeGreaterThanOrEqual(0);

    if ($shell['announcement'] !== null) {
        expect(array_keys($shell['announcement']))->toEqualCanonicalizing(SHELL_PROP_BANNER_KEYS)
            ->and($shell['announcement']['is_unread'])->toBeBool()
            ->and($shell['announcementChannelId'])->toBe($shell['announcement']['conversation_id']);
    }

    SHELL_PROP_assertNoSecrets($shell);
})->with([
    'admin' => ['shahadat@goodtechies.test', '/admin/dashboard', 'Admin/Dashboard'],
    'employee' => ['yaseen@goodtechies.test', '/employee/dashboard', 'Employee/Dashboard'],
    'remote employee' => ['tapu@goodtechies.test', '/employee/dashboard', 'Employee/Dashboard'],
    'accountant' => ['accountant@goodtechies.test', '/accountant/dashboard', 'Accountant/Dashboard'],
])->group('phase12', 'realtime');

it('gives the accountant the zero shape rather than a banner they may not open', function () {
    // No `messages.use`, so `inboxFor()` is empty and there is nothing to count or to draw. The
    // keys are still all three: a screen cannot be typed against a payload that loses a key for
    // one role (2-42).
    $this->actingAs($this->accountant);

    $shell = SHELL_PROP_reload($this, '/accountant/dashboard', 'Accountant/Dashboard');

    expect($shell)->toBe([
        'unreadMessages' => 0,
        'unreadByConversation' => [],
        'announcement' => null,
        'announcementChannelId' => null,
    ]);
})->group('phase12', 'realtime');

/*
|--------------------------------------------------------------------------
| A new announcement appears, with nothing navigated
|--------------------------------------------------------------------------
*/

it('carries a new announcement on the next poll, with no navigation in between', function () {
    $this->actingAs($this->yaseen);

    $before = SHELL_PROP_reload($this, '/employee/dashboard', 'Employee/Dashboard');

    // Somebody with `announcements.send` posts, which is the whole event. Nothing is navigated
    // and nothing is reloaded but the one prop.
    $this->messages->post($this->admin, $this->conversations->announcements(), SHELL_PROP_ANNOUNCEMENT);

    $after = SHELL_PROP_reload($this, '/employee/dashboard', 'Employee/Dashboard');

    expect($after['announcement'])->not->toBeNull()
        ->and($after['announcement']['body'])->toBe(SHELL_PROP_ANNOUNCEMENT)
        ->and($after['announcement']['author'])->toBe($this->admin->name)
        ->and($after['announcement']['is_unread'])->toBeTrue()
        ->and($after['announcementChannelId'])->toBe((int) $this->conversations->announcements()->getKey())
        // And the badge moved with it, because an announcement is an unread message.
        ->and($after['unreadMessages'])->toBeGreaterThan($before['unreadMessages']);
})->group('phase12', 'realtime');

it('goes quiet when the announcements channel has been read', function () {
    $this->actingAs($this->yaseen);

    $this->messages->post($this->admin, $this->conversations->announcements(), SHELL_PROP_ANNOUNCEMENT);

    $unread = SHELL_PROP_reload($this, '/employee/dashboard', 'Employee/Dashboard');
    expect($unread['announcement']['is_unread'])->toBeTrue();

    // Opening the thread is what marks it read — there is no dismiss button, and the banner
    // going quiet is the whole of how it is dismissed (6-18's shape).
    $this->get('/messages/'.$this->conversations->announcements()->getKey())->assertOk();

    $read = SHELL_PROP_reload($this, '/employee/dashboard', 'Employee/Dashboard');

    expect($read['announcement'])->not->toBeNull()
        ->and($read['announcement']['is_unread'])->toBeFalse();
})->group('phase12', 'realtime');

/*
|--------------------------------------------------------------------------
| The badge and the Messages page cannot disagree
|--------------------------------------------------------------------------
*/

it('counts exactly what the Messages page counts, over the same policy-checked list', function (string $email) {
    $user = User::where('email', $email)->firstOrFail();

    $this->messages->post($this->admin, $this->conversations->team(), 'Standup moved to 10:15 tomorrow.');
    $this->messages->post($this->admin, $this->conversations->announcements(), SHELL_PROP_ANNOUNCEMENT);

    $this->actingAs($user);

    $shell = SHELL_PROP_reload($this, '/employee/dashboard', 'Employee/Dashboard');

    // The Messages page's own rows, summed. `shell.ts` pushes exactly this sum into the badge
    // when the page has it (`adoptInbox`), so the two must be the same arithmetic — otherwise
    // the nav row says one thing and the screen it opens says another.
    $rows = $this->get('/messages')->assertOk()->inertiaProps()['conversations'];
    $sum = array_sum(array_map(fn (array $row): int => (int) $row['unread_count'], $rows));

    expect($shell['unreadMessages'])->toBe($sum);
})->with([
    'employee' => ['yaseen@goodtechies.test'],
    'remote employee' => ['tapu@goodtechies.test'],
])->group('phase12', 'realtime');

it('does not count a project channel the reader is not on', function () {
    // The privacy half, stated as a count. `inboxFor()` puts every candidate through
    // `ConversationPolicy::view` one at a time, and the unread count is a grouped query over
    // exactly those ids — so a channel this reader may not see contributes nothing, and its
    // existence is not inferable from the number either.
    $this->actingAs($this->yaseen);

    $mine = collect(SHELL_PROP_reload($this, '/employee/dashboard', 'Employee/Dashboard'));

    $hidden = $this->conversations->inboxFor($this->admin)
        ->reject(fn ($conversation): bool => $this->conversations->inboxFor($this->yaseen)
            ->contains(fn ($visible): bool => (int) $visible->getKey() === (int) $conversation->getKey()));

    // There IS at least one channel the Admin has and Yaseen does not, or this test proves
    // nothing — the seed's project channels are what make that true.
    expect($hidden)->not->toBeEmpty();

    $before = $mine['unreadMessages'];

    foreach ($hidden as $conversation) {
        $this->messages->post($this->admin, $conversation, 'Not for Yaseen.');
    }

    $after = SHELL_PROP_reload($this, '/employee/dashboard', 'Employee/Dashboard');

    expect($after['unreadMessages'])->toBe($before);
})->group('phase12', 'realtime');

/*
|--------------------------------------------------------------------------
| And the Messages page itself still sends its own copy
|--------------------------------------------------------------------------
*/

it('leaves the Messages page its own announcement prop, in the same shape', function () {
    // The page draws the banner inside its own fixed-height workspace (M-12), so it keeps
    // sending its own — and `ShellLive.vue` draws nothing on that one screen. Two placements of
    // one banner; the shape has to be one shape or the extracted component reads two.
    $this->actingAs($this->yaseen);

    $this->messages->post($this->admin, $this->conversations->announcements(), SHELL_PROP_ANNOUNCEMENT);

    $page = $this->get('/messages')->assertOk()->inertiaProps();
    $shell = SHELL_PROP_reload($this, '/employee/dashboard', 'Employee/Dashboard');

    expect(array_keys($page['announcement']))->toEqualCanonicalizing(SHELL_PROP_BANNER_KEYS)
        ->and($page['announcement'])->toEqual($shell['announcement']);
})->group('phase12', 'realtime');

/**
 * The prop resolves to the same thing whether it is asked for alone or beside a page's own
 * props, which is what `reload.ts` does when the shell's timer and a screen's timer coincide.
 */
it('resolves alongside a page prop in one coalesced request', function () {
    $this->actingAs($this->admin);

    $response = $this->get('/admin/dashboard', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => SHELL_PROP_version(),
        'X-Inertia-Partial-Component' => 'Admin/Dashboard',
        'X-Inertia-Partial-Data' => 'shell,workStats,attention',
    ])->assertOk();

    expect(array_keys($response->json('props.shell')))->toEqualCanonicalizing(SHELL_PROP_KEYS);

    $response->assertJsonPath('props.shell.unreadMessages', fn ($value): bool => is_int($value));

    expect($response->json('props'))->toHaveKeys(['shell', 'workStats', 'attention'])
        // Everything else is filtered out, which is the whole reason a live refresh names props.
        ->and($response->json('props'))->not->toHaveKey('tasksByEmployee');
})->group('phase12', 'realtime');

/**
 * A guest gets no shell at all rather than a zero one — there is no page to partial-reload, and
 * the middleware's guard is the reason a logged-out tab left open overnight does not 500.
 */
it('does not resolve a shell for somebody who is not signed in', function () {
    // Reliability slice 1: a background Inertia visit gets a 401 the session dialog reads,
    // rather than a redirect Inertia would follow onto the login page.
    $response = $this->get('/admin/dashboard', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => SHELL_PROP_version(),
        'X-Inertia-Partial-Component' => 'Admin/Dashboard',
        'X-Inertia-Partial-Data' => 'shell',
    ])->assertUnauthorized()->assertJson(['reason' => 'session']);

    expect($response->json())->not->toHaveKey('props')
        ->and($response->getContent())->not->toContain('shell');
})->group('phase12', 'realtime');
