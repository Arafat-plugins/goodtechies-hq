<?php

use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Support\TagColour;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The two rules `tags` used to state as comments
|--------------------------------------------------------------------------
|
| Slice 1 created the table for a feature that could only read it, and wrote
| two of its rules down instead of enforcing them: a colour is a status token
| name, and a global tag's name is unique. Slice 4 adds the endpoints that let
| a human write to the table, so both are constraints now.
|
| Every assertion here goes through DB::table() rather than the model, on
| purpose. The point is not that the service refuses a bad row — it is that
| PostgreSQL does, so a console command, a future job or somebody's psql
| session is refused identically.
|
*/

beforeEach(fn () => $this->seed());

it('refuses a colour that is not a status token', function (string $colour) {
    DB::table('tags')->insert([
        'project_id' => null,
        'name' => 'Probe '.uniqid(),
        'colour' => $colour,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->with([
    // The exact thing DESIGN.md §5.1 forbids: a value that cannot follow the theme.
    'a hex' => ['#ff0000'],
    'a css colour word' => ['red'],
    'an oklch value' => ['oklch(0.7 0.2 30)'],
    // Close, but not one of the eight.
    'a status that does not exist' => ['blocked'],
    'empty' => [''],
])->throws(QueryException::class)->group('phase2');

it('accepts every colour the enum declares', function () {
    foreach (TagColour::cases() as $colour) {
        $tag = Tag::create([
            'project_id' => null,
            'name' => 'Probe '.$colour->value,
            'colour' => $colour,
        ]);

        expect($tag->fresh()->colour)->toBe($colour);
    }
})->group('phase2');

it('refuses a second global tag with the same name', function () {
    // The composite unique index cannot see this: PostgreSQL treats two NULLs as distinct, so
    // (NULL, 'SEO') and (NULL, 'SEO') are different rows to it. `tags_global_name_unique` is
    // the partial index that closes it.
    Tag::create(['project_id' => null, 'name' => 'Duplicated', 'colour' => TagColour::Done]);

    expect(fn () => DB::table('tags')->insert([
        'project_id' => null,
        'name' => 'Duplicated',
        'colour' => 'review',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->group('phase2');

it('still lets one name exist globally and in a project at the same time', function () {
    // The partial index is scoped to the global rows, so it must not have narrowed what slice
    // 1 allowed: a project may have its own `SEO` label beside the agency-wide one.
    $project = Project::factory()->create();

    Tag::create(['project_id' => null, 'name' => 'Shared name', 'colour' => TagColour::Backlog]);
    $scoped = Tag::create(['project_id' => $project->id, 'name' => 'Shared name', 'colour' => TagColour::Changes]);

    expect($scoped->exists)->toBeTrue()
        ->and(Tag::where('name', 'Shared name')->count())->toBe(2);
})->group('phase2');

it('takes the label off every task when the tag row goes', function () {
    // `task_tags` cascades, which is what makes TagService::delete() a real delete rather than
    // a hidden flag — and what makes the audit row it writes first the only surviving record of
    // which tasks were wearing the label.
    $task = Task::factory()->create();
    $tag = Tag::factory()->create();
    $task->tags()->attach($tag);

    expect(DB::table('task_tags')->where('tag_id', $tag->id)->count())->toBe(1);

    $tag->delete();

    expect(DB::table('task_tags')->where('tag_id', $tag->id)->count())->toBe(0)
        ->and($task->fresh()->exists)->toBeTrue();
})->group('phase2');
