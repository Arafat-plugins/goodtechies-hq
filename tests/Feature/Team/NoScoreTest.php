<?php

use App\Models\User;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| No productivity score on the Team directory
|--------------------------------------------------------------------------
|
| Part H §1 forbids productivity scoring outright, and a directory of people
| is the screen most likely to grow one: it already has a row per person and a
| word about each of them, and "today's availability" is one column away from
| "how much are they getting done".
|
| So the same pair of checks the Timesheet and Workload files run
| (tests/Feature/Workforce/NoScoreTest) and the attendance ones
| (tests/Feature/Attendance/NoScoreTest), over this slice's files: the WORDS in
| the source, and the payload under any key name.
|
| Two things this file also pins, which the word list alone would not:
|
|   - the directory is ordered by NAME and by nothing else. An order is a
|     ranking as soon as it is by anything a person could do better or worse.
|   - the payload carries no number but the row id. A count of anything per
|     person is a score with the word filed off.
|
| The helper names are prefixed `TEAM_` deliberately: Pest declares test-file
| functions and constants GLOBALLY, and a second `withoutComments()` would be a
| fatal redeclare rather than a failing assertion.
|
*/

const TEAM_FORBIDDEN_WORDS = ['score', 'productivity', 'productive', 'efficiency', 'rating', 'ranking', 'leaderboard'];

/** Every file this slice put in front of a person, or that builds what they see. */
function teamSourceFiles(): array
{
    $files = [
        base_path('app/Http/Controllers/Shared/TeamController.php'),
        base_path('app/Http/Resources/TeamMemberResource.php'),
        base_path('resources/js/Pages/Shared/Team.vue'),
        base_path('resources/js/Components/Team/team.ts'),
    ];

    return array_values(array_filter($files, 'is_file'));
}

/**
 * Source with its comments removed.
 *
 * A docblock explaining why there is no score is not a score; a label would be. Stripping is
 * what lets this slice be explicit about the rule in prose without tripping over itself.
 */
function teamWithoutComments(string $source): string
{
    $stripped = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
    $stripped = preg_replace('#<!--.*?-->#s', '', $stripped) ?? $stripped;

    return preg_replace('#(^|\s)//[^\n]*#m', '', $stripped) ?? $stripped;
}

it('has no score and no productivity figure in any Team directory source file', function (): void {
    $offenders = [];

    foreach (teamSourceFiles() as $file) {
        $body = teamWithoutComments((string) file_get_contents($file));

        foreach (TEAM_FORBIDDEN_WORDS as $word) {
            if (stripos($body, $word) !== false) {
                $offenders[] = basename($file).' contains "'.$word.'"';
            }
        }
    }

    expect($offenders)->toBe([]);
})->group('phase6', 'team');

it('sends no score in the Team directory payload', function (): void {
    Carbon::setTestNow('2026-09-24 10:00:00');

    $this->seed();

    $admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();

    // Key NAMES as well as values: "Efficiency: 82 %" under a different name is the same thing.
    $props = $this->actingAs($admin)->get('/team')->assertOk()->viewData('page')['props'];
    $json = json_encode($props['members'], JSON_THROW_ON_ERROR);

    foreach (TEAM_FORBIDDEN_WORDS as $word) {
        expect(stripos($json, $word))->toBeFalse("the Team payload contains \"{$word}\"");
    }

    Carbon::setTestNow();
})->group('phase6', 'team');

it('orders the directory by name and by nothing a person could do better or worse', function (): void {
    Carbon::setTestNow('2026-09-24 10:00:00');

    $this->seed();

    $admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();

    $names = array_column(
        $this->actingAs($admin)->get('/team')->assertOk()->viewData('page')['props']['members'],
        'name',
    );

    $sorted = $names;
    sort($sorted, SORT_NATURAL | SORT_FLAG_CASE);

    expect($names)->toBe($sorted);

    Carbon::setTestNow();
})->group('phase6', 'team');
