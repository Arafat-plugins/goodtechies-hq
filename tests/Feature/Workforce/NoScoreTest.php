<?php

use App\Models\User;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| No productivity score on the Timesheet or on Workload
|--------------------------------------------------------------------------
|
| Part H forbids productivity scoring outright, and these are the two
| screens of Phase 4 most likely to grow one: a grid of somebody's hours
| against a target, and a table of how much work each person is carrying.
|
| Two checks, because a score gets in two ways — as WORDS on a screen and as
| a NUMBER in a payload under another name. The timer half
| (tests/Feature/Employee/TimeScreenTest) and the attendance half
| (tests/Feature/Attendance/NoScoreTest) run the same pair over their own
| files; the helper names here are deliberately distinct from both, because
| Pest declares test-file functions globally and a second `withoutComments()`
| would be a fatal redeclare rather than a failing assertion.
|
| The one comparison either screen makes is "18h 20m of 25h" — a total
| against a number the employee's own SCHEDULE set, which is a fact. There
| is no division anywhere: estimated and tracked are printed as two figures
| and never as a ratio between them.
|
*/

const WORKFORCE_FORBIDDEN_WORDS = ['score', 'productivity', 'productive', 'efficiency', 'rating', 'ranking'];

/** Every file this slice put in front of a person, or that builds what they see. */
function workforceSourceFiles(): array
{
    $roots = [
        base_path('resources/js/Components/Timesheet'),
        base_path('resources/js/Components/Workload'),
        base_path('resources/js/Pages/Admin/Timesheet'),
        base_path('resources/js/Pages/Admin/Workload'),
        base_path('resources/js/Pages/Employee/Timesheet'),
    ];

    $files = [
        base_path('app/Services/TimesheetService.php'),
        base_path('app/Services/WorkloadService.php'),
        base_path('app/Http/Controllers/Concerns/BuildsTimesheet.php'),
        base_path('app/Http/Controllers/Admin/TimesheetController.php'),
        base_path('app/Http/Controllers/Admin/WorkloadController.php'),
        base_path('app/Http/Controllers/Employee/TimesheetController.php'),
    ];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $entry) {
            if ($entry->isFile()) {
                $files[] = $entry->getPathname();
            }
        }
    }

    return array_values(array_filter($files, 'is_file'));
}

/**
 * Source with its comments removed.
 *
 * A docblock explaining why there is no score is not a score; a label would be. Stripping is
 * what lets this slice be explicit about the rule in prose without tripping over itself.
 */
function workforceWithoutComments(string $source): string
{
    $stripped = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
    $stripped = preg_replace('#<!--.*?-->#s', '', $stripped) ?? $stripped;

    return preg_replace('#(^|\s)//[^\n]*#m', '', $stripped) ?? $stripped;
}

/**
 * The payload with every record NAME blanked out.
 *
 * The rule is about what this application says, not about what the agency's work is called.
 * The timesheet lists task titles, and the seeded ones include *"Monthly rankings report —
 * Buffalo Modular"* — a real SEO deliverable, and a client's word for their own job. Grepping
 * it would make this test fail on the customer's vocabulary rather than on ours, and the fix
 * somebody would reach for is renaming their task. So titles and names are removed first, and
 * every other key and value in the payload is still read.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function workforceWithoutRecordNames(array $payload): array
{
    array_walk_recursive($payload, function (mixed &$value, string|int $key): void {
        if (is_string($value) && in_array($key, ['title', 'name', 'client'], true)) {
            $value = '';
        }
    });

    return $payload;
}

it('has no score and no productivity figure in any timesheet or workload source file', function (): void {
    $offenders = [];

    foreach (workforceSourceFiles() as $file) {
        $body = workforceWithoutComments((string) file_get_contents($file));

        foreach (WORKFORCE_FORBIDDEN_WORDS as $word) {
            if (stripos($body, $word) !== false) {
                $offenders[] = basename($file).' contains "'.$word.'"';
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('sends no score in any timesheet or workload payload', function (): void {
    Carbon::setTestNow('2026-09-24 09:00:00');

    $this->seed();

    $admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    $pages = [
        [$admin, '/admin/workload'],
        [$admin, '/admin/timesheet/'.$tapu->employee->id],
        [$tapu, '/employee/timesheet'],
    ];

    // Key NAMES as well as values: "Efficiency: 82 %" under a different name is the same thing.
    foreach ($pages as [$user, $path]) {
        $props = $this->actingAs($user)->get($path)->assertOk()->viewData('page')['props'];
        $json = json_encode(workforceWithoutRecordNames($props), JSON_THROW_ON_ERROR);

        foreach (WORKFORCE_FORBIDDEN_WORDS as $word) {
            expect(stripos($json, $word))->toBeFalse("{$path} payload contains \"{$word}\"");
        }
    }

    Carbon::setTestNow();
});
