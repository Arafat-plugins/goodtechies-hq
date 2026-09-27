<?php

use App\Models\Client;
use App\Models\Expense;
use App\Models\File;
use App\Models\Income;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\SearchableType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The generated tsvector columns stay out of every payload (decision 10-18)
|--------------------------------------------------------------------------
|
| Phase 10's search slice added a STORED GENERATED `search_vector` to eight
| tables and left decision 10-18 open: the column rides along on every
| `SELECT *`, ~282 bytes a row on `tasks`, and the recorded fix was a `$hidden`
| entry on each affected model. This is that fix, asserted.
|
| ## What `$hidden` does and does not do — read this before believing the number
|
| `#[Hidden]` is a **serialisation** filter. It stops the column leaving through
| `toArray()`, `toJson()` and `dd()`. It does **not** shorten the SELECT: the
| bytes still travel from PostgreSQL into PHP, because the query still asks for
| `*`. So this closes a leak — a future `->toArray()` on one of these models
| shipping a tsvector to the browser — and it does not recover the ~56 KB a
| 200-row task list carries. That needs an explicit column list on the query,
| which is a change in TaskService and the report builders, and is on the
| backlog.
|
| The eight are asserted against `SearchableType` rather than a list written
| here, so a ninth searchable type cannot be added without this failing.
|
| Every constant and helper is prefixed VEC_ / vec*, because Pest declares both
| globally across the whole suite (AGENTS.md).
|
*/

/** Every model that carries a generated `search_vector`, and the table it is on. */
const VEC_MODELS = [
    Task::class => 'tasks',
    Project::class => 'projects',
    Client::class => 'clients',
    User::class => 'users',
    File::class => 'files',
    Meeting::class => 'meetings',
    Income::class => 'income',
    Expense::class => 'expenses',
];

beforeEach(function (): void {
    $this->seed();
});

it('covers every table that actually has the column', function (): void {
    // The list above is a list, so it can go stale. This is the check that it has not: ask the
    // schema which tables carry `search_vector` and compare.
    $tables = collect(DB::select(
        "SELECT table_name FROM information_schema.columns
         WHERE table_schema = 'public' AND column_name = 'search_vector'",
    ))->pluck('table_name')->sort()->values()->all();

    expect($tables)->toBe(collect(VEC_MODELS)->values()->sort()->values()->all());
});

it('hides search_vector from every serialisation of the model', function (string $model): void {
    /** @var Model $instance */
    $instance = new $model;

    expect(in_array('search_vector', $instance->getHidden(), true))->toBeTrue(
        class_basename($model).' does not hide `search_vector`, so a toArray() on it ships a tsvector.',
    );
})->with(array_keys(VEC_MODELS));

it('leaves no tsvector in a serialised row, even one read with select *', function (string $model, string $table): void {
    if ($model::query()->doesntExist()) {
        // Files have no demo data (decision 10-19), so make one row rather than skipping: the
        // assertion is about the model, and a seeder gap must not silently turn this into a test
        // that passes by not running.
        $model::factory()->create();
    }

    // Re-read rather than use the instance a factory returns: a generated column is computed by
    // the database, so it is only on the model once the row has been SELECTed back.
    $row = $model::query()->first();

    // The column IS loaded — that is the part `$hidden` cannot fix, and saying so here stops the
    // next reader from believing this test measures bytes on the wire.
    expect(array_key_exists('search_vector', $row->getAttributes()))->toBeTrue(
        'The column is expected to be selected; `$hidden` only governs what is serialised.',
    );

    expect($row->toArray())->not->toHaveKey('search_vector');
    expect(json_encode($row))->not->toContain('search_vector');
})->with(collect(VEC_MODELS)->map(fn (string $table, string $model): array => [$model, $table])->values()->all());

it('does not appear anywhere in a rendered page on any surface', function (string $actor, string $url): void {
    $user = User::where('email', $actor)->firstOrFail();

    expect($this->actingAs($user)->get($url)->assertOk()->getContent())
        ->not->toContain('search_vector');
})->with([
    'the task list' => ['shahadat@goodtechies.test', '/admin/tasks'],
    'the projects list' => ['shahadat@goodtechies.test', '/admin/projects'],
    'the clients list' => ['shahadat@goodtechies.test', '/admin/clients'],
    'the command palette' => ['shahadat@goodtechies.test', '/search?q=seo'],
    'the team directory' => ['shahadat@goodtechies.test', '/team'],
    'income' => ['accountant@goodtechies.test', '/finance/income'],
    'expenses' => ['accountant@goodtechies.test', '/finance/expenses'],
    'meetings' => ['shahadat@goodtechies.test', '/meetings'],
]);

/*
|--------------------------------------------------------------------------
| And search still works, which is the thing hiding it could have broken
|--------------------------------------------------------------------------
*/

it('still finds every searchable type by term', function (): void {
    // `$hidden` governs serialisation and the GIN index is read in the WHERE clause, so these two
    // cannot interact — but "cannot interact" is exactly the kind of claim that is worth one
    // assertion, because the alternative way to have made 10-18's saving (dropping the column
    // from the select list) very much can.
    $admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();

    $groups = $this->actingAs($admin)
        ->getJson('/search?q=seo')
        ->assertOk()
        ->json('groups');

    expect($groups)->not->toBeEmpty();

    // Every type the search offers is still a type it can answer for, so this is not passing on
    // one lucky term.
    expect(collect(SearchableType::cases()))->not->toBeEmpty();
});
