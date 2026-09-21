<?php

use App\Support\RoleName;
use App\Support\TaskStatus;

/*
|--------------------------------------------------------------------------
| The task status transition map (master prompt Part D, Phase 2)
|--------------------------------------------------------------------------
|
| The drag endpoints and the edit form will both ask TaskStatus the same two
| questions — "is this move legal at all" and "may this role make it" — so the
| answers are pinned here, at the enum, before either caller exists.
|
| Both halves are tested: the moves that ARE allowed, and the moves that are
| not. A transition map with only positive tests is a map that permits
| everything nobody thought to check.
|
*/

it('walks the happy path from backlog to completed', function () {
    // BACKLOG → TO DO → IN PROGRESS → IN REVIEW → COMPLETED, one step at a time.
    $path = [
        TaskStatus::Backlog,
        TaskStatus::Todo,
        TaskStatus::InProgress,
        TaskStatus::InReview,
        TaskStatus::Completed,
    ];

    for ($i = 0; $i < count($path) - 1; $i++) {
        expect($path[$i]->canTransitionTo($path[$i + 1]))->toBeTrue(
            sprintf('%s → %s should be legal', $path[$i]->value, $path[$i + 1]->value),
        );
    }
})->group('phase2');

it('sends changes requested back to in progress', function () {
    expect(TaskStatus::InReview->canTransitionTo(TaskStatus::ChangesRequested))->toBeTrue()
        ->and(TaskStatus::ChangesRequested->canTransitionTo(TaskStatus::InProgress))->toBeTrue();
})->group('phase2');

it('allows cancelling from any open status', function (TaskStatus $from) {
    expect($from->canTransitionTo(TaskStatus::Cancelled))->toBeTrue();
})->with(fn () => array_map(
    fn (TaskStatus $status): array => [$status],
    array_filter(TaskStatus::cases(), fn (TaskStatus $s): bool => $s->isOpen()),
))->group('phase2');

it('exposes exactly the mapped transitions and nothing else', function (string $from, array $expected) {
    $actual = array_map(
        fn (TaskStatus $status): string => $status->value,
        TaskStatus::from($from)->allowedTransitions(),
    );

    expect($actual)->toEqualCanonicalizing($expected);

    // The complement: every status NOT in the list is refused.
    foreach (TaskStatus::cases() as $to) {
        expect(TaskStatus::from($from)->canTransitionTo($to))
            ->toBe(in_array($to->value, $expected, true), "{$from} → {$to->value}");
    }
})->with([
    ['backlog', ['todo', 'waiting', 'cancelled']],
    ['todo', ['in_progress', 'backlog', 'waiting', 'cancelled']],
    ['in_progress', ['in_review', 'todo', 'waiting', 'cancelled']],
    ['in_review', ['completed', 'changes_requested', 'in_progress', 'cancelled']],
    ['changes_requested', ['in_progress', 'waiting', 'cancelled']],
    ['waiting', ['backlog', 'todo', 'in_progress', 'cancelled']],
    ['completed', ['in_progress']],
    ['cancelled', ['backlog', 'todo']],
])->group('phase2');

it('never lets a status skip a step', function (string $from, string $to) {
    expect(TaskStatus::from($from)->canTransitionTo(TaskStatus::from($to)))->toBeFalse();
})->with([
    // The whole point of the map: you cannot mark your own work done from the board.
    ['backlog', 'in_progress'],
    ['backlog', 'in_review'],
    ['backlog', 'completed'],
    ['todo', 'in_review'],
    ['todo', 'completed'],
    ['in_progress', 'completed'],
    ['in_progress', 'changes_requested'],
    // Changes requested is a verdict, not a place you can jump to.
    ['waiting', 'changes_requested'],
    ['waiting', 'in_review'],
    ['changes_requested', 'in_review'],
    ['changes_requested', 'completed'],
    // A completed task is reopened to in progress, not straight back to review.
    ['completed', 'in_review'],
    ['completed', 'todo'],
    ['completed', 'cancelled'],
    ['cancelled', 'in_progress'],
    ['cancelled', 'completed'],
])->group('phase2');

it('refuses a transition to itself', function (TaskStatus $status) {
    expect($status->canTransitionTo($status))->toBeFalse();
})->with(fn () => array_map(fn (TaskStatus $s): array => [$s], TaskStatus::cases()))
    ->group('phase2');

/*
|--------------------------------------------------------------------------
| Role half
|--------------------------------------------------------------------------
*/

it('lets an employee do ordinary work on the board', function (string $from, string $to) {
    foreach ([RoleName::EMPLOYEE, RoleName::REMOTE_EMPLOYEE] as $role) {
        expect(TaskStatus::from($from)->mayRoleTransition($role, TaskStatus::from($to)))
            ->toBeTrue("{$role->value} should be able to do {$from} → {$to}");
    }
})->with([
    ['backlog', 'todo'],
    ['todo', 'in_progress'],
    ['in_progress', 'in_review'],
    ['in_progress', 'waiting'],
    ['changes_requested', 'in_progress'],
    ['waiting', 'in_progress'],
    // Pulling your own submission back out of review is work, not a verdict.
    ['in_review', 'in_progress'],
])->group('phase2');

it('never lets an employee pass or reject a review', function (string $to) {
    foreach ([RoleName::EMPLOYEE, RoleName::REMOTE_EMPLOYEE] as $role) {
        expect(TaskStatus::InReview->mayRoleTransition($role, TaskStatus::from($to)))
            ->toBeFalse("{$role->value} must not be able to do in_review → {$to}");
    }

    // ...and a Manager can, because the reviewer is the project's PM. Which PM is a fact
    // about one task, so TaskPolicy::review() carries that half.
    expect(TaskStatus::InReview->mayRoleTransition(RoleName::MANAGER, TaskStatus::from($to)))->toBeTrue()
        ->and(TaskStatus::InReview->mayRoleTransition(RoleName::ADMIN, TaskStatus::from($to)))->toBeTrue();
})->with([['completed'], ['changes_requested']])->group('phase2');

it('never lets an employee cancel a task', function (TaskStatus $from) {
    foreach ([RoleName::EMPLOYEE, RoleName::REMOTE_EMPLOYEE] as $role) {
        expect($from->mayRoleTransition($role, TaskStatus::Cancelled))->toBeFalse();
    }

    expect($from->mayRoleTransition(RoleName::MANAGER, TaskStatus::Cancelled))->toBeTrue()
        ->and($from->mayRoleTransition(RoleName::ADMIN, TaskStatus::Cancelled))->toBeTrue();
})->with(fn () => array_map(
    fn (TaskStatus $status): array => [$status],
    array_filter(TaskStatus::cases(), fn (TaskStatus $s): bool => $s->isOpen()),
))->group('phase2');

it('reopens a finished or cancelled task for an admin only', function (string $from, string $to) {
    expect(TaskStatus::from($from)->mayRoleTransition(RoleName::ADMIN, TaskStatus::from($to)))->toBeTrue();

    foreach ([RoleName::MANAGER, RoleName::EMPLOYEE, RoleName::REMOTE_EMPLOYEE] as $role) {
        expect(TaskStatus::from($from)->mayRoleTransition($role, TaskStatus::from($to)))
            ->toBeFalse("{$role->value} must not reopen {$from}");
    }
})->with([
    ['completed', 'in_progress'],
    ['cancelled', 'backlog'],
    ['cancelled', 'todo'],
])->group('phase2');

it('gives the accountant no transition at all, legal or otherwise', function () {
    foreach (TaskStatus::cases() as $from) {
        foreach (TaskStatus::cases() as $to) {
            expect($from->mayRoleTransition(RoleName::ACCOUNTANT, $to))
                ->toBeFalse("accountant must not do {$from->value} → {$to->value}");
        }
    }
})->group('phase2');

it('refuses an illegal transition even to an admin', function () {
    // A role escalates WHO may make a move, never WHICH moves exist.
    expect(TaskStatus::Backlog->mayRoleTransition(RoleName::ADMIN, TaskStatus::Completed))->toBeFalse()
        ->and(TaskStatus::Completed->mayRoleTransition(RoleName::ADMIN, TaskStatus::Cancelled))->toBeFalse()
        ->and(TaskStatus::Cancelled->mayRoleTransition(RoleName::ADMIN, TaskStatus::Completed))->toBeFalse();
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The supporting facts the rest of the phase leans on
|--------------------------------------------------------------------------
*/

it('counts only completed and cancelled as closed', function () {
    expect(TaskStatus::closed())->toBe([TaskStatus::Completed, TaskStatus::Cancelled])
        ->and(TaskStatus::Completed->isOpen())->toBeFalse()
        ->and(TaskStatus::Cancelled->isOpen())->toBeFalse();

    foreach (TaskStatus::cases() as $status) {
        if (! in_array($status, TaskStatus::closed(), true)) {
            expect($status->isOpen())->toBeTrue("{$status->value} should be open");
        }
    }
})->group('phase2');

it('gives every status its own badge tone', function () {
    $tones = array_map(fn (TaskStatus $s): string => $s->tone(), TaskStatus::cases());

    // Eight statuses, eight tones: no two statuses share a colour. A board where BACKLOG and
    // TO DO are the same colour is a board that lies.
    expect($tones)->toHaveCount(8)
        ->and(array_unique($tones))->toHaveCount(8);
})->group('phase2');

it('has a transition row for every status', function () {
    expect(array_keys(TaskStatus::TRANSITIONS))
        ->toEqualCanonicalizing(array_map(fn (TaskStatus $s): string => $s->value, TaskStatus::cases()));

    // Every target named in the map is itself a real status.
    foreach (TaskStatus::TRANSITIONS as $from => $targets) {
        foreach ($targets as $target) {
            expect(TaskStatus::tryFrom($target))->not->toBeNull("{$from} → {$target} names no status");
        }
    }
})->group('phase2');
