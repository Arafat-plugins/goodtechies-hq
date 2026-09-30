<?php

use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use App\Support\TaskStatus;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Brief 012 — the comment count on a board card
|--------------------------------------------------------------------------
|
| `comment_count` is the number of messages in the task's own discussion
| (`Task::discussionMessages()`, a `withCount`). It counts that task's
| conversation and nothing else — not a sibling task's, not the project's —
| it is the same number for every reader who may open the task, and it costs
| the board no query per card.
|
| Constants and helpers are global in Pest, so everything here is COMMENT_.
|
*/

/** One task's card off a Board payload. */
function COMMENT_card(array $props, int $id): ?array
{
    return collect($props['board']['columns'])->flatMap(fn (array $column) => $column['tasks'])
        ->firstWhere('id', $id);
}

/** How many statements one GET runs, as QueryCountTest measures it. */
function COMMENT_queries(User $user, string $url): int
{
    $count = 0;

    DB::listen(function () use (&$count): void {
        $count++;
    });

    test()->actingAs($user)->get($url)->assertOk();

    DB::getEventDispatcher()->forget(QueryExecuted::class);

    return $count;
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $this->project = Project::factory()->create(['name' => 'Comment count project']);

    $this->task = Task::factory()->for($this->project)->status(TaskStatus::Todo)
        ->assignedTo($this->yaseen->employee)->create(['title' => 'Write the release notes']);
    $this->other = Task::factory()->for($this->project)->status(TaskStatus::Todo)
        ->assignedTo($this->yaseen->employee)->create(['title' => 'Check the changelog']);
});

it('counts the messages posted on the task\'s discussion, and only those', function () {
    foreach (['First pass is up.', 'Looks good.', 'Merged.'] as $body) {
        $this->actingAs($this->admin)
            ->post("/admin/tasks/{$this->task->id}/discussion", ['body' => $body])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    $this->actingAs($this->yaseen)
        ->post("/employee/tasks/{$this->other->id}/discussion", ['body' => 'Starting on it.'])
        ->assertRedirect()
        ->assertSessionHas('success');

    // The project's own channel is a different conversation; it is not this task's discussion.
    Message::create([
        'conversation_id' => app(ConversationService::class)->forProject($this->project)->id,
        'author_id' => $this->admin->id,
        'body' => 'Project-wide note.',
    ]);

    $posted = Message::query()
        ->whereHas('conversation', fn ($q) => $q->where('type', 'task')->where('linked_task_id', $this->task->id))
        ->count();
    expect($posted)->toBe(3);

    $admin = $this->actingAs($this->admin)->get('/admin/tasks/board')->assertOk()->inertiaPage()['props'];
    expect(COMMENT_card($admin, $this->task->id)['comment_count'])->toBe(3)
        ->and(COMMENT_card($admin, $this->other->id)['comment_count'])->toBe(1);

    // The same number for any reader of the task: the assignee's board and the detail page agree.
    $employee = $this->actingAs($this->yaseen)->get('/employee/tasks/board')->assertOk()->inertiaPage()['props'];
    expect(COMMENT_card($employee, $this->task->id)['comment_count'])->toBe(3);

    $detail = $this->actingAs($this->admin)->get("/admin/tasks/{$this->task->id}")->assertOk()->inertiaPage()['props']['task'];
    expect($detail['comment_count'])->toBe(3);
});

it('is zero for a task nobody has discussed', function () {
    $props = $this->actingAs($this->admin)->get('/admin/tasks/board')->assertOk()->inertiaPage()['props'];

    expect(COMMENT_card($props, $this->task->id)['comment_count'])->toBe(0);
});

it('costs the board no query per card, however many comments there are', function () {
    // One unmeasured visit first: the first request of a test warms caches the rest do not pay.
    $this->actingAs($this->admin)->get('/admin/tasks/board')->assertOk();
    $before = COMMENT_queries($this->admin, '/admin/tasks/board');

    foreach ([$this->task, $this->other] as $task) {
        foreach (['One.', 'Two.'] as $body) {
            $this->actingAs($this->admin)
                ->post("/admin/tasks/{$task->id}/discussion", ['body' => $body])
                ->assertRedirect();
        }
    }

    expect(COMMENT_queries($this->admin, '/admin/tasks/board'))->toBe($before);
});
