<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| No productivity score anywhere in leave
|--------------------------------------------------------------------------
|
| Part H forbids productivity scoring outright, and leave is a place it
| could creep in wearing a different hat: "days taken this year vs the team",
| "leave taken as a percentage of allowance", a balances grid sorted worst
| first. None of those is built and none may be.
|
| Two checks, because a score gets in two ways — as WORDS on a screen and as
| a NUMBER in a payload under another name. The helper names are deliberately
| distinct from the timesheet, workload, timer and attendance copies of this
| pair: Pest declares test-file functions globally, so a second
| `withoutComments()` would be a fatal redeclare rather than a failing
| assertion.
|
| The one comparison this feature makes is "2 days against 15 left" — a
| request against a number an Admin set for that one person. It is a fact
| about one employee and it is never put beside anybody else's.
|
*/

const LEAVE_FORBIDDEN_WORDS = ['score', 'productivity', 'productive', 'efficiency', 'rating', 'ranking'];

/** Every file this slice put in front of a person, or that builds what they see. */
function leaveSourceFiles(): array
{
    $roots = [
        base_path('resources/js/Components/Leave'),
        base_path('resources/js/Pages/Admin/Leave'),
    ];

    $files = [
        base_path('app/Services/LeaveService.php'),
        base_path('app/Models/LeaveRequest.php'),
        base_path('app/Models/LeaveBalance.php'),
        base_path('app/Models/LeaveType.php'),
        base_path('app/Support/LeaveStatus.php'),
        base_path('app/Http/Resources/LeaveRequestResource.php'),
        base_path('app/Http/Controllers/Shared/LeaveController.php'),
        base_path('app/Http/Controllers/Admin/LeaveController.php'),
        base_path('app/Http/Controllers/Admin/LeaveBalanceController.php'),
        base_path('resources/js/Pages/Shared/Leave.vue'),
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
 * A docblock explaining why there is no score is not a score; a label would be.
 */
function leaveWithoutComments(string $source): string
{
    $stripped = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
    $stripped = preg_replace('#<!--.*?-->#s', '', $stripped) ?? $stripped;

    return preg_replace('#(^|\s)//[^\n]*#m', '', $stripped) ?? $stripped;
}

/**
 * The payload with every record NAME blanked out, so this test reads what the application says
 * rather than what the agency's own work is called.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function leaveWithoutRecordNames(array $payload): array
{
    array_walk_recursive($payload, function (mixed &$value, string|int $key): void {
        if (is_string($value) && in_array($key, ['title', 'name', 'client', 'reason'], true)) {
            $value = '';
        }
    });

    return $payload;
}

it('has no score and no productivity figure in any leave source file', function (): void {
    $offenders = [];

    foreach (leaveSourceFiles() as $file) {
        $body = leaveWithoutComments((string) file_get_contents($file));

        foreach (LEAVE_FORBIDDEN_WORDS as $word) {
            if (stripos($body, $word) !== false) {
                $offenders[] = basename($file).' contains "'.$word.'"';
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('sends no score in any leave payload', function (): void {
    $this->seed();

    $admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $pages = [
        [$admin, '/admin/leave'],
        [$admin, '/admin/leave/calendar'],
        [$admin, '/admin/leave/balances'],
        [$yaseen, '/leave'],
    ];

    // Key NAMES as well as values: "Leave efficiency: 82 %" under another name is the same
    // thing.
    foreach ($pages as [$user, $path]) {
        $props = $this->actingAs($user)->get($path)->assertOk()->viewData('page')['props'];
        $json = json_encode(leaveWithoutRecordNames($props), JSON_THROW_ON_ERROR);

        foreach (LEAVE_FORBIDDEN_WORDS as $word) {
            expect(stripos($json, $word))->toBeFalse("{$path} payload contains \"{$word}\"");
        }
    }
});
