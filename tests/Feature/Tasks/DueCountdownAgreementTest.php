<?php

use App\Models\Task;
use App\Support\TaskStatus;
use Illuminate\Support\Carbon;

/*
 * The Board card's countdown (resources/js/lib/dueCountdown.ts) and the server's overdue rule
 * must never disagree: a card that says "3 hours left" on a task the Overdue filter already
 * lists is a board that lies. Both sides read tests/fixtures/due-countdown-cases.json — the JS
 * runner checks the helper's labels and `overdue`, and this file checks the model method and the
 * query scope against the same `overdue` at the same instant, in the app zone.
 */

function countdownAgreementCases(): array
{
    $fixture = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/due-countdown-cases.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $cases = [];

    foreach ($fixture['cases'] as $case) {
        if (($case['status'] ?? null) !== null) {
            $cases[$case['name']] = [$fixture['timezone'], $case];
        }
    }

    return $cases;
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('agrees with the countdown helper on overdue', function (string $zone, array $case): void {
    expect(config('app.timezone'))->toBe($zone);

    $task = Task::factory()
        ->status(TaskStatus::from($case['status']))
        ->create(['due_date' => $case['due_date']]);

    Carbon::setTestNow(Carbon::parse($case['now'])->setTimezone($zone));

    $fresh = $task->fresh();

    expect($fresh->isOverdue())->toBe($case['expected']['overdue'])
        ->and(Task::query()->whereKey($task->id)->overdue()->exists())->toBe($case['expected']['overdue']);
})->with(countdownAgreementCases());
