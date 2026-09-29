<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Slow loading — slice 6
|--------------------------------------------------------------------------
|
| 1. The first paint is one request. A full document load resolves the shell's
|    live state (`shell`) into the page, so the shell no longer fires a partial
|    reload for it — which re-ran the whole current controller — the moment the
|    page mounted. Every Inertia request after that still gets `shell` only by
|    asking for it.
| 2. Heavy props are built only when they are sent. `active` on the Messages page
|    and `discussion` on both task-detail pages are closures, so the rail's and
|    the task detail's background partial reloads, which do not name them, no
|    longer pay for a whole thread and a signed URL per attachment.
|
| Every function here is prefixed SPEED_: Pest declares them globally.
|
*/

/** @return array{response: TestResponse, count: int, queries: list<string>} */
function SPEED_measure(User $user, string $url, array $headers = []): array
{
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = test()->actingAs($user)->get($url, $headers);

    DB::getEventDispatcher()->forget(QueryExecuted::class);

    expect($response->status())->toBe(200, $url.' did not answer 200');

    // `SPEED_REPORT=1 php vendor/bin/pest …` prints every measurement, for a report.
    if (getenv('SPEED_REPORT')) {
        fwrite(STDERR, sprintf("  %3d  %s %s\n", count($queries), $url, $headers['X-Inertia-Partial-Data'] ?? (isset($headers['X-Inertia']) ? '(inertia)' : '(document)')));
    }

    return ['response' => $response, 'count' => count($queries), 'queries' => $queries];
}

function SPEED_inertia(array $extra = []): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Requested-With' => 'XMLHttpRequest',
        ...$extra,
    ];
}

function SPEED_partial(string $component, string $only): array
{
    return SPEED_inertia([
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => $only,
        'X-HQ-Focused' => '1',
    ]);
}

/** The page object embedded in a full document response. */
function SPEED_documentProps(TestResponse $response): array
{
    $html = (string) $response->getContent();

    // Inertia 3 embeds the page as JSON in a script tag; older builds used a `data-page` attribute.
    if (preg_match('/<script[^>]*data-page="app"[^>]*>(.*?)<\/script>/s', $html, $match) === 1) {
        $json = $match[1];
    } else {
        preg_match('/<div[^>]*data-page="([^"]+)"/', $html, $match);
        $json = html_entity_decode($match[1] ?? '', ENT_QUOTES | ENT_HTML5);
    }

    expect($json)->not->toBe('', 'no Inertia page object in the document');

    $page = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    return $page['props'];
}

beforeEach(function (): void {
    $this->seed();

    $this->speedAdmin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->speedYaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| 1. First paint
|--------------------------------------------------------------------------
*/

it('resolves the shell into the first paint and costs fewer statements than the old two requests', function (string $actor, string $url, string $component): void {
    $user = $this->{$actor};

    // Before: the document without `shell` (what an Inertia visit to the same URL still runs),
    // then the shell's opening partial reload, which ran the whole controller again.
    $withoutShell = SPEED_measure($user, $url, SPEED_inertia());
    $shellReload = SPEED_measure($user, $url, SPEED_partial($component, 'shell'));
    $before = $withoutShell['count'] + $shellReload['count'];

    // After: one document that already carries it.
    $document = SPEED_measure($user, $url);
    $props = SPEED_documentProps($document['response']);

    expect($props)->toHaveKey('shell')
        ->and($props['shell'])->toHaveKeys(['unreadMessages', 'announcement', 'announcementChannelId'])
        ->and($document['count'])->toBeLessThan($before, sprintf(
            '%s first paint: %d statements, the old document + shell reload was %d + %d = %d',
            $url, $document['count'], $withoutShell['count'], $shellReload['count'], $before,
        ));

    // Every Inertia request still asks by name: a navigation does not carry it.
    expect($withoutShell['response']->json('props'))->not->toHaveKey('shell');
})->with([
    'admin dashboard' => ['speedAdmin', '/admin/dashboard', 'Admin/Dashboard'],
    'employee dashboard' => ['speedYaseen', '/employee/dashboard', 'Employee/Dashboard'],
    'messages' => ['speedAdmin', '/messages', 'Shared/Messages'],
]);

it('keeps the shell out of a document that is not past two-factor enrolment', function (): void {
    config(['auth.two_factor.enforced' => true]);

    // An Admin who has signed in with a password but not enrolled yet (the test database's copy).
    $this->speedAdmin->forceFill(['two_factor_confirmed_at' => null])->save();

    $response = $this->actingAs($this->speedAdmin->fresh())->get('/two-factor/enrol');

    $response->assertOk();
    expect(SPEED_documentProps($response))->not->toHaveKey('shell');
});

it('leaves the shell out of a signed-out document', function (): void {
    $response = $this->get('/login');

    $response->assertOk();
    expect(SPEED_documentProps($response))->not->toHaveKey('shell');
});

/*
|--------------------------------------------------------------------------
| 2. Lazy heavy props
|--------------------------------------------------------------------------
*/

it('does not build the open thread on a rail poll of /messages', function (): void {
    $team = app(ConversationService::class)->team();
    $url = '/messages?conversation='.$team->getKey();

    // Warm up once, so the mark-read write a first focused read makes is not in either count.
    SPEED_measure($this->speedAdmin, $url, SPEED_inertia());

    $rail = SPEED_measure($this->speedAdmin, $url, SPEED_partial('Shared/Messages', 'conversations,announcement'));
    $withThread = SPEED_measure($this->speedAdmin, $url, SPEED_partial('Shared/Messages', 'conversations,announcement,active'));
    $full = SPEED_measure($this->speedAdmin, $url, SPEED_inertia());

    expect($rail['response']->json('props'))->not->toHaveKey('active')
        ->and($rail['count'])->toBeLessThan($withThread['count'], sprintf(
            'rail poll ran %d statements, the same poll naming `active` %d', $rail['count'], $withThread['count'],
        ))
        ->and($rail['count'])->toBeLessThan($full['count'])
        // Anybody who names it, and every full visit, still gets the thread.
        ->and($withThread['response']->json('props.active.conversation_id'))->toBe((int) $team->getKey())
        ->and($full['response']->json('props.active.conversation_id'))->toBe((int) $team->getKey());
});

it('does not build the discussion on a task-detail poll', function (string $actor, string $prefix, string $component): void {
    $user = $this->{$actor};
    $task = Task::visibleTo($user)->whereNotNull('project_id')->firstOrFail();
    $url = $prefix.'/tasks/'.$task->getKey();

    $poll = SPEED_measure($user, $url, SPEED_partial($component, 'task,activity'));
    $withDiscussion = SPEED_measure($user, $url, SPEED_partial($component, 'task,activity,discussion'));
    $full = SPEED_measure($user, $url, SPEED_inertia());

    expect($poll['response']->json('props'))->not->toHaveKey('discussion')
        ->and($poll['count'])->toBeLessThan($withDiscussion['count'], sprintf(
            'task poll ran %d statements, the same poll naming `discussion` %d', $poll['count'], $withDiscussion['count'],
        ))
        ->and($withDiscussion['response']->json('props.discussion'))->toBeArray()
        ->and($full['response']->json('props.discussion'))->toBeArray()
        ->and($full['response']->json('props.discussion'))->toHaveKey('messages');
})->with([
    'admin' => ['speedAdmin', '/admin', 'Admin/Tasks/Show'],
    'employee' => ['speedYaseen', '/employee', 'Employee/Tasks/Show'],
]);

it('still refuses a task it may not see before any discussion is built', function (): void {
    $hidden = Task::query()
        ->whereNotIn('id', Task::visibleTo($this->speedYaseen)->select('id'))
        ->firstOrFail();

    $this->actingAs($this->speedYaseen)
        ->get('/employee/tasks/'.$hidden->getKey(), SPEED_partial('Employee/Tasks/Show', 'discussion'))
        ->assertNotFound();
});
