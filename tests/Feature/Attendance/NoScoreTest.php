<?php

use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| No productivity score in the attendance half of Phase 4 either
|--------------------------------------------------------------------------
|
| Part H forbids productivity scoring outright, and the Phase 4 plan asks for
| a grep on the UI strings "score" and "productivity". The timer half ships
| the same check over its own files (tests/Feature/Employee/TimeScreenTest);
| this is the attendance half, over the files this slice put in front of a
| person. Helper names are deliberately distinct from that file's — Pest
| declares test-file functions globally, so two `stripComments()` would be a
| fatal redeclare rather than a failing assertion.
|
| Two checks, because a score can get in two ways: as WORDS on a screen, and
| as a NUMBER in a payload under another name. Attendance is a record of
| presence. Nothing in it ranks or rates anybody, and the only numbers it
| carries are a clock time, a duration and a count of days.
|
*/

const ATTENDANCE_FORBIDDEN_WORDS = ['score', 'productivity', 'productive', 'efficiency', 'rating', 'ranking'];

/** Every file this slice put in front of a person, or that builds what they see. */
function attendanceSourceFiles(): array
{
    $roots = [
        base_path('resources/js/Components/Attendance'),
        base_path('resources/js/Pages/Admin/Attendance'),
        base_path('resources/js/Pages/Admin/Schedules'),
    ];

    $files = [
        base_path('resources/js/Pages/Shared/Attendance.vue'),
        base_path('app/Services/AttendanceService.php'),
        base_path('app/Services/ScheduleService.php'),
        base_path('app/Support/AttendanceDay.php'),
        base_path('app/Support/AttendanceStatus.php'),
        base_path('app/Http/Controllers/Shared/AttendanceController.php'),
        base_path('app/Http/Controllers/Admin/AttendanceController.php'),
        base_path('app/Http/Controllers/Admin/ScheduleController.php'),
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
 * what makes the difference between the two, and it is why this file can be explicit about the
 * rule in prose without tripping over itself.
 */
function attendanceWithoutComments(string $source): string
{
    $stripped = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
    $stripped = preg_replace('#<!--.*?-->#s', '', $stripped) ?? $stripped;

    return preg_replace('#(^|\s)//[^\n]*#m', '', $stripped) ?? $stripped;
}

it('has no score and no productivity figure in any attendance source file', function (): void {
    $offenders = [];

    foreach (attendanceSourceFiles() as $file) {
        $body = attendanceWithoutComments((string) file_get_contents($file));

        foreach (ATTENDANCE_FORBIDDEN_WORDS as $word) {
            if (stripos($body, $word) !== false) {
                $offenders[] = basename($file).' contains "'.$word.'"';
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('sends no score in any attendance payload', function (): void {
    $this->seed();

    $admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    app(AttendanceService::class)->clockIn(
        $yaseen->employee,
        Carbon::parse('2026-09-14')->setTime(8, 58),
    );

    // Key NAMES as well as values: "Efficiency: 82 %" under a different name is the same thing.
    foreach (['/admin/attendance?date=2026-09-14', '/attendance/'.$yaseen->employee->id, '/admin/schedules'] as $path) {
        $props = $this->actingAs($admin)->get($path)->assertOk()->viewData('page')['props'];
        $json = json_encode($props, JSON_THROW_ON_ERROR);

        foreach (ATTENDANCE_FORBIDDEN_WORDS as $word) {
            expect(stripos($json, $word))->toBeFalse("{$path} payload contains \"{$word}\"");
        }
    }
});
