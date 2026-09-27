<?php

use App\Services\Import\AsanaReader;
use App\Services\Import\ClickUpReader;
use App\Services\Import\CsvFile;
use App\Services\Import\ImportFileException;
use App\Support\TaskStatus;

/*
|--------------------------------------------------------------------------
| The readers, on their own
|--------------------------------------------------------------------------
|
| Everything below is a shape a REAL export has been seen to arrive in and
| that a hand-written fixture would never produce: a byte-order mark from
| Excel, a duration in milliseconds, a date as an epoch, an assignee list as
| JSON, a header in the wrong case.
|
| These are the assumptions most likely to be wrong about the client's file
| (GATE A). Each one is pinned here so that when their export lands, the
| thing that breaks is a test and not the cutover.
|
*/

function importFixture(string $name, string $contents): string
{
    $path = sys_get_temp_dir().'/hq-import-'.$name.'-'.getmypid().'.csv';
    file_put_contents($path, $contents);

    return $path;
}

it('reads a ClickUp export whose headers carry a byte-order mark and the wrong case', function () {
    $path = importFixture('bom', "\xEF\xBB\xBF"."task id,TASK NAME,status,parent_id\n"
        ."1,Client One,IN PROGRESS,\n"
        ."2,Some work,COMPLETED,1\n");

    $draft = (new ClickUpReader)->read(new CsvFile($path));

    expect($draft->projects)->toHaveCount(1)
        ->and($draft->projects[0]->name)->toBe('Client One')
        ->and($draft->projects[0]->tasks[0]->status)->toBe(TaskStatus::Completed);

    unlink($path);
})->group('phase12');

it('reads ClickUp durations as milliseconds and as h:mm:ss, never as seconds', function () {
    $path = importFixture('time', "Task ID,Task Name,Status,Parent ID,Time Logged\n"
        ."1,Client,IN PROGRESS,,\n"
        ."2,By milliseconds,IN PROGRESS,1,5400000\n"
        ."3,By clock,IN PROGRESS,1,1:30:00\n"
        ."4,By words,IN PROGRESS,1,1h 30m\n"
        ."5,By nonsense,IN PROGRESS,1,about an hour\n");

    $tasks = (new ClickUpReader)->read(new CsvFile($path))->projects[0]->tasks;

    // 5400000 ms is an hour and a half. Read as seconds it would be sixty-two days, and the
    // import would quietly put two months of work on somebody's timesheet.
    expect(array_map(fn ($task): int => $task->trackedSeconds, $tasks))->toBe([5400, 5400, 5400, 0]);

    unlink($path);
})->group('phase12');

it('reads a ClickUp date as an epoch in milliseconds or as text', function () {
    $path = importFixture('dates', "Task ID,Task Name,Status,Parent ID,Due Date\n"
        ."1,Client,IN PROGRESS,,\n"
        ."2,Epoch,IN PROGRESS,1,1787216400000\n"
        ."3,Text,IN PROGRESS,1,\"Thu, August 20, 2026\"\n"
        ."4,Rubbish,IN PROGRESS,1,soon\n");

    $tasks = (new ClickUpReader)->read(new CsvFile($path))->projects[0]->tasks;

    expect($tasks[0]->dueDate?->toDateString())->toBe('2026-08-20')
        ->and($tasks[1]->dueDate?->toDateString())->toBe('2026-08-20')
        ->and($tasks[2]->dueDate)->toBeNull();

    unlink($path);
})->group('phase12');

it('reads an assignee list as JSON, as objects and as plain text', function () {
    $path = importFixture('assignees', "Task ID,Task Name,Status,Parent ID,Assignees\n"
        ."1,Client,IN PROGRESS,,\n"
        .'2,Json,IN PROGRESS,1,"[""Tapu"",""Yaseen""]"'."\n"
        .'3,Objects,IN PROGRESS,1,"[{""username"":""Tapu""}]"'."\n"
        .'4,Plain,IN PROGRESS,1,Tapu'."\n"
        ."5,Nobody,IN PROGRESS,1,\n");

    $tasks = (new ClickUpReader)->read(new CsvFile($path))->projects[0]->tasks;

    expect(array_map(fn ($task): ?string => $task->assignee, $tasks))
        ->toBe(['Tapu', 'Tapu', 'Tapu', null]);

    unlink($path);
})->group('phase12');

it('refuses a file with no header row and one that is not the named source', function () {
    $empty = importFixture('empty', '');

    expect(fn () => (new ClickUpReader)->read(new CsvFile($empty)))
        ->toThrow(ImportFileException::class, 'has no header row');

    $wrong = importFixture('wrong', "Name,Section/Column\nA task,To Do\n");

    expect(fn () => (new ClickUpReader)->read(new CsvFile($wrong)))
        ->toThrow(ImportFileException::class, 'does not look like a ClickUp export');

    unlink($empty);
    unlink($wrong);
})->group('phase12');

it('keeps the two readers\' status maps closed — nothing falls through to a default', function () {
    // The rule the brief states: an unmapped status is a reported skip, not a guess. It holds
    // only while neither map has an `?? Backlog` in it, which is exactly what this asserts by
    // reading a status neither of them has.
    $clickUp = importFixture('unknown-cu', "Task ID,Task Name,Status,Parent ID\n"
        ."1,Client,IN PROGRESS,\n2,Odd,PENDING LEGAL,1\n");

    $draft = (new ClickUpReader)->read(new CsvFile($clickUp));

    expect($draft->projects[0]->tasks)->toBeEmpty()
        ->and($draft->issues)->toHaveCount(1)
        ->and($draft->issues[0]->why)->toContain('"PENDING LEGAL" is not mapped');

    $asana = importFixture('unknown-as', "Name,Projects,Section/Column,Completed At\n"
        ."Odd,Website,Pending Legal,\n");

    $draft = (new AsanaReader)->read(new CsvFile($asana));

    expect($draft->projects)->toBeEmpty()
        ->and($draft->issues[0]->why)->toContain('section "Pending Legal" is not mapped');

    unlink($clickUp);
    unlink($asana);
})->group('phase12');
