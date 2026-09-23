<?php

use App\Models\Client;
use App\Models\File;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The files table
|--------------------------------------------------------------------------
|
| The two constraints that make the chosen ownership shape real rather than a
| convention: exactly one owner per row, and exactly one live version per
| chain. Both are asserted by trying to break them, because a CHECK nobody has
| ever violated is indistinguishable from a comment.
|
*/

beforeEach(fn () => $this->seed());

it('has the columns the service writes', function () {
    expect(Schema::hasColumns('files', [
        'id',
        'task_id', 'project_id', 'client_id',
        'disk', 'path',
        'name', 'extension', 'mime_type', 'size', 'checksum',
        'uploaded_by',
        'version_of', 'version', 'superseded_at',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
})->group('phase2');

it('insists on exactly one owner', function (array $owners) {
    $row = [
        'task_id' => null,
        'project_id' => null,
        'client_id' => null,
        'disk' => 'local',
        'path' => 'files/probe-'.uniqid().'.pdf',
        'name' => 'probe.pdf',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'size' => 10,
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('files')->insert([...$row, ...$owners]);
})->with([
    // No owner at all: a file that belongs to nothing.
    'none' => [[]],
    // Two owners: a file on a task AND a client, which nothing in the application means.
    'two' => [fn () => [
        'task_id' => Task::query()->value('id'),
        'client_id' => Client::query()->value('id'),
    ]],
])->throws(QueryException::class)->group('phase2');

it('accepts a row with exactly one owner', function () {
    $project = Project::factory()->create();

    expect(File::factory()->forProject($project)->create()->project_id)->toBe($project->id);
})->group('phase2');

it('refuses a second live version of one file', function () {
    $task = Task::factory()->create();
    $root = File::factory()->forTask($task)->create();

    // Another row in the same chain, also current. `files_one_live_version` is a unique index
    // on coalesce(version_of, id) for the rows that are neither superseded nor deleted, so the
    // chain cannot end up with two heads whoever wrote the rows.
    File::factory()->forTask($task)->create([
        'version_of' => $root->id,
        'version' => 2,
        'superseded_at' => null,
    ]);
})->throws(QueryException::class)->group('phase2');

it('lets a superseded version sit beside the live one', function () {
    $task = Task::factory()->create();
    $root = File::factory()->forTask($task)->create();

    $old = File::factory()->forTask($task)->create([
        'version_of' => $root->id,
        'version' => 2,
        'superseded_at' => now(),
    ]);

    expect($old->version_of)->toBe($root->id)
        ->and(File::where('version_of', $root->id)->count())->toBe(1);
})->group('phase2');

it('does not count a deleted row as the live version', function () {
    $task = Task::factory()->create();
    $root = File::factory()->forTask($task)->create();

    $root->delete();

    // With the old head soft-deleted the partial index no longer covers it, so a new current
    // row in the same chain is accepted — which is what promoting a survivor relies on.
    $replacement = File::factory()->forTask($task)->create([
        'version_of' => $root->id,
        'version' => 2,
    ]);

    expect($replacement->isCurrent())->toBeTrue();
})->group('phase2');

it('refuses two rows at the same path', function () {
    $task = Task::factory()->create();
    $first = File::factory()->forTask($task)->create();

    File::factory()->forTask($task)->create(['path' => $first->path]);
})->throws(QueryException::class)->group('phase2');

it('lets hq_app write and delete a file row', function () {
    // The runtime role, not the migrator. New tables pick their grants up from the default
    // privileges in deploy/sql/roles.sql; this is the check that they did.
    $file = File::factory()->forTask(Task::factory()->create())->create();

    $file->update(['name' => 'renamed.pdf']);
    $file->delete();
    $file->forceDelete();

    expect(File::withTrashed()->whereKey($file->id)->exists())->toBeFalse()
        ->and(DB::connection()->selectOne('SELECT current_user AS name')->name)->toBe('hq_app');
})->group('phase2');
