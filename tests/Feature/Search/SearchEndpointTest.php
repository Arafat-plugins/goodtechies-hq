<?php

use App\Models\User;
use App\Services\SearchService;

/*
|--------------------------------------------------------------------------
| GET /search — the route, the envelope, the caps and the links
|--------------------------------------------------------------------------
|
| tests/Feature/Search/SearchScopingTest.php owns the privacy rule. This
| file owns the endpoint's contract: the shape it always answers with, the
| two ends of the term-length rule (decision M-4), the caps, and the one
| thing a search result is FOR — a link that opens.
|
| The deep-link tests are here rather than with the scoping tests because
| they are not a privacy rule; they are the Meetings slice's lesson. An
| employee handed `/admin/projects/12` meets a 403 dressed up as a link, so
| every href is resolved on the SERVER for the viewer's own surface.
|
| Every constant and helper here is prefixed SEARCH_ENDPOINT_, because Pest
| declares both globally across the whole suite (AGENTS.md).
|
*/

/** Every key a single result carries on the wire, and the whole of it. */
const SEARCH_ENDPOINT_RESULT_KEYS = ['type', 'id', 'label', 'snippet', 'href'];

/** Every key the envelope carries. */
const SEARCH_ENDPOINT_ENVELOPE_KEYS = ['term', 'total', 'truncated', 'groups'];

/** @return array<string, mixed> */
function searchEndpointBody(User $user, string $query): array
{
    return test()->actingAs($user)->getJson('/search?'.$query)->assertOk()->json();
}

/**
 * Every result row in a response, flattened.
 *
 * @param  array<string, mixed>  $body
 * @return list<array<string, mixed>>
 */
function searchEndpointRows(array $body): array
{
    $rows = [];

    foreach ($body['groups'] as $group) {
        foreach ($group['results'] as $row) {
            $rows[] = $row;
        }
    }

    return $rows;
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| The envelope
|--------------------------------------------------------------------------
*/

it('answers with exactly four envelope keys and five keys per result', function () {
    // An exact key-set assertion, the shape AccountantProjectEndpointTest uses: a check for
    // three field names passes the day somebody adds a fourth. `rank` in particular must not
    // appear — `ts_rank` scores are not comparable between types and a number on the wire is a
    // number somebody eventually renders.
    $body = searchEndpointBody($this->admin, 'q=Buffalo');

    expect(array_keys($body))->toEqualCanonicalizing(SEARCH_ENDPOINT_ENVELOPE_KEYS);

    $rows = searchEndpointRows($body);
    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect(array_keys($row))->toEqualCanonicalizing(SEARCH_ENDPOINT_RESULT_KEYS);
    }
})->group('phase10', 'search');

it('gives every group a type and a human label, because the palette renders them as headings', function () {
    $body = searchEndpointBody($this->admin, 'q=Buffalo');

    foreach ($body['groups'] as $group) {
        expect(array_keys($group))->toEqualCanonicalizing(['type', 'label', 'results'])
            ->and($group['label'])->not->toBeEmpty()
            ->and($group['results'])->not->toBeEmpty();
    }
})->group('phase10', 'search');

/*
|--------------------------------------------------------------------------
| The short query is 200, and the long one is 422 (decision M-4)
|--------------------------------------------------------------------------
*/

it('answers a short or missing term with 200 and an empty envelope, never 422', function (string $query) {
    // The palette sends a request per keystroke. A `min:2` validation rule here would make the
    // box flash a validation error on the first letter of every search anybody ever ran —
    // decision M-4, made for message search and sharper for a command palette. A half-typed
    // term is not a malformed request.
    $body = searchEndpointBody($this->admin, $query);

    expect($body['total'])->toBe(0)
        ->and($body['groups'])->toBe([])
        // The envelope keeps its shape, so the palette has one thing to render and never a
        // special case for "you have not typed enough yet".
        ->and(array_keys($body))->toEqualCanonicalizing(SEARCH_ENDPOINT_ENVELOPE_KEYS);
})->with([
    'nothing at all' => '',
    'an empty q' => 'q=',
    'one character' => 'q=B',
    'one character and a space' => 'q=B+',
])->group('phase10', 'search');

it('answers a term nobody could have typed with 422', function () {
    // The other end of M-4: past a hundred characters the request did not come from the
    // palette, and tokenising it into an arbitrarily large tsquery across eight indexes is
    // work nobody asked for. This one IS malformed and says so.
    $this->actingAs($this->admin)
        ->getJson('/search?q='.str_repeat('a', SearchService::MAXIMUM + 1))
        ->assertStatus(422);
})->group('phase10', 'search');

it('answers punctuation that contains no word with 200 and nothing in it', function (string $term) {
    // A bare `@`, a row of symbols, a lone quote. None of these produce a lexeme, so none of
    // them can match anything — but `to_tsquery` THROWS on a malformed query, so the
    // alternative to this branch is a 500 on a keystroke. They are answered the way a term
    // that matches nothing is answered, because that is what they are.
    $body = searchEndpointBody($this->admin, 'q='.urlencode($term));

    expect($body['total'])->toBe(0)
        ->and($body['groups'])->toBe([]);
})->with([
    'a bare at-sign' => '@',
    'operators from the tsquery grammar' => '& | ! ( ) :',
    'a lone quote' => '"',
    'only punctuation' => '%%% ...',
    'a lone hyphen' => '-',
])->group('phase10', 'search');

/*
|--------------------------------------------------------------------------
| What a user actually types
|--------------------------------------------------------------------------
*/

it('treats an unquoted term as prefix-matched words that must all appear', function () {
    // `buffalo modular` → `buffalo:* & modular:*`. Prefix-matching is why the palette is
    // usable before the word is finished, and the `&` is why adding a word narrows rather
    // than widens.
    $whole = searchEndpointBody($this->admin, 'q=buffalo+modular');
    $partial = searchEndpointBody($this->admin, 'q=buff+mod');

    $labels = fn (array $body): array => array_map(fn (array $r): string => $r['label'], searchEndpointRows($body));

    expect($labels($whole))->toContain('Buffalo Modular — SEO')
        // Half-typed finds the same thing, which is the whole argument for `simple` plus `:*`
        // over the `english` stemmer.
        ->and($labels($partial))->toContain('Buffalo Modular — SEO');
})->group('phase10', 'search');

it('treats a quoted term as an exact phrase, in that order', function () {
    // `"home model"` → `home <-> model`: adjacent, IN THAT ORDER, and not prefix-matched,
    // because somebody who reached for quotes asked for exactness.
    //
    // The seed makes this testable in the sharpest possible way: two seeded rows say
    // "Home Model pages" and two others say "model-home", so the phrase and its reversal
    // return DISJOINT sets, and the loose form returns both. A phrase that merely returned
    // "fewer" results would be consistent with quotes doing nothing at all.
    $labels = fn (string $q): array => array_map(
        fn (array $r): string => $r['label'],
        searchEndpointRows(searchEndpointBody($this->admin, 'q='.urlencode($q))),
    );

    $forwards = $labels('"Home Model"');
    $backwards = $labels('"Model Home"');
    $loose = $labels('Home Model');

    expect($forwards)->toContain('Buffalo Modular — SEO')
        ->and($forwards)->toContain('Optimize Home Model pages')
        // The reversal finds the other two rows and none of these.
        ->and($forwards)->not->toContain('Rebuild the model-home gallery template')
        ->and($backwards)->toContain('Rebuild the model-home gallery template')
        ->and($backwards)->not->toContain('Optimize Home Model pages');

    // Unquoted, the words may appear anywhere in the row: both phrasings come back.
    expect($loose)->toContain('Optimize Home Model pages')
        ->and($loose)->toContain('Rebuild the model-home gallery template')
        ->and(count($loose))->toBeGreaterThan(count($forwards));
})->group('phase10', 'search');

it('ignores an unbalanced quote and searches the words either side of it', function () {
    // `buffalo "` has no closing quote, so there is no phrase to extract — and the honest
    // reading of it is a search for `buffalo`, not an error and not an empty result. A palette
    // user types the opening quote one keystroke before the closing one, every single time, so
    // this is a state every quoted search passes through.
    $withQuote = searchEndpointBody($this->admin, 'q='.urlencode('buffalo "'));
    $without = searchEndpointBody($this->admin, 'q=buffalo');

    expect($withQuote['total'])->toBe($without['total'])
        ->and($withQuote['total'])->toBeGreaterThan(0);
})->group('phase10', 'search');

/*
|--------------------------------------------------------------------------
| The caps
|--------------------------------------------------------------------------
*/

it('never returns more than the per-type cap in one group', function () {
    // A term broad enough to match most of the database, run by the person who can see all of
    // it. The palette is "find the thing I am thinking of", not a report.
    $body = searchEndpointBody($this->admin, 'q=e');

    foreach ($body['groups'] as $group) {
        expect(count($group['results']))->toBeLessThanOrEqual(SearchService::PER_TYPE);
    }
})->group('phase10', 'search');

it('never returns more than the overall cap, and says so when it truncates', function () {
    $body = searchEndpointBody($this->admin, 'q='.urlencode('se'));

    $total = count(searchEndpointRows($body));

    expect($total)->toBeLessThanOrEqual(SearchService::OVERALL)
        // `total` counts what is IN the response. It is never the size of the match before
        // scoping, because no such number is computed anywhere — which is the count rule.
        ->and($body['total'])->toBe($total)
        ->and($body['truncated'])->toBeBool();
})->group('phase10', 'search');

/*
|--------------------------------------------------------------------------
| The links point at the viewer's own shell
|--------------------------------------------------------------------------
*/

it('sends an admin to admin addresses and an employee to employee addresses', function () {
    // The Meetings slice's lesson, as an assertion. An employee handed `/admin/projects/12`
    // meets a 403 dressed up as a link, so the base is resolved on the server per viewer.
    $adminRows = searchEndpointRows(searchEndpointBody($this->admin, 'q=Buffalo'));
    $employeeRows = searchEndpointRows(searchEndpointBody($this->tapu, 'q=Buffalo'));

    $projects = fn (array $rows): array => array_values(array_filter(
        $rows,
        fn (array $r): bool => in_array($r['type'], ['project', 'task', 'file'], true),
    ));

    expect($projects($adminRows))->not->toBeEmpty()
        ->and($projects($employeeRows))->not->toBeEmpty();

    foreach ($projects($adminRows) as $row) {
        expect($row['href'])->toStartWith('/admin/');
    }

    foreach ($projects($employeeRows) as $row) {
        expect($row['href'])->toStartWith('/employee/');
        // The sharp version: never the other shell's address, whatever else is true.
        expect($row['href'])->not->toStartWith('/admin/');
    }
})->group('phase10', 'search');

it('gives every result a link the viewer can actually open', function (string $user, string $term) {
    // Following every href and asserting it is not a 403 or a 404 is the only version of this
    // test that means anything: a link that 403s is worse than no link, because the reader
    // concludes the application is broken rather than that the record is not theirs.
    $rows = searchEndpointRows(searchEndpointBody($this->{$user}, 'q='.urlencode($term)));

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        $status = test()->actingAs($this->{$user})->get($row['href'])->getStatusCode();

        expect($status)->toBeLessThan(400, "{$row['type']} #{$row['id']} linked to {$row['href']}, which answered {$status}");
    }
})->with([
    'the admin, searching work' => ['admin', 'Buffalo'],
    'the admin, searching the books' => ['admin', 'September'],
    'an employee, searching work' => ['tapu', 'Buffalo'],
    'the accountant, searching the books' => ['accountant', 'September'],
])->group('phase10', 'search');

it('sends meeting and message results to the shared routes both shells use', function () {
    // Whose calendar a meeting is on belongs to the person, not to the shell, so these two
    // have one address each and every surface uses it — the same reason those route groups are
    // in routes/shared.php at all.
    foreach (searchEndpointRows(searchEndpointBody($this->tapu, 'q=Buffalo')) as $row) {
        if ($row['type'] === 'meeting') {
            expect($row['href'])->toStartWith('/meetings/');
        }

        if ($row['type'] === 'message') {
            expect($row['href'])->toStartWith('/messages/');
        }
    }
})->group('phase10', 'search');

it('sends a finance result to the ledger month rather than to an edit form', function () {
    // `…/{id}/edit` is an ACT on a record, guarded by `finance.manage` through the policy. A
    // search result that lands a read-only viewer on a form they may not submit is a link that
    // lies, so the month's ledger is the destination — which is also how every finance screen
    // in this application is addressed.
    $rows = searchEndpointRows(searchEndpointBody($this->accountant, 'q=September'));

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row['href'])->toMatch('#^/finance/(income|expenses)\?month=\d{4}-\d{2}$#')
            ->and($row['href'])->not->toContain('/edit');
    }
})->group('phase10', 'search');

/*
|--------------------------------------------------------------------------
| The snippet
|--------------------------------------------------------------------------
*/

it('sends null rather than an empty string for a type that has nothing more to say', function () {
    // A client, a person and a file are a name and nothing else. "There is nothing more to
    // say about this row" and "the excerpt came out blank" are different facts, and the
    // palette renders the first as one line and the second as a broken second one.
    foreach (searchEndpointRows(searchEndpointBody($this->admin, 'q=Buffalo')) as $row) {
        if (in_array($row['type'], ['client', 'employee', 'file'], true)) {
            expect($row['snippet'])->toBeNull();
        }

        if ($row['snippet'] !== null) {
            expect($row['snippet'])->toBeString()->not->toBe('');
        }
    }
})->group('phase10', 'search');

it('keeps a snippet to one line, whatever the note behind it looks like', function () {
    foreach (searchEndpointRows(searchEndpointBody($this->admin, 'q=model')) as $row) {
        if ($row['snippet'] === null) {
            continue;
        }

        // The ellipsis characters are part of the cut, so the bound is the window plus them.
        expect(mb_strlen($row['snippet']))->toBeLessThanOrEqual(SearchService::SNIPPET_LENGTH + 2)
            ->and($row['snippet'])->not->toContain("\n");
    }
})->group('phase10', 'search');

/*
|--------------------------------------------------------------------------
| The route itself
|--------------------------------------------------------------------------
*/

it('is reachable by name and lives at /search', function () {
    expect(route('search.index', absolute: false))->toBe('/search');
})->group('phase10', 'search');

it('sends a signed-out request to the login page', function () {
    $this->getJson('/search?q=Buffalo')->assertUnauthorized();
    $this->get('/search?q=Buffalo')->assertRedirect('/login');
})->group('phase10', 'search');
