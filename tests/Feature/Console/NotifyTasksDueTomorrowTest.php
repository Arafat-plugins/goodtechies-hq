<?php

use App\Models\Notification;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use App\Services\RecurringTaskEngine;
use App\Services\TaskService;
use App\Support\NotificationPriority;
use App\Support\NotificationTab;
use App\Support\NotificationType;
use App\Support\RecurrenceRule;
use App\Support\TaskStatus;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| hq:notify-due-tomorrow
|--------------------------------------------------------------------------
|
| The last of Part D §19's fixed automation rules: "Task due tomorrow → notify
| assignee". Deliberately the same shape as hq:flag-overdue, and tested for the
| same two properties:
|
|   - it only SENDS. "Due tomorrow" is a query (Task::scopeDueOn) and nothing
|     here writes a flag on a task, so it cannot go stale at midnight;
|   - "once per task" is answered by the notifications table, so a second run
|     at 08:05 sends nothing.
|
| Recipients pass the same two filters every notification in this application
| passes: the type's required permission, and `view` on the task itself.
|
*/

beforeEach(function () {
    $this->seed();

    $this->tasks = app(TaskService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->today = Carbon::parse('2026-10-20');
    $this->project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();

    $this->task = $this->tasks->create($this->admin, [
        'project_id' => $this->project->id,
        'title' => 'Ship the redirect map',
        'status' => 'todo',
        'due_date' => $this->today->copy()->addDay()->toDateString(),
    ], [$this->tapu->employee->id]);

    // Notifications the CREATE fired (task.assigned) are not this command's business.
    Notification::query()->delete();
});

/** How many due-tomorrow rows this person has. */
function dueTomorrowRows(User $user): int
{
    return Notification::query()
        ->where('user_id', $user->getKey())
        ->where('type', NotificationType::TaskDueTomorrow->value)
        ->count();
}

it('reminds the assignee the day before a task is due', function () {
    $this->artisan('hq:notify-due-tomorrow --as-of='.$this->today->toDateString())
        ->expectsOutputToContain('1 reminded')
        ->assertSuccessful();

    expect(dueTomorrowRows($this->tapu))->toBe(1);

    $row = Notification::query()->where('user_id', $this->tapu->id)->firstOrFail();

    expect($row->type)->toBe(NotificationType::TaskDueTomorrow)
        ->and($row->summary())->toBe('"Ship the redirect map" is due tomorrow')
        ->and($row->type->tab())->toBe(NotificationTab::Tasks)
        ->and($row->type->priority())->toBe(NotificationPriority::Normal)
        ->and($row->payload['context']['due_date'])->toBe($this->today->copy()->addDay()->toDateString());
})->group('phase3');

it('reminds the assignee and nobody else — not the creator, not the Admins', function () {
    $this->artisan('hq:notify-due-tomorrow --as-of='.$this->today->toDateString())->assertSuccessful();

    // Unlike overdue, which escalates to the manager or the Admins, this is a heads-up to the
    // person holding the work. A manager told every night about everything anybody has due
    // tomorrow stops reading the bell.
    expect(dueTomorrowRows($this->tapu))->toBe(1)
        ->and(dueTomorrowRows($this->admin))->toBe(0)
        ->and(dueTomorrowRows($this->yaseen))->toBe(0)
        // The Accountant holds no tasks.* key, so the type's own permission filter drops them —
        // their role is named nowhere.
        ->and(dueTomorrowRows($this->accountant))->toBe(0);
})->group('phase3');

it('sends once per task, however many times it runs that morning', function () {
    $this->artisan('hq:notify-due-tomorrow --as-of='.$this->today->toDateString())->assertSuccessful();

    $this->artisan('hq:notify-due-tomorrow --as-of='.$this->today->toDateString())
        ->expectsOutputToContain('0 reminded')
        ->assertSuccessful();

    expect(dueTomorrowRows($this->tapu))->toBe(1);
})->group('phase3');

it('ignores a task that is due later, already closed, or archived', function () {
    // Due in a week.
    $this->tasks->create($this->admin, [
        'project_id' => $this->project->id,
        'title' => 'Next week',
        'status' => 'todo',
        'due_date' => $this->today->copy()->addWeek()->toDateString(),
    ], [$this->tapu->employee->id]);

    // Due tomorrow but already cancelled: dueOn() is open-only.
    $closed = $this->tasks->create($this->admin, [
        'project_id' => $this->project->id,
        'title' => 'Dropped',
        'status' => 'todo',
        'due_date' => $this->today->copy()->addDay()->toDateString(),
    ], [$this->tapu->employee->id]);

    $this->tasks->transition($this->admin, $closed, TaskStatus::Cancelled, reason: 'Client pulled it.');

    // Due tomorrow but archived: not work anybody is being asked to do.
    $archived = $this->tasks->create($this->admin, [
        'project_id' => $this->project->id,
        'title' => 'Shelved',
        'status' => 'todo',
        'due_date' => $this->today->copy()->addDay()->toDateString(),
    ], [$this->tapu->employee->id]);

    $this->tasks->archive($this->admin, $archived);

    Notification::query()->delete();

    $this->artisan('hq:notify-due-tomorrow --as-of='.$this->today->toDateString())
        ->expectsOutputToContain('1 reminded')
        ->assertSuccessful();

    expect(dueTomorrowRows($this->tapu))->toBe(1);
})->group('phase3');

it('writes nothing to the task it reminds about', function () {
    $before = Task::findOrFail($this->task->id)->only(['status', 'due_date', 'updated_at']);

    $this->artisan('hq:notify-due-tomorrow --as-of='.$this->today->toDateString())->assertSuccessful();

    expect(Task::findOrFail($this->task->id)->only(['status', 'due_date', 'updated_at']))->toEqual($before);
})->group('phase3');

it('says so and stops when nothing is due tomorrow', function () {
    $this->artisan('hq:notify-due-tomorrow --as-of=2026-01-01')
        ->expectsOutputToContain('No tasks due on 2026-01-02')
        ->assertSuccessful();

    expect(Notification::count())->toBe(0);
})->group('phase3');

it('reminds the assignee of a task the recurring engine generated, like any other', function () {
    // The engine's instances are ordinary tasks — same table, same service, same status machine
    // — so every automation rule reaches them with no special case anywhere. A template due on
    // the 20th, generated that morning, is reminded about that evening.
    $template = RecurringTask::factory()
        ->for($this->project)
        ->assignedTo($this->tapu->employee)
        ->create([
            'title_template' => 'Buffalo Modular SEO — {period}',
            'created_by' => $this->admin->id,
            // Generated on the 20th, due the day after: the offset is measured from the period's
            // START, so it means the same thing on a monthly, weekly or custom rule.
            'recurrence_rule' => RecurrenceRule::monthly(20, 20)->toArray(),
        ]);

    $log = app(RecurringTaskEngine::class)->generate($template, $this->today);
    $generated = Task::findOrFail($log->task_id);

    Notification::query()->delete();

    $this->artisan('hq:notify-due-tomorrow --as-of='.$this->today->toDateString())->assertSuccessful();

    expect($generated->due_date->toDateString())->toBe($this->today->copy()->addDay()->toDateString())
        ->and($generated->isGenerated())->toBeTrue()
        // Both the hand-made task and the generated one.
        ->and(dueTomorrowRows($this->tapu))->toBe(2);
})->group('phase3');

it('is scheduled daily at 08:00, without overlapping, in the app timezone', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains($event->command ?? '', 'hq:notify-due-tomorrow'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 8 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->timezone ?? config('app.timezone'))->toBe(config('app.timezone'))
        ->and(trim(Str::after($event->command, 'artisan'), " '\""))->toBe('hq:notify-due-tomorrow');
})->group('phase3');

it('lets the notifications table hold the new type', function () {
    // The Phase 2 migration wrote notifications_type_is_known from the enum as it stood then.
    // Without the Phase 3 migration that rewrites it, the first reminder at 08:00 the morning
    // after deploy would be refused by a CHECK — a failure that would read as a broken scheduler.
    expect(NotificationType::values())->toContain('task.due_tomorrow');

    $this->artisan('hq:notify-due-tomorrow --as-of='.$this->today->toDateString())->assertSuccessful();

    expect(dueTomorrowRows($this->tapu))->toBe(1);
})->group('phase3');
