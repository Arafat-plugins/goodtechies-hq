<?php

use App\Models\Employee;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Support\RoleName;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| No productivity score, anywhere
|--------------------------------------------------------------------------
|
| Part H forbids productivity scoring outright, and the Phase 4 plan asks
| for this as a grep on the UI strings "score" and "productivity".
|
| It is two checks, because a score can get in two ways:
|
|   - as WORDS on the screen, so the timer's own source files are read and
|     searched with their comments stripped — a docblock explaining the ban
|     is not a violation of it, and a label would be;
|   - as a NUMBER in the payload, so the rendered props of the Time page
|     and the task detail are searched too. "Efficiency: 82 %" under a
|     different name is the same thing, so the serialiser's key names are
|     in scope as much as its values.
|
| Tracked time is a record, not a judgement. Nothing in this slice ranks or
| rates a person, and the one comparison that exists — "4h 18m / 5h" — is a
| total against a number the SCHEDULE set, which is a fact.
|
*/

/** The words that must not appear, and the ones that would be them under another name. */
const FORBIDDEN_WORDS = ['score', 'productivity', 'productive', 'efficiency', 'rating', 'ranking'];

/** Every file this slice put in front of a person, or that builds what they see. */
function timerSourceFiles(): array
{
    $roots = [
        base_path('resources/js/Components/Timer'),
        base_path('resources/js/Pages/Employee/Time'),
    ];

    $files = [
        base_path('resources/js/lib/timerState.ts'),
        base_path('app/Http/Resources/TimeEntryResource.php'),
        base_path('app/Http/Controllers/Concerns/BuildsTimerState.php'),
        base_path('app/Http/Controllers/Employee/TimeController.php'),
        base_path('app/Http/Controllers/Employee/TimeEntryController.php'),
        base_path('app/Http/Controllers/Employee/TimerController.php'),
        base_path('app/Support/TimerFlag.php'),
        base_path('app/Support/TimeEntryType.php'),
    ];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
    }

    return array_values(array_filter($files, 'is_file'));
}

/**
 * The file with its comments taken out.
 *
 * A docblock that says "there is no score here" is the rule being kept, not broken — so the
 * grep reads what would reach a person: template text, labels and string literals.
 */
function withoutComments(string $source): string
{
    $stripped = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
    $stripped = preg_replace('#<!--.*?-->#s', '', $stripped) ?? $stripped;

    return preg_replace('#(^|\s)//[^\n]*#m', '', $stripped) ?? $stripped;
}

it('has no score and no productivity figure in any timer source file', function (): void {
    $offenders = [];

    foreach (timerSourceFiles() as $path) {
        $text = strtolower(withoutComments((string) file_get_contents($path)));

        foreach (FORBIDDEN_WORDS as $word) {
            if (str_contains($text, $word)) {
                $offenders[] = basename($path).' contains "'.$word.'"';
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('sends no score and no productivity figure to the Time page', function (): void {
    Carbon::setTestNow('2026-09-24 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->seed(SettingsSeeder::class);

    $tapu = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();
    $task = Task::factory()->create();
    $task->assignees()->attach($tapu->id, ['is_primary' => true]);

    TimeEntry::factory()
        ->forEmployee($tapu)
        ->onTask($task)
        ->flagged()
        ->create(['work_date' => '2026-09-24']);

    $payload = strtolower((string) json_encode(
        $this->actingAs($tapu->user)->get('/employee/time')->assertOk()->viewData('page'),
    ));

    foreach (FORBIDDEN_WORDS as $word) {
        expect($payload)->not->toContain($word);
    }

    Carbon::setTestNow();
});

it('sends no score with the timer on a task detail page either', function (): void {
    Carbon::setTestNow('2026-09-24 09:00:00');

    $this->seed(RolePermissionSeeder::class);
    $this->seed(SettingsSeeder::class);

    $tapu = Employee::factory()->forRole(RoleName::REMOTE_EMPLOYEE)->create();
    $task = Task::factory()->create();
    $task->assignees()->attach($tapu->id, ['is_primary' => true]);

    $payload = strtolower((string) json_encode(
        $this->actingAs($tapu->user)->get("/employee/tasks/{$task->id}")->assertOk()->viewData('page'),
    ));

    foreach (FORBIDDEN_WORDS as $word) {
        expect($payload)->not->toContain($word);
    }

    Carbon::setTestNow();
});
