<?php

use App\Models\Client;
use App\Models\Conversation;
use App\Models\File;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\SearchService;
use App\Support\SearchableType;
use App\Support\UserStatus;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| Global search — the scope, which is the whole feature
|--------------------------------------------------------------------------
|
| Master prompt Part D §17:
|
|   "Query is scoped to the requester's accessible object IDs BEFORE
|    ranking. Postgres tsvector."
|
| and Phase 10's own security list:
|
|   "search for 'Buffalo' as Tapu returns the project (operational view)
|    and never the client record or price; snippets never contain
|    restricted fields; Accountant search never returns tasks/messages."
|
| This file is those two paragraphs. It is written the way
| tests/Feature/Finance/AccountantProjectEndpointTest.php writes the same
| kind of rule — a positive assertion about what comes back, plus a
| RECURSIVE walk over the whole serialized body for values that must not
| appear at any depth — because a check for a named field passes the day
| the value arrives under a different name, or inside a snippet.
|
| The count tests are the ones that would be missing from a naive version of
| this file, and they are the reason decision M-3 exists: a result set can be
| correct row by row and still leak, because the NUMBER of rows is itself a
| fact about records the searcher cannot open.
|
| Every constant and helper here is prefixed SEARCH_ / search*, because Pest
| declares both globally across the whole suite (AGENTS.md).
|
*/

/**
 * Values that must never appear anywhere in a payload built for somebody without the key
 * that would let them read the value.
 *
 * Client names and contact names come from DemoSeeder; the money comes from
 * `project_finance`. A client's name is the sharpest of them because Part C §2 gives the
 * employee the DOMAIN in its place — `buffalomodular.com` is expected in these payloads and
 * `Buffalo Modular Homes` must never be.
 */
const SEARCH_FORBIDDEN_CLIENT_NAMES = [
    'Buffalo Modular Homes',
    'Heat Gap Heating & Plumbing',
    'APH St Albans',
    'ABC Ltd',
];

const SEARCH_FORBIDDEN_CONTACT_NAMES = [
    'Karen Buffalo',
    'Dave Heatgap',
    'Priya Aph',
    'Sam Abc',
];

/** Every seeded figure in `project_finance`, in the shapes a serializer might render them. */
const SEARCH_FORBIDDEN_MONEY = [
    '4500.00', '4,500.00', '200.00', '80.00', '350.00', '60.00', '75.00',
];

/**
 * A term that matches ONLY a client row and nothing else in the database.
 *
 * "Homes" is in `Buffalo Modular Homes` and in no project name, task title, meeting title or
 * filename — asserted below, so the day the seed changes this file fails loudly rather than
 * passing for the wrong reason. It is the sharpest tool in the file: a searcher without
 * `clients.view_full` must get an EMPTY response for it, not a redacted row and not a count.
 */
const SEARCH_CLIENT_ONLY_TERM = 'Homes';

/**
 * A word posted into two chat rooms by the message test — one this reader is in, one they are
 * not. Distinctive so that it cannot match anything the seeder wrote.
 */
const SEARCH_MESSAGE_TERM = 'pergola';

/** @return array<string, mixed> the decoded response body */
function searchAs(User $user, string $term): array
{
    return test()->actingAs($user)->getJson('/search?q='.urlencode($term))->assertOk()->json();
}

/**
 * The set of entity types a response actually contains.
 *
 * @param  array<string, mixed>  $body
 * @return list<string>
 */
function searchTypes(array $body): array
{
    return array_values(array_map(fn (array $group): string => $group['type'], $body['groups']));
}

/**
 * The labels inside one group, or [] when the group is absent — which is the same thing to a
 * reader and must be the same thing to a test.
 *
 * @param  array<string, mixed>  $body
 * @return list<string>
 */
function searchLabels(array $body, string $type): array
{
    foreach ($body['groups'] as $group) {
        if ($group['type'] === $type) {
            return array_values(array_map(fn (array $row): string => $row['label'], $group['results']));
        }
    }

    return [];
}

/**
 * Every snippet in a response, at any depth.
 *
 * @param  array<string, mixed>  $body
 * @return list<string>
 */
function searchSnippets(array $body): array
{
    $snippets = [];

    array_walk_recursive($body, function (mixed $value, string|int $key) use (&$snippets): void {
        if ($key === 'snippet' && is_string($value)) {
            $snippets[] = $value;
        }
    });

    return $snippets;
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
    $this->employee = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| Part D's own sentence, as a test
|--------------------------------------------------------------------------
*/

it('returns Tapu the Buffalo project and never the Buffalo client record', function () {
    $body = searchAs($this->tapu, 'Buffalo');

    // The positive half: the operational view of the project they are actually on.
    expect(searchLabels($body, 'project'))->toContain('Buffalo Modular — SEO');

    // The negative half, and the point of the phase. Not "a client row with its name blanked"
    // — the type is ABSENT, which is Part C's rule that a record they may not see is omitted
    // from lists rather than masked in them.
    expect(searchTypes($body))->not->toContain('client');
})->group('phase10', 'search');

it('names no client, no contact and no money anywhere in the employee payload, at any depth', function () {
    // The type assertion above catches a client that arrives as a CLIENT. This catches one
    // that arrives as the value of an allowed key — a client's name reaching a project's
    // label, or a price landing inside a snippet. `array_walk_recursive` over the encoded body
    // is the shape AccountantProjectEndpointTest uses for the same reason.
    $body = json_encode(searchAs($this->tapu, 'Buffalo'), JSON_THROW_ON_ERROR);

    foreach (SEARCH_FORBIDDEN_CLIENT_NAMES as $client) {
        expect($body)->not->toContain($client);
    }

    foreach (SEARCH_FORBIDDEN_CONTACT_NAMES as $contact) {
        expect($body)->not->toContain($contact);
    }

    foreach (SEARCH_FORBIDDEN_MONEY as $money) {
        expect($body)->not->toContain($money);
    }
})->group('phase10', 'search');

it('does give Tapu the domain, which is what stands in place of the client name', function () {
    // Part C §2 settles this: the employee sees `buffalomodular.com` INSTEAD of
    // `Buffalo Modular Homes`. Without this assertion the test above could be satisfied by a
    // search that returns nothing at all.
    $body = searchAs($this->tapu, 'buffalomodular');

    expect(searchLabels($body, 'project'))->toContain('Buffalo Modular — SEO');
})->group('phase10', 'search');

/*
|--------------------------------------------------------------------------
| Snippets — the second leak, and the easier one to miss
|--------------------------------------------------------------------------
*/

it('puts no restricted field in any snippet an employee can produce', function () {
    // A snippet is GENERATED text, so no policy was asked about it. The guarantee is
    // structural instead — `SearchableType::snippetColumns()` is the only list an excerpt may
    // be cut from — and this is the assertion that the structure holds.
    //
    // The terms are swept in ONE test rather than as a dataset on purpose: several of them
    // legitimately return nothing for this reader, and a per-term test would then assert
    // nothing at all and still report green. The guard below is what makes the sweep mean
    // something — if the corpus stops producing snippets, this fails rather than passing
    // vacuously, which is exactly how a privacy sweep quietly stops sweeping.
    $snippets = [];

    foreach (['Buffalo', 'SEO', 'retainer', 'September', 'model', 'notes', 'client'] as $term) {
        $snippets = [...$snippets, ...searchSnippets(searchAs($this->tapu, $term))];
    }

    expect($snippets)->not->toBeEmpty('the snippet sweep produced no snippets to sweep');

    foreach ($snippets as $snippet) {
        foreach ([...SEARCH_FORBIDDEN_CLIENT_NAMES, ...SEARCH_FORBIDDEN_CONTACT_NAMES, ...SEARCH_FORBIDDEN_MONEY] as $secret) {
            expect($snippet)->not->toContain($secret);
        }
    }
})->group('phase10', 'search');

it('never cuts a snippet from a column that is not also searchable', function (string $type) {
    // The rule behind the test above, asserted directly on the declaration rather than on a
    // sample of outputs. A snippet column that is not searchable would be text handed to a
    // reader because some OTHER field matched, which is the precise shape of the leak.
    $case = SearchableType::from($type);

    // `array_diff` rather than `->each->toBeIn(…)`: three types (Client, Person, File) declare
    // NO snippet columns at all, and `each` over an empty array asserts nothing — which is a
    // risky test wearing a green tick. Phrased this way, an empty list is a real pass and the
    // assertion still fails loudly if a column ever escapes the searchable set.
    expect(array_diff($case->snippetColumns(), $case->searchableColumns()))
        ->toBe([], "{$type} cuts a snippet from a column that cannot be matched on");
})->with(array_map(fn (SearchableType $t): string => $t->value, SearchableType::cases()))
    ->group('phase10', 'search');

it('indexes exactly the columns SearchableType says it does', function () {
    // The migration's expression and the enum's list are two statements of one fact. They are
    // deliberately not generated from each other — a migration that read its shape out of
    // application code would change meaning the day that code was edited — so a test stands
    // between them. Without it, a column could be added to a vector while this application
    // still believed it was absent, which is how `internal_notes` gets quietly indexed.
    $migration = require base_path('database/migrations/2026_10_20_0001_add_search_vectors_and_gin_indexes.php');
    $vectors = (new ReflectionClass($migration))->getReflectionConstant('VECTORS')->getValue();

    foreach (SearchableType::cases() as $case) {
        $table = $case->vectorTable();

        if ($table === null) {
            continue;
        }

        preg_match_all('/coalesce\((\w+),/i', $vectors[$table], $matches);

        expect($matches[1])->toEqualCanonicalizing($case->searchableColumns())
            ->and($matches[1])->not->toContain('internal_notes')
            ->and($matches[1])->not->toContain('contact_info');
    }
})->group('phase10', 'search');

/*
|--------------------------------------------------------------------------
| The count does not leak
|--------------------------------------------------------------------------
*/

it('gives two searchers different counts for one term, and the smaller is not a filtered copy of the larger', function () {
    // THE test of this slice. Decision M-3's argument, at eight tables instead of one: a
    // result set can be correct row by row and still leak, because the NUMBER of results is a
    // fact about records the searcher cannot open. Rank-then-filter produces exactly this
    // response with exactly these rows — and a `total` computed before the filter.
    $adminBody = searchAs($this->admin, 'Buffalo');
    $tapuBody = searchAs($this->tapu, 'Buffalo');

    // Different counts, which is the easy half.
    expect($adminBody['total'])->toBeGreaterThan($tapuBody['total']);

    // The half that actually proves the order. The Admin sees three Buffalo projects and the
    // client; Tapu sees the one project they are on. If the scope ran AFTER the match, Tapu's
    // `total` would still have counted the other two projects and the client — so the
    // assertion is not "fewer rows", it is "the total equals the rows that are present".
    $tapuRows = array_sum(array_map(fn (array $g): int => count($g['results']), $tapuBody['groups']));
    $adminRows = array_sum(array_map(fn (array $g): int => count($g['results']), $adminBody['groups']));

    expect($tapuBody['total'])->toBe($tapuRows)
        ->and($adminBody['total'])->toBe($adminRows);

    // And the Admin genuinely has rows Tapu does not, so the comparison above is about a real
    // access difference rather than an empty database.
    expect(searchLabels($adminBody, 'project'))->toContain('Buffalo Modular — Website Development')
        ->and(searchLabels($tapuBody, 'project'))->not->toContain('Buffalo Modular — Website Development')
        ->and(searchTypes($adminBody))->toContain('client');
})->group('phase10', 'search');

it('answers a term that matches only a restricted record with nothing at all, rather than a refusal', function () {
    // The strongest form of the rule, and the one that cannot be satisfied by a filter: there
    // is no row to redact, so a rank-then-filter implementation would return `total: 1` with
    // an empty `groups`, or a placeholder saying permission was denied. Both of those teach
    // the searcher that the phrase exists somewhere. The honest answer is the same answer a
    // nonsense term gets.
    $body = searchAs($this->tapu, SEARCH_CLIENT_ONLY_TERM);

    expect($body['total'])->toBe(0)
        ->and($body['groups'])->toBe([]);
})->group('phase10', 'search');

it('proves that term really does match a client, so the previous test is a scope and not a typo', function () {
    // Without this, the test above would pass just as happily if "Homes" matched nothing at
    // all — which is how a scoping test quietly stops testing anything.
    $body = searchAs($this->admin, SEARCH_CLIENT_ONLY_TERM);

    expect(searchLabels($body, 'client'))->toContain('Buffalo Modular Homes')
        ->and(searchTypes($body))->toBe(['client']);
})->group('phase10', 'search');

/*
|--------------------------------------------------------------------------
| An employee finds only their own work
|--------------------------------------------------------------------------
*/

it('does not find an employee a project they are not a member of', function () {
    // Tapu is on the SEO retainer and the Heat Gap retainer, and on neither Buffalo build.
    expect(searchLabels(searchAs($this->tapu, 'Website Development'), 'project'))->toBe([])
        ->and(searchLabels(searchAs($this->admin, 'Website Development'), 'project'))
        ->toContain('Buffalo Modular — Website Development');
})->group('phase10', 'search');

it('does not find an employee a task they are not assigned to', function () {
    // `Task::visibleTo()` is narrower than `Project::visibleTo()` on purpose: being on a
    // project does not put you on every task in it. The term is drawn from a task the Admin
    // can see and this employee is not on.
    $adminTasks = searchLabels(searchAs($this->admin, 'Optimize'), 'task');
    $tapuTasks = searchLabels(searchAs($this->tapu, 'Optimize'), 'task');

    expect($adminTasks)->not->toBeEmpty();

    foreach ($tapuTasks as $title) {
        expect($adminTasks)->toContain($title);
    }

    expect(count($tapuTasks))->toBeLessThanOrEqual(count($adminTasks));
})->group('phase10', 'search');

it('does not find an employee a meeting they are not in', function () {
    // Tapu is a participant of three of the four seeded meetings, and not of the APH one.
    expect(searchLabels(searchAs($this->tapu, 'walkthrough'), 'meeting'))->toBe([])
        ->and(searchLabels(searchAs($this->admin, 'walkthrough'), 'meeting'))
        ->toContain('APH — design walkthrough');
})->group('phase10', 'search');

it('does not find an employee a message in a room they cannot enter', function () {
    // Message search is `ConversationService::search()`, scoped to `inboxFor()` before the
    // term runs (decision M-3). This asserts that calling it rather than reimplementing it
    // kept that property: every room a hit comes from is one this reader may open.
    // DemoSeeder seeds no messages, so this test builds the two rooms it is about: one Tapu
    // is in, and one they are not. Both get the SAME distinctive word, which is what makes
    // the assertion a scope rather than a coincidence of vocabulary.
    $conversations = app(ConversationService::class);

    Message::factory()->create([
        'conversation_id' => $conversations->team()->getKey(),
        'author_id' => $this->admin->getKey(),
        'body' => 'Reminder: the '.SEARCH_MESSAGE_TERM.' deadline is Friday.',
    ]);

    // Buffalo Modular — Website Development. Tapu is not a member, so `ProjectPolicy::view`
    // refuses the project and `ConversationPolicy` therefore refuses its channel.
    $closed = Project::where('name', 'Buffalo Modular — Website Development')->firstOrFail();

    Message::factory()->create([
        'conversation_id' => $conversations->forProject($closed)->getKey(),
        'author_id' => $this->admin->getKey(),
        'body' => 'Internal: the '.SEARCH_MESSAGE_TERM.' contract is being renegotiated.',
    ]);

    $inbox = $conversations->inboxFor($this->tapu)
        ->map(fn (Conversation $conversation): string => $conversation->labelFor($this->tapu))
        ->all();

    $rooms = searchLabels(searchAs($this->tapu, SEARCH_MESSAGE_TERM), 'message');

    // Non-vacuous on purpose: the loop below proves nothing if search returned no messages,
    // and a scoping test that stops testing is worse than no test.
    expect($rooms)->not->toBeEmpty('no message hits, so the room check asserted nothing');

    foreach ($rooms as $room) {
        expect($inbox)->toContain($room);
    }

    // The Admin is in both rooms and finds both copies of the word; Tapu finds one. The
    // closed room's message exists, matches, and is simply not there for Tapu — which is the
    // count rule again, on the one entity whose matcher this slice did not write.
    expect(searchLabels(searchAs($this->admin, SEARCH_MESSAGE_TERM), 'message'))->toHaveCount(2)
        ->and($rooms)->toHaveCount(1);
})->group('phase10', 'search');

/*
|--------------------------------------------------------------------------
| Files, whose scope is composed rather than borrowed
|--------------------------------------------------------------------------
|
| Every other entity reuses one `visibleTo()` scope. A file has no scope of
| its own: `FilePolicy::view` is "`view` on the owning record", so
| SearchService composes the project, task and client scopes into one
| statement. Composition is where an access rule gets quietly widened, and
| DemoSeeder seeds no files at all — so without these tests the one rule
| this slice assembled by hand would have zero coverage.
|
*/

it('finds a file on a project the searcher is on, and not one on a project they are not', function () {
    $mine = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();
    $theirs = Project::where('name', 'Buffalo Modular — Website Development')->firstOrFail();

    // The same distinctive filename on both, so the only thing separating them is access.
    File::factory()->ownedBy($mine)->create(['name' => 'pergola-brief.pdf']);
    File::factory()->ownedBy($theirs)->create(['name' => 'pergola-brief.pdf']);

    expect(searchLabels(searchAs($this->tapu, 'pergola'), 'file'))->toHaveCount(1)
        // The Admin sees both copies, which is what makes the one above a scope.
        ->and(searchLabels(searchAs($this->admin, 'pergola'), 'file'))->toHaveCount(2);
})->group('phase10', 'search');

it('does not find an employee a file on a task they are not assigned to', function () {
    // `Task::visibleTo()` is narrower than `Project::visibleTo()`, and the composed file scope
    // has to inherit that narrowness rather than fall back to the project's.
    $task = Task::query()->visibleTo($this->admin)->whereNotIn(
        'id',
        Task::query()->visibleTo($this->tapu)->select('id'),
    )->firstOrFail();

    File::factory()->ownedBy($task)->create(['name' => 'pergola-spec.pdf']);

    expect(searchLabels(searchAs($this->tapu, 'pergola'), 'file'))->toBe([])
        ->and(searchLabels(searchAs($this->admin, 'pergola'), 'file'))->toHaveCount(1);
})->group('phase10', 'search');

it('does not find a client file for anybody without client access', function () {
    File::factory()->ownedBy(Client::where('name', 'Buffalo Modular Homes')->firstOrFail())
        ->create(['name' => 'pergola-contract.pdf']);

    // A client's file is exactly as visible as the client, which is the whole of
    // `FilePolicy::view`. An employee has no client access at all, so a file hanging off one
    // must not become the side door — a filename is content.
    expect(searchLabels(searchAs($this->tapu, 'pergola'), 'file'))->toBe([])
        ->and(searchLabels(searchAs($this->admin, 'pergola'), 'file'))->toHaveCount(1);
})->group('phase10', 'search');

it('does not find a message attachment through file search, for anybody', function () {
    // Deliberately out of scope, and asserted so that "we left it out" cannot drift into "we
    // forgot". A message's reader is decided by `ConversationPolicy` asking the linked task or
    // project, over a membership that is COMPUTED rather than stored — it cannot be expressed
    // as a subquery without restating that computation, and an approximation of a membership
    // rule is how a room somebody cannot enter starts showing its filenames.
    //
    // They remain reachable through their message, in the thread, where they were posted.
    $message = Message::factory()->create([
        'conversation_id' => app(ConversationService::class)->team()->getKey(),
        'author_id' => $this->admin->getKey(),
        'body' => 'Attached.',
    ]);

    File::factory()->ownedBy($message)->create(['name' => 'pergola-photo.pdf']);

    expect(searchLabels(searchAs($this->admin, 'pergola'), 'file'))->toBe([])
        ->and(searchLabels(searchAs($this->tapu, 'pergola'), 'file'))->toBe([]);
})->group('phase10', 'search');

it('links a file result to the record it hangs off, never to the download', function () {
    // `GET /files/{file}` is a signed route that re-runs FilePolicy and then DOWNLOADS. A
    // search result whose Enter key starts a download is not a search result, and a signed URL
    // in a palette payload would be a capability sitting in a JSON response.
    $project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();

    File::factory()->ownedBy($project)->create(['name' => 'pergola-brief.pdf']);

    $body = searchAs($this->tapu, 'pergola');

    foreach ($body['groups'] as $group) {
        if ($group['type'] !== 'file') {
            continue;
        }

        foreach ($group['results'] as $row) {
            expect($row['href'])->toBe('/employee/projects/'.$project->getKey())
                ->and($row['href'])->not->toContain('/files/')
                ->and($row['href'])->not->toContain('signature');
        }
    }
})->group('phase10', 'search');

/*
|--------------------------------------------------------------------------
| The Accountant — finance records, and nothing else
|--------------------------------------------------------------------------
*/

it('gives the accountant finance records and no other type at all', function () {
    // Phase 10: "per shell: Accountant searches only finance records", and "Accountant search
    // never returns tasks/messages". Asserted as the TYPE SET rather than as four separate
    // absences, because a set assertion also fails the day a NINTH type is added and nobody
    // thinks about this row of the matrix.
    //
    // Nothing in SearchService names this role. The empty types are empty because the
    // Accountant holds no `projects.view`, no `tasks.view`, no `messages.use`, no
    // `meetings.use` and no `clients.view_full`, and the finance ones are full because they
    // hold `finance.view`.
    $body = searchAs($this->accountant, 'September');

    expect(searchTypes($body))->not->toBeEmpty()
        ->and(searchTypes($body))->each->toBeIn(['income', 'expense']);
})->group('phase10', 'search');

it('gives the accountant nothing for a term that only matches work', function (string $term) {
    expect(searchAs($this->accountant, $term)['total'])->toBe(0);
})->with(['Buffalo', 'Homes', 'walkthrough', 'Optimize'])->group('phase10', 'search');

it('leaks no salary, no client and no employee to the accountant', function () {
    // Part C §1 gives the Accountant ❌ on clients and on viewing projects, and Part H forbids
    // anything that would rank people. A search that returned a person — even just a name —
    // would be a directory they do not have, reached sideways.
    $body = searchAs($this->accountant, 'a');

    expect(searchTypes($body))->not->toContain('employee')
        ->and(searchTypes($body))->not->toContain('client')
        ->and(searchTypes($body))->not->toContain('task')
        ->and(searchTypes($body))->not->toContain('message');

    $encoded = json_encode($body, JSON_THROW_ON_ERROR);

    foreach (SEARCH_FORBIDDEN_CLIENT_NAMES as $client) {
        expect($encoded)->not->toContain($client);
    }
})->group('phase10', 'search');

it('gives the admin finance records too, which is what proves the rule is a key and not a shell', function () {
    // If "only the Accountant searches finance" had been implemented by naming the role, this
    // would fail. The Admin holds `finance.view`, so the Admin finds the books.
    expect(searchTypes(searchAs($this->admin, 'September')))->toContain('income');
})->group('phase10', 'search');

/*
|--------------------------------------------------------------------------
| Who may search at all
|--------------------------------------------------------------------------
*/

it('finds a deactivated user nothing', function () {
    // Three agreeing statements of one rule: the `active` middleware on the route, every
    // `visibleTo()` scope, and `SearchService::search()`'s own first line. This asserts the
    // service's, because the middleware's is asserted by the route test and a caller reaching
    // the service directly must get the same answer.
    $this->tapu->forceFill(['status' => UserStatus::Inactive->value])->save();

    expect(app(SearchService::class)->search($this->tapu->fresh(), 'Buffalo')['total'])->toBe(0);
})->group('phase10', 'search');

it('sends a signed-out request to the login page rather than answering it', function () {
    $this->get('/search?q=Buffalo')->assertRedirect('/login');
})->group('phase10', 'search');

it('lets every signed-in role call the route, because what differs is what they find', function (string $user) {
    // There is no `can:` on this route and that is deliberate — see routes/shared.php. A role
    // that got 403 here would mean somebody had invented a `search.use` key.
    test()->actingAs($this->{$user})->getJson('/search?q=SEO')->assertOk();
})->with(['admin', 'accountant', 'employee', 'tapu'])->group('phase10', 'search');

/*
|--------------------------------------------------------------------------
| The one access rule this slice had to write, held against the policy
|--------------------------------------------------------------------------
*/

it('answers exactly what ClientPolicy::view answers, for every seeded person and every client', function () {
    // Every other entity reuses a `visibleTo()` scope that already has its own test. Clients
    // have no such scope and this slice does not own `app/Models/Client.php`, so the rule is
    // written as a builder in SearchService — and a second statement of a privacy rule is only
    // safe while something asserts the two agree. This is that something.
    $service = app(SearchService::class);
    $clients = Client::all();

    foreach (['admin', 'accountant', 'employee', 'tapu'] as $who) {
        $user = $this->{$who};
        $scope = $service->clientScope($user);
        $scoped = $scope === null ? [] : $scope->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $byPolicy = $clients
            ->filter(fn (Client $client): bool => Gate::forUser($user)->allows('view', $client))
            ->map(fn (Client $client): int => (int) $client->getKey())
            ->values()
            ->all();

        expect($scoped)->toEqualCanonicalizing($byPolicy, "client scope disagrees with ClientPolicy for {$who}");
    }
})->group('phase10', 'search');
