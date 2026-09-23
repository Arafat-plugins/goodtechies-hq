<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\TaskStatus;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Admin — the Company dashboard's attention panel and status donut
|--------------------------------------------------------------------------
|
| Decision 2-50. The panel used to mount bare and always show its empty state,
| directly beneath cards counting six overdue tasks and one awaiting review,
| and the donut was a hard-coded `:data="[]"` under copy reading "fills in
| once there is something to count" on a dashboard with 25 tasks on it.
|
| The five task cards themselves are asserted in MyTasksTest, beside the My
| Tasks page they are contrasted with. What is here is the two blocks below
| them, and the property they share with the cards: every number and every row
| is a query scoped by `Task::visibleTo()` whose predicate comes from
| `TaskBucket`, so nothing on this page can disagree with anything else on it.
|
| The seeded demo data has its own late and in-review work, which is why these
| assertions are relative — a row is looked for by id, and a count is checked
| against the same query asked again, never against a literal.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->faruk = User::where('email', 'faruk@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
});

/**
 * The attention rows this user is sent, as `kind => [id, …]`.
 *
 * @return array<string, list<string>>
 */
function attentionFor(object $test, User $user): array
{
    $rows = $test->actingAs($user)->get('/admin/dashboard')->assertOk()->inertiaPage()['props']['attention'];

    $byKind = [];

    foreach ($rows as $row) {
        $byKind[$row['kind']][] = $row['id'];
    }

    return $byKind;
}

/*
|--------------------------------------------------------------------------
| "Needs your attention"
|--------------------------------------------------------------------------
*/

it('lists what is waiting on this admin\'s verdict and what is late, and leads to both', function () {
    // A project this Admin is PM of, so TaskReviewers makes him its reviewer.
    $mine = Project::factory()->create(['pm_id' => $this->admin->employee->id]);

    $waiting = Task::factory()->for($mine)->status(TaskStatus::InReview)
        ->assignedTo($this->tapu->employee)->create(['title' => 'Waiting on a verdict']);

    // Older than anything the demo seed made late, so it leads the soonest-due-first list the
    // panel shows five of.
    $late = Task::factory()->for($mine)->overdue()
        ->assignedTo($this->tapu->employee)
        ->create(['title' => 'Late and unloved', 'due_date' => now()->subYears(2)->toDateString()]);

    $rows = collect($this->actingAs($this->admin)->get('/admin/dashboard')->assertOk()
        ->inertiaPage()['props']['attention']);

    $review = $rows->firstWhere('id', 'review-'.$waiting->id);
    $overdue = $rows->firstWhere('id', 'overdue-'.$late->id);

    expect($review)->not->toBeNull()
        ->and($review['title'])->toBe('Waiting on a verdict')
        ->and($review['meta'])->toStartWith('Waiting on your review')
        ->and($review['href'])->toBe('/admin/tasks/'.$waiting->id)
        ->and($overdue)->not->toBeNull()
        ->and($overdue['href'])->toBe('/admin/tasks/'.$late->id);

    // Every row leads somewhere. A thing that needs you and cannot be opened is worse than no
    // panel, so this follows each link rather than asserting its shape.
    foreach ([$review, $overdue] as $row) {
        $this->actingAs($this->admin)->get($row['href'])->assertOk();
    }
})->group('phase2');

it('says how late a row is in words, not only in red', function () {
    $late = Task::factory()->for(Project::factory())->overdue()
        ->assignedTo($this->tapu->employee)->create(['due_date' => now()->subYears(3)->toDateString()]);

    $row = collect($this->actingAs($this->admin)->get('/admin/dashboard')
        ->inertiaPage()['props']['attention'])
        ->firstWhere('id', 'overdue-'.$late->id);

    // DESIGN.md §6 rule 6, and a bug this repo has fixed twice: the urgent medallion is a
    // second encoding of the words, never the only one. This is the part a screen reader gets.
    expect($row['meta'])->toMatch('/^\\d+ days late/')
        ->and($row['tone'])->toBe('urgent');
})->group('phase2');

it('puts a review that is somebody else\'s to give on their panel and not on this one', function () {
    // Faruk is the PM, so he is the reviewer and Shahadat is not — even though Shahadat is an
    // Admin who can see every task in the agency. Seeing a task and being asked to rule on it
    // are different questions, and the panel asks the second.
    $theirs = Project::factory()->create(['pm_id' => $this->faruk->employee->id]);

    $waiting = Task::factory()->for($theirs)->status(TaskStatus::InReview)
        ->assignedTo($this->tapu->employee)->create();

    expect(attentionFor($this, $this->admin)['review'] ?? [])->not->toContain('review-'.$waiting->id)
        ->and(attentionFor($this, $this->faruk)['review'] ?? [])->toContain('review-'.$waiting->id);
})->group('phase2');

it('keeps an archived task off the panel', function () {
    $late = Task::factory()->for(Project::factory())->overdue()
        ->assignedTo($this->tapu->employee)->create(['archived_at' => now()]);

    expect(attentionFor($this, $this->admin)['overdue'] ?? [])->not->toContain('overdue-'.$late->id);
})->group('phase2');

it('shows a shortlist rather than a list', function () {
    Task::factory()->count(8)->for(Project::factory())->overdue()
        ->assignedTo($this->tapu->employee)->create();

    // The panel answers "what should I open first"; the cards above it lead to the whole of
    // each bucket.
    expect(count(attentionFor($this, $this->admin)['overdue'] ?? []))->toBe(5);
})->group('phase2');

it('agrees with the card above it about what is overdue', function () {
    Task::factory()->count(2)->for(Project::factory())->overdue()
        ->assignedTo($this->tapu->employee)->create(['due_date' => now()->subYears(4)->toDateString()]);

    $props = $this->actingAs($this->admin)->get('/admin/dashboard')->inertiaPage()['props'];
    $card = collect($props['workStats'])->firstWhere('key', 'overdue');

    $ids = collect($props['attention'])->where('kind', 'overdue')
        ->map(fn (array $row): int => (int) str_replace('overdue-', '', $row['id']));

    // The panel is a window onto the same query the card counted, so every row it shows is one
    // of the tasks the card's link opens.
    $listed = collect($this->actingAs($this->admin)->get($card['href'])->inertiaPage()['props']['tasks']['groups'])
        ->flatMap(fn (array $group): array => array_column($group['tasks'], 'id'));

    expect($ids)->not->toBeEmpty();

    foreach ($ids as $id) {
        expect($listed)->toContain($id);
    }
})->group('phase2');

/*
|--------------------------------------------------------------------------
| "Tasks by status"
|--------------------------------------------------------------------------
*/

it('breaks the open work down by status, over the same scoped set', function () {
    $slices = $this->actingAs($this->admin)->get('/admin/dashboard')
        ->inertiaPage()['props']['taskStatuses'];

    $open = array_values(array_filter(
        TaskStatus::boardOrder(),
        fn (TaskStatus $status): bool => $status->isOpen(),
    ));

    // Every open status, in the order the board draws its columns — including the ones sitting
    // at zero. Zero is an answer, and a breakdown whose slices come and go as the week does is
    // one whose legend cannot be read twice.
    expect(array_column($slices, 'key'))
        ->toBe(array_map(fn (TaskStatus $status): string => $status->value, $open));

    foreach ($slices as $slice) {
        expect($slice['count'])->toBe(
            (int) Task::query()->visibleTo($this->admin)->notArchived()->where('status', $slice['key'])->count(),
        )
            // The tone is the server's, so the ring agrees with the status badges rather than
            // re-deriving the mapping in Vue.
            ->and($slice['tone'])->toBe(TaskStatus::from($slice['key'])->tone());
    }

    // The centre of the donut reads "Open tasks", so the slices have to sum to exactly that.
    expect(array_sum(array_column($slices, 'count')))
        ->toBe((int) Task::query()->visibleTo($this->admin)->notArchived()->open()->count());
})->group('phase2');

it('counts no completed or cancelled work in the open breakdown', function () {
    $slices = $this->actingAs($this->admin)->get('/admin/dashboard')
        ->inertiaPage()['props']['taskStatuses'];

    expect(array_column($slices, 'key'))
        ->not->toContain(TaskStatus::Completed->value)
        ->not->toContain(TaskStatus::Cancelled->value);
})->group('phase2');

it('sends both blocks on every render, empty or not', function () {
    // They are always present and always arrays: a screen that had to guess whether a prop
    // existed would end up deciding for itself what an absent one means.
    $this->actingAs($this->admin)
        ->get('/admin/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Dashboard', false)
            ->has('attention')
            ->has('taskStatuses', 6)
            ->etc(),
        );
})->group('phase2');
