<?php

use App\Models\User;
use App\Support\SearchableType;

/*
|--------------------------------------------------------------------------
| GET /search?type=… — the palette's per-section scope (brief 010)
|--------------------------------------------------------------------------
|
| The top-bar search looks only in the section the person is in: on Tasks
| it finds tasks, on Projects projects, and the whole app only on a
| Dashboard. The server half is one optional `type` parameter that NARROWS
| the loop over `SearchableType::inDisplayOrder()` and does nothing else —
| every scope inside `SearchService` still runs exactly as it did, so a
| filter can hide a group and can never reveal a row.
|
| Constants and helpers are prefixed SEARCH_TYPE_ / searchType because Pest
| declares them globally across the suite (AGENTS.md).
|
*/

/** A seeded term that matches a project, a task, a meeting and a client for the Admin. */
const SEARCH_TYPE_WIDE_TERM = 'Buffalo';

/** A seeded task title word on a task Tapu is not assigned to. */
const SEARCH_TYPE_HIDDEN_TASK_TERM = 'gallery';

/** @return array<string, mixed> */
function searchTypeBody(User $user, string $query): array
{
    return test()->actingAs($user)->getJson('/search?'.$query)->assertOk()->json();
}

/**
 * @param  array<string, mixed>  $body
 * @return list<string>
 */
function searchTypeGroups(array $body): array
{
    return array_values(array_map(fn (array $group): string => $group['type'], $body['groups']));
}

/**
 * @param  array<string, mixed>  $body
 * @return list<string>
 */
function searchTypeLabels(array $body, string $type): array
{
    foreach ($body['groups'] as $group) {
        if ($group['type'] === $type) {
            return array_values(array_map(fn (array $row): string => $row['label'], $group['results']));
        }
    }

    return [];
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
});

it('returns only task groups for type=task', function () {
    // The precondition, so the narrowing below is a narrowing and not an empty term.
    expect(searchTypeGroups(searchTypeBody($this->admin, 'q='.SEARCH_TYPE_WIDE_TERM)))
        ->toContain('project', 'task', 'client');

    $body = searchTypeBody($this->admin, 'q='.SEARCH_TYPE_WIDE_TERM.'&type=task');

    expect(searchTypeGroups($body))->toBe(['task'])
        ->and($body['total'])->toBe(count(searchTypeLabels($body, 'task')));
})->group('search', 'brief010');

it('accepts several types, as an array or comma-separated, and keeps display order', function (string $types) {
    $body = searchTypeBody($this->admin, 'q='.SEARCH_TYPE_WIDE_TERM.'&'.$types);

    expect(searchTypeGroups($body))->toBe(['project', 'client']);
})->with([
    'array' => 'type[]=client&type[]=project',
    'comma' => 'type=client,project',
])->group('search', 'brief010');

it('searches every type when no type is given, exactly as before', function (string $query) {
    $all = searchTypeBody($this->admin, 'q='.SEARCH_TYPE_WIDE_TERM);
    $body = searchTypeBody($this->admin, 'q='.SEARCH_TYPE_WIDE_TERM.$query);

    expect($body)->toBe($all)
        ->and(searchTypeGroups($body))->toBe(['project', 'task', 'meeting', 'client']);
})->with([
    'absent' => '',
    'empty' => '&type=',
])->group('search', 'brief010');

it('answers an unknown type with 422, never 500', function (string $query) {
    $response = $this->actingAs($this->admin)
        ->getJson('/search?q='.SEARCH_TYPE_WIDE_TERM.'&'.$query)
        ->assertStatus(422)
        ->assertJsonMissingPath('groups');

    // The error sits on `type` or one of its items (`type.1`), and nowhere else.
    expect(array_keys($response->json('errors')))->not->toBeEmpty()
        ->each->toStartWith('type');
})->with([
    'unknown word' => 'type=salary',
    'one bad of two' => 'type=task,salary',
    'array' => 'type[]=task&type[]=payroll',
    'nested array' => 'type[0][]=task',
])->group('search', 'brief010');

it('gives an employee only their own tasks under type=task', function () {
    $admin = searchTypeBody($this->admin, 'q='.SEARCH_TYPE_HIDDEN_TASK_TERM.'&type=task');
    $tapu = searchTypeBody($this->tapu, 'q='.SEARCH_TYPE_HIDDEN_TASK_TERM.'&type=task');

    expect(searchTypeLabels($admin, 'task'))->toContain('Rebuild the model-home gallery template')
        ->and($tapu['groups'])->toBe([])
        ->and($tapu['total'])->toBe(0);

    // And on a term Tapu DOES match across types, only the task group comes back, and it is
    // the unfiltered task group unchanged: the filter narrows which groups run, never what a
    // group may contain.
    $filtered = searchTypeBody($this->tapu, 'q='.SEARCH_TYPE_WIDE_TERM.'&type=task');

    expect(searchTypeGroups($filtered))->toBe(['task'])
        ->and(searchTypeLabels($filtered, 'task'))
        ->toBe(searchTypeLabels(searchTypeBody($this->tapu, 'q='.SEARCH_TYPE_WIDE_TERM), 'task'))
        ->not->toBeEmpty();
})->group('search', 'brief010');

it('still hides projects an employee is not on under type=project', function () {
    expect(searchTypeLabels(searchTypeBody($this->admin, 'q=Website+Development&type=project'), 'project'))
        ->toContain('Buffalo Modular — Website Development')
        ->and(searchTypeBody($this->tapu, 'q=Website+Development&type=project')['groups'])->toBe([]);

    // On a term that matches Tapu's own project and other types too, only projects come back,
    // and still only the one he is on.
    $filtered = searchTypeBody($this->tapu, 'q='.SEARCH_TYPE_WIDE_TERM.'&type=project');

    expect(searchTypeGroups($filtered))->toBe(['project'])
        ->and(searchTypeLabels($filtered, 'project'))
        ->toBe(searchTypeLabels(searchTypeBody($this->tapu, 'q='.SEARCH_TYPE_WIDE_TERM), 'project'))
        ->not->toContain('Buffalo Modular — Website Development');
})->group('search', 'brief010');

it('cannot widen what the accountant finds by naming a type they have no key for', function () {
    // `September` finds the Accountant finance rows unfiltered (SearchScopingTest). Asking for
    // a type they hold no key for answers nothing, not the finance rows and not the type.
    expect(searchTypeBody($this->accountant, 'q=September')['groups'])->not->toBe([]);

    foreach ([SearchableType::Task, SearchableType::Project, SearchableType::Client] as $type) {
        expect(searchTypeBody($this->accountant, 'q=September&type='.$type->value)['groups'])->toBe([])
            ->and(searchTypeBody($this->accountant, 'q='.SEARCH_TYPE_WIDE_TERM.'&type='.$type->value)['groups'])
            ->toBe([]);
    }

    expect(searchTypeGroups(searchTypeBody($this->accountant, 'q=September&type=income')))->toBe(['income']);
})->group('search', 'brief010');
