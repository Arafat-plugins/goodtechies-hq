<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Searching your own messages, and nobody else's
|--------------------------------------------------------------------------
|
| `GET /messages/search?q=…` searches inside the conversations the requester
| may open and nowhere else — the candidate ids come from the inbox, which has
| already been through ConversationPolicy::view row by row, and the ILIKE runs
| only inside them.
|
| The load-bearing test in this file is the second one. Scoping AFTER the match
| would have made the NUMBER of hits a fact about rooms the reader cannot enter:
| a term that appears once in a project channel they are not on would show up in
| the shape of the answer even with no row of it ever sent. Part C says a record
| they may not see is absent, and a count of absent records is not absent.
|
| Constants here are global in Pest, so they are prefixed MESSAGE_SEARCH_.
|
*/

const MESSAGE_SEARCH_URL = '/messages/search';

/** A word that exists nowhere in the seeded data, so a hit can only be the one we planted. */
const MESSAGE_SEARCH_TERM = 'quokka';

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->conversations = app(ConversationService::class);
    $this->messages = app(MessageService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->team = $this->conversations->team();

    $this->tapusTask = Task::query()
        ->forEmployee($this->tapu->employee)
        ->notArchived()
        ->orderBy('id')
        ->firstOrFail();

    $this->tapusProject = Project::findOrFail($this->tapusTask->project_id);
    $this->projectChannel = $this->conversations->forProject($this->tapusProject);
});

/*
|--------------------------------------------------------------------------
| It finds real messages
|--------------------------------------------------------------------------
*/

it('finds a message by a word in it, and names the conversation it is in', function () {
    $this->messages->post($this->tapu, $this->team, 'The '.MESSAGE_SEARCH_TERM.' report is filed.');

    $payload = $this->actingAs($this->tapu)
        ->getJson(MESSAGE_SEARCH_URL.'?q='.MESSAGE_SEARCH_TERM)
        ->assertOk()
        ->json();

    expect($payload['query'])->toBe(MESSAGE_SEARCH_TERM)
        ->and($payload['results'])->toHaveCount(1)
        ->and($payload['has_more'])->toBeFalse();

    $row = $payload['results'][0];

    expect(array_keys($row))->toEqualCanonicalizing([
        'message_id', 'conversation_id', 'conversation_label', 'conversation_type',
        'author', 'excerpt', 'created_at',
    ])
        ->and($row['conversation_id'])->toBe($this->team->id)
        // Named by `labelFor()`, per reader — the same label the inbox row carries.
        ->and($row['conversation_label'])->toBe($this->team->labelFor($this->tapu))
        ->and($row['conversation_type'])->toBe('team')
        ->and($row['author'])->toBe($this->tapu->name)
        ->and($row['excerpt'])->toContain(MESSAGE_SEARCH_TERM);
})->group('phase6');

it('matches whatever case the word was typed in', function () {
    $this->messages->post($this->admin, $this->team, 'A '.strtoupper(MESSAGE_SEARCH_TERM).' sighting.');

    $this->actingAs($this->tapu)
        ->getJson(MESSAGE_SEARCH_URL.'?q='.MESSAGE_SEARCH_TERM)
        ->assertOk()
        ->assertJsonCount(1, 'results');
})->group('phase6');

it('cuts the excerpt on the server, centred on the match', function () {
    // A hit four thousand characters in would be reported as an excerpt not containing the word
    // the reader typed if the cut were taken from the front — which reads as a bug.
    $body = str_repeat('padding words here. ', 60).MESSAGE_SEARCH_TERM.' '.str_repeat('trailing words. ', 60);

    $this->messages->post($this->admin, $this->team, $body);

    $excerpt = $this->actingAs($this->tapu)
        ->getJson(MESSAGE_SEARCH_URL.'?q='.MESSAGE_SEARCH_TERM)
        ->assertOk()
        ->json('results.0.excerpt');

    expect($excerpt)->toContain(MESSAGE_SEARCH_TERM)
        // Roughly 160 characters plus the two ellipses that say it was cut on both sides.
        ->and(mb_strlen($excerpt))->toBeLessThanOrEqual(162)
        ->and($excerpt)->toStartWith('…')
        ->and($excerpt)->toEndWith('…')
        // Plain text. Highlighting is the screen's job; a server emitting markup into JSON is
        // a server asking a template to trust it.
        ->and($excerpt)->not->toContain('<');
})->group('phase6');

it('answers newest first and says when there is more', function () {
    foreach (range(1, ConversationService::SEARCH_LIMIT + 3) as $i) {
        $this->messages->post($this->admin, $this->team, 'A '.MESSAGE_SEARCH_TERM.' number '.$i);
    }

    $payload = $this->actingAs($this->tapu)
        ->getJson(MESSAGE_SEARCH_URL.'?q='.MESSAGE_SEARCH_TERM)
        ->assertOk()
        ->json();

    $ids = array_column($payload['results'], 'message_id');

    expect($payload['results'])->toHaveCount(ConversationService::SEARCH_LIMIT)
        ->and($payload['has_more'])->toBeTrue()
        ->and($ids)->toBe(array_reverse(collect($ids)->sort()->values()->all()));
})->group('phase6');

/*
|--------------------------------------------------------------------------
| The scope, which is the point of the endpoint
|--------------------------------------------------------------------------
*/

it('never finds a word that exists only in a project channel the searcher is not on', function () {
    // Yaseen is on neither of Tapu's SEO projects. This is the load-bearing assertion of the
    // whole endpoint: he gets no row AND no count, because the candidate ids were built from
    // his inbox before the term was ever matched.
    $this->messages->post($this->tapu, $this->projectChannel, 'The '.MESSAGE_SEARCH_TERM.' audit is done.');

    $mine = $this->actingAs($this->tapu)
        ->getJson(MESSAGE_SEARCH_URL.'?q='.MESSAGE_SEARCH_TERM)
        ->assertOk()
        ->json();

    // The term is real and findable — by the person who may read the room.
    expect($mine['results'])->toHaveCount(1)
        ->and($mine['results'][0]['conversation_id'])->toBe($this->projectChannel->id);

    $theirs = $this->actingAs($this->yaseen)
        ->getJson(MESSAGE_SEARCH_URL.'?q='.MESSAGE_SEARCH_TERM)
        ->assertOk()
        ->json();

    expect($theirs['results'])->toBe([])
        ->and($theirs['has_more'])->toBeFalse();

    $this->actingAs($this->yaseen)
        ->getJson(MESSAGE_SEARCH_URL.'?q='.MESSAGE_SEARCH_TERM)
        ->assertDontSee('audit is done');
})->group('phase6');

it('never finds a DM between two other people', function () {
    $dm = $this->conversations->dmBetween($this->tapu, $this->admin);

    $this->messages->post($this->admin, $dm, 'Between us: the '.MESSAGE_SEARCH_TERM.' invoice.');

    // A third person, and an Admin at that — a DM's audience is the two columns on its row and
    // nothing in it consults a role.
    expect($this->actingAs($this->yaseen)
        ->getJson(MESSAGE_SEARCH_URL.'?q='.MESSAGE_SEARCH_TERM)
        ->assertOk()
        ->json('results'))->toBe([]);

    expect($this->actingAs($this->tapu)
        ->getJson(MESSAGE_SEARCH_URL.'?q='.MESSAGE_SEARCH_TERM)
        ->assertOk()
        ->json('results'))->toHaveCount(1);
})->group('phase6');

it('loses the channel the moment somebody leaves the project, with no sync having run', function () {
    $this->messages->post($this->tapu, $this->projectChannel, 'The '.MESSAGE_SEARCH_TERM.' brief.');

    $this->actingAs($this->tapu)
        ->getJson(MESSAGE_SEARCH_URL.'?q='.MESSAGE_SEARCH_TERM)
        ->assertOk()
        ->assertJsonCount(1, 'results');

    $this->tapusProject->members()->detach($this->tapu->employee->getKey());

    expect($this->actingAs($this->tapu->fresh())
        ->getJson(MESSAGE_SEARCH_URL.'?q='.MESSAGE_SEARCH_TERM)
        ->assertOk()
        ->json('results'))->toBe([]);
})->group('phase6');

it('does not search task discussions, which are read inside their task', function () {
    $discussion = $this->conversations->forTask($this->tapusTask);

    $this->messages->post($this->tapu, $discussion, 'A '.MESSAGE_SEARCH_TERM.' note on this task.');

    expect($this->actingAs($this->tapu)
        ->getJson(MESSAGE_SEARCH_URL.'?q='.MESSAGE_SEARCH_TERM)
        ->assertOk()
        ->json('results'))->toBe([]);
})->group('phase6');

/*
|--------------------------------------------------------------------------
| The term itself
|--------------------------------------------------------------------------
*/

it('answers 200 and nothing at all for a term of one character', function () {
    // A half-typed search box is not a malformed request. A 422 here would make a screen that
    // searches as you type flash an error on the first keystroke, every time.
    $this->messages->post($this->admin, $this->team, 'A '.MESSAGE_SEARCH_TERM.' somewhere.');

    $payload = $this->actingAs($this->tapu)
        ->getJson(MESSAGE_SEARCH_URL.'?q=q')
        ->assertOk()
        ->json();

    expect($payload['query'])->toBe('q')
        ->and($payload['results'])->toBe([])
        ->and($payload['has_more'])->toBeFalse();
})->group('phase6');

it('answers 200 and nothing at all when q is missing or only whitespace', function () {
    $this->actingAs($this->tapu)
        ->getJson(MESSAGE_SEARCH_URL)
        ->assertOk()
        ->assertJsonPath('query', '')
        ->assertJsonPath('results', [])
        ->assertJsonPath('has_more', false);

    $this->actingAs($this->tapu)
        ->getJson(MESSAGE_SEARCH_URL.'?q='.urlencode('   '))
        ->assertOk()
        ->assertJsonPath('results', []);
})->group('phase6');

it('refuses a term over a hundred characters with 422', function () {
    // That is not somebody typing.
    $this->actingAs($this->tapu)
        ->getJson(MESSAGE_SEARCH_URL.'?q='.str_repeat('a', 101))
        ->assertStatus(422)
        ->assertJsonValidationErrors('q');

    $this->actingAs($this->tapu)
        ->getJson(MESSAGE_SEARCH_URL.'?q='.str_repeat('a', 100))
        ->assertOk();
})->group('phase6');

it('treats a wildcard as a character and not as a pattern', function () {
    $this->messages->post($this->admin, $this->team, 'Nothing special here.');

    // `%` unescaped would match every message in the inbox, which is a search that quietly
    // ignores a key on the keyboard.
    expect($this->actingAs($this->tapu)
        ->getJson(MESSAGE_SEARCH_URL.'?q='.urlencode('%%'))
        ->assertOk()
        ->json('results'))->toBe([]);
})->group('phase6');

/*
|--------------------------------------------------------------------------
| The door
|--------------------------------------------------------------------------
*/

it('refuses the accountant, because the route group is gated on messages.use', function () {
    // 403 and not 404: the route exists and their answer to it is no. Nothing about it is
    // hidden — it is a capability, and no code anywhere names their role.
    $this->actingAs($this->accountant)
        ->getJson(MESSAGE_SEARCH_URL.'?q='.MESSAGE_SEARCH_TERM)
        ->assertForbidden();
})->group('phase6');

it('sends a guest to the login page', function () {
    $this->get(MESSAGE_SEARCH_URL.'?q='.MESSAGE_SEARCH_TERM)
        ->assertRedirect('/login');
})->group('phase6');
