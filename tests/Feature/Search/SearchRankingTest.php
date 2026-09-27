<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\SearchService;
use App\Support\SearchableType;

/*
|--------------------------------------------------------------------------
| Ranking — a title beats a body, and the same question gets the same answer
|--------------------------------------------------------------------------
|
| Part D §17 ends "Postgres tsvector", and ranking is the half of that
| sentence the scoping tests do not touch. Two properties matter and they
| are both about the reader rather than about relevance theory:
|
|   1. A row whose NAME is what you typed comes before a row that merely
|      mentions it. This is not a preference — it is the difference between
|      a palette that finds things and one that has to be scrolled.
|
|   2. The same query returns the same order, twice running. The palette
|      re-fetches on every keystroke with a selection sitting in the list;
|      an order that reshuffles under that selection moves the highlight
|      onto a different row between a person deciding to press Enter and
|      pressing it.
|
| The weights that produce (1) are set in the GENERATED COLUMN, not in the
| query — `setweight(…, 'A')` on the name, `'C'` on the body — so the
| ranking cannot disagree with itself between two call sites, and a query
| does not need to know which column matched.
|
| Every constant and helper here is prefixed SEARCH_RANKING_, because Pest
| declares both globally across the whole suite (AGENTS.md).
|
*/

/** A word planted in one row's title and another row's body, matching nothing seeded. */
const SEARCH_RANKING_TERM = 'kingfisher';

/**
 * The labels of one type, in the order the endpoint returned them.
 *
 * @return list<string>
 */
function searchRankingOrder(User $user, string $term, string $type): array
{
    $body = test()->actingAs($user)->getJson('/search?q='.urlencode($term))->assertOk()->json();

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
    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| A title outranks a body
|--------------------------------------------------------------------------
*/

it('puts the task whose title matches above the task that only mentions the word', function () {
    // Two tasks, one term. The only difference between them is WHICH column carries the word,
    // which is exactly what the `A`/`C` weights in the generated column exist to distinguish.
    // The body row is created FIRST so that the id tie-breaker — newest first — would put it
    // on top if the ranking did nothing. The test therefore fails if the weights are dropped,
    // rather than passing by accident on insertion order.
    $body = Task::factory()->for($this->project)->create([
        'title' => 'Write the quarterly summary',
        'description' => 'Mention the '.SEARCH_RANKING_TERM.' campaign in the introduction.',
    ]);

    $title = Task::factory()->for($this->project)->create([
        'title' => 'Launch the '.SEARCH_RANKING_TERM.' campaign',
        'description' => 'No further notes.',
    ]);

    $order = searchRankingOrder($this->admin, SEARCH_RANKING_TERM, 'task');

    expect($order)->toHaveCount(2)
        ->and($order[0])->toBe($title->title)
        ->and($order[1])->toBe($body->title);
})->group('phase10', 'search');

it('puts the project whose name matches above the project that only mentions the word in its notes', function () {
    // The same assertion for the other weighted vector, because the weights are declared per
    // table and a copy-paste that dropped `setweight` on one of them would pass the test above.
    $notes = Project::factory()->create([
        'name' => 'Internal tooling refresh',
        'employee_notes' => 'Reuse the '.SEARCH_RANKING_TERM.' illustrations from last year.',
    ]);

    $named = Project::factory()->create([
        'name' => SEARCH_RANKING_TERM.' microsite',
        'employee_notes' => 'No further notes.',
    ]);

    $order = searchRankingOrder($this->admin, SEARCH_RANKING_TERM, 'project');

    expect($order)->toHaveCount(2)
        ->and($order[0])->toBe($named->name)
        ->and($order[1])->toBe($notes->name);
})->group('phase10', 'search');

it('ranks the domain between the name and the notes', function () {
    // `B` exists for a reason and this is it: a domain is how Part C §2 lets an employee tell
    // two "— Website Maintenance" rows apart, so it must outrank a passing mention in a note
    // and never outrank the project's own name.
    $notes = Project::factory()->create([
        'name' => 'Unrelated retainer',
        'domain' => 'unrelated.test',
        'employee_notes' => 'Coordinate with the '.SEARCH_RANKING_TERM.' team.',
    ]);

    $domain = Project::factory()->create([
        'name' => 'Another retainer',
        'domain' => SEARCH_RANKING_TERM.'.test',
        'employee_notes' => 'No further notes.',
    ]);

    $named = Project::factory()->create([
        'name' => SEARCH_RANKING_TERM.' rebuild',
        'domain' => 'rebuild.test',
        'employee_notes' => 'No further notes.',
    ]);

    expect(searchRankingOrder($this->admin, SEARCH_RANKING_TERM, 'project'))
        ->toBe([$named->name, $domain->name, $notes->name]);
})->group('phase10', 'search');

/*
|--------------------------------------------------------------------------
| The same question gets the same answer
|--------------------------------------------------------------------------
*/

it('returns the same order twice for the same term', function () {
    // The palette re-fetches on every keystroke. Two identical requests that disagree would
    // move the highlighted row between a person deciding to press Enter and pressing it, and
    // the bug would be unreproducible by definition.
    $first = searchRankingOrder($this->admin, 'Buffalo', 'project');
    $second = searchRankingOrder($this->admin, 'Buffalo', 'project');

    expect($first)->not->toBeEmpty()->and($second)->toBe($first);
})->group('phase10', 'search');

it('breaks a genuine rank tie by id, newest first', function () {
    // A real tie has to be built rather than borrowed: the three seeded Buffalo projects look
    // tied and are not, because `ts_rank` reads the whole document and their notes differ.
    // These three carry the SAME searchable text in every indexed column, so their vectors —
    // and therefore their ranks — are identical to the last bit.
    //
    // That is precisely when an unstable sort shows itself. `ORDER BY search_rank DESC, id
    // DESC` is what makes the answer deterministic; without the second key this passes on one
    // plan and reorders on another, which is the kind of bug that is only ever seen in
    // production.
    $ids = [];

    for ($i = 0; $i < 3; $i++) {
        $ids[] = (int) Project::factory()->create([
            'name' => SEARCH_RANKING_TERM.' retainer',
            'domain' => 'identical.test',
            'employee_notes' => 'Identical notes on every row.',
        ])->getKey();
    }

    $returned = test()->actingAs($this->admin)
        ->getJson('/search?q='.SEARCH_RANKING_TERM)
        ->assertOk()
        ->json('groups.0.results');

    expect(array_column($returned, 'id'))->toBe(array_reverse($ids));
})->group('phase10', 'search');

/*
|--------------------------------------------------------------------------
| Groups are ordered, not interleaved
|--------------------------------------------------------------------------
*/

it('keeps the groups in a fixed order rather than ranking types against each other', function () {
    // `ts_rank` scores are only comparable WITHIN one vector: a project's A-weighted name hit
    // and a task's A-weighted title hit are the same number, so interleaving the two would
    // produce an order that changes under the user for no reason they can see. Groups are
    // stable and rows inside a group are ranked — see `SearchableType::inDisplayOrder()`.
    $body = test()->actingAs($this->admin)->getJson('/search?q=Buffalo')->assertOk()->json();

    $types = array_map(fn (array $group): string => $group['type'], $body['groups']);
    $expected = array_values(array_filter(
        array_map(fn ($case): string => $case->value, SearchableType::inDisplayOrder()),
        fn (string $type): bool => in_array($type, $types, true),
    ));

    expect($types)->toBe($expected)->and(count($types))->toBeGreaterThan(1);
})->group('phase10', 'search');

it('caps a group at the per-type limit even when everything in it ranks the same', function () {
    // The cap is applied to the SCOPED query, never to a merged pool — which is the count rule
    // wearing its performance hat. Twelve identically-ranked rows, eight returned.
    for ($i = 0; $i < 12; $i++) {
        Project::factory()->create(['name' => SEARCH_RANKING_TERM.' site '.$i]);
    }

    expect(searchRankingOrder($this->admin, SEARCH_RANKING_TERM, 'project'))
        ->toHaveCount(SearchService::PER_TYPE);
})->group('phase10', 'search');
