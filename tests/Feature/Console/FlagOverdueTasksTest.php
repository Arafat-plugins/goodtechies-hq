<?php

use App\Models\Notification;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Support\NotificationType;
use App\Support\TaskStatus;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| hq:flag-overdue
|--------------------------------------------------------------------------
|
| Two rules, and the second is the one with teeth.
|
|   - The overdue BUCKET stays a query. Nothing here writes a flag on a task,
|     so the bucket is right at every hour of every day, and the command that
|     sends the mail is measured against the same scope the screens use.
|   - "One notification per NEWLY overdue task … once per task, not per day."
|     The memory is the notifications table itself, which is why a second run
|     on the same morning, a run three days later and a run after a weekend of
|     downtime all send the same nothing.
|
*/

beforeEach(function () {
    $this->seed();

    $this->tasks = app(TaskService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->faruk = User::where('email', 'faruk@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();

    $this->late = $this->tasks->create($this->admin, [
        'project_id' => $this->project->id,
        'title' => 'Ship the redirect map',
        'status' => 'todo',
        'due_date' => Carbon::today()->subDays(3)->toDateString(),
    ], [$this->tapu->employee->id]);

    Notification::query()->delete();
});

/**
 * Notifications of one type about one task, whoever they were sent to.
 */
function overdueRowsFor(Task $task): Collection
{
    return Notification::query()
        ->where('group_key', sprintf(
            '%s:%s:%d',
            NotificationType::TaskOverdue->value,
            $task->getMorphClass(),
            $task->getKey(),
        ))
        ->get();
}

it('notifies the assignee and the admins about a newly overdue task', function () {
    $this->artisan('hq:flag-overdue')->assertSuccessful();

    $recipients = overdueRowsFor($this->late)->pluck('user_id')->map('intval')->sort()->values()->all();

    // Nobody in the seed has a manager_id, so "manager/Admin" resolves to every Admin.
    expect($recipients)->toBe(collect([$this->tapu, $this->admin, $this->faruk])
        ->pluck('id')->map('intval')->sort()->values()->all())
        ->and(overdueRowsFor($this->late)->first()->summary())->toBe('"Ship the redirect map" is overdue')
        // The Accountant holds no tasks.* key, so they are not on any list this command builds.
        ->and(Notification::query()->forUser($this->accountant)->count())->toBe(0);
})->group('phase2');

it('sends one notification per task and not one per day', function () {
    $this->artisan('hq:flag-overdue')->assertSuccessful();

    $first = overdueRowsFor($this->late)->count();

    expect($first)->toBeGreaterThan(0);

    // A second run the same morning — somebody watching the cron, or a retry.
    $this->artisan('hq:flag-overdue')->assertSuccessful();

    // And three days later, with the task still overdue and still open.
    $this->travel(3)->days();
    $this->artisan('hq:flag-overdue')->assertSuccessful();

    expect(overdueRowsFor($this->late)->count())->toBe($first)
        ->and(overdueRowsFor($this->late)->pluck('count')->map('intval')->unique()->all())->toBe([1]);
})->group('phase2');

it('measures against the as-of date, so a task not yet late is not flagged', function () {
    $soon = $this->tasks->create($this->admin, [
        'project_id' => $this->project->id,
        'title' => 'Not late yet',
        'status' => 'todo',
        'due_date' => Carbon::today()->addDay()->toDateString(),
    ], [$this->tapu->employee->id]);

    $this->artisan('hq:flag-overdue')->assertSuccessful();

    expect(overdueRowsFor($soon))->toHaveCount(0);

    // Two days on it is late, and the same command says so.
    $this->artisan('hq:flag-overdue', ['--as-of' => Carbon::today()->addDays(2)->toDateString()])->assertSuccessful();

    expect(overdueRowsFor($soon))->not->toHaveCount(0);
})->group('phase2');

it('leaves closed and archived tasks alone', function () {
    $done = $this->tasks->create($this->admin, [
        'project_id' => $this->project->id,
        'title' => 'Finished, and late, and nobody cares',
        'status' => 'todo',
        'due_date' => Carbon::today()->subWeek()->toDateString(),
    ], [$this->tapu->employee->id]);

    $this->tasks->transition($this->admin, $done, TaskStatus::Cancelled, reason: 'Client dropped it');

    $archived = $this->tasks->create($this->admin, [
        'project_id' => $this->project->id,
        'title' => 'Parked and late',
        'status' => 'todo',
        'due_date' => Carbon::today()->subWeek()->toDateString(),
    ], [$this->tapu->employee->id]);

    $this->tasks->archive($this->admin, $archived);

    $this->artisan('hq:flag-overdue')->assertSuccessful();

    expect(overdueRowsFor($done))->toHaveCount(0)
        ->and(overdueRowsFor($archived))->toHaveCount(0);
})->group('phase2');

it('does not write anything to the task it flags', function () {
    $before = $this->late->fresh()->toArray();

    $this->artisan('hq:flag-overdue')->assertSuccessful();

    // The bucket is a query, so the row is untouched. `updated_at` included: a command that
    // touched the task would be a second write path with a notification hidden inside it.
    expect($this->late->fresh()->toArray())->toBe($before)
        // And the query itself still answers the same way it did before the command ran.
        ->and(Task::query()->overdue()->whereKey($this->late->id)->exists())->toBeTrue();
})->group('phase2');

it('is scheduled daily at 08:00', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains($event->command ?? '', 'hq:flag-overdue'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 8 * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
})->group('phase2');
