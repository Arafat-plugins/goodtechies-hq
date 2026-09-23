<?php

use App\Models\Notification;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotificationType;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The bell and the Notification Center, over HTTP
|--------------------------------------------------------------------------
|
| Four routes, one gate, and one privacy rule stated twice:
|
|   - a notification belongs to exactly ONE user, so somebody else's id is
|     404 and never 403 (Part C §1 — a record you may not see is absent);
|   - the Accountant reaches none of these routes at all, and is refused by
|     the catalogue rather than by being named: every Phase 2 notification
|     type requires `tasks.view`, and they hold no tasks.* key.
|
| The reads are JSON because the bell polls them; the writes redirect back,
| like every other write in Phase 2. The screens arrive in the next dispatch.
|
*/

beforeEach(function () {
    $this->seed();

    $this->notifications = app(NotificationService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->task = Task::query()->forEmployee($this->tapu->employee)->notArchived()->orderBy('id')->firstOrFail();

    Notification::query()->delete();
});

/*
|--------------------------------------------------------------------------
| The bell
|--------------------------------------------------------------------------
*/

it('answers the bell with an unread count and the newest few', function () {
    $this->notifications->notify(NotificationType::TaskAssigned, $this->task, [$this->tapu], ['title' => $this->task->title]);
    $this->travel(5)->minutes();
    $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->tapu], ['title' => $this->task->title]);

    $response = $this->actingAs($this->tapu)->getJson('/notifications/recent');

    $response->assertOk()
        ->assertJsonPath('unread_count', 2)
        ->assertJsonCount(2, 'notifications')
        // Newest first.
        ->assertJsonPath('notifications.0.type', NotificationType::TaskCommented->value)
        ->assertJsonPath('notifications.0.count', 1)
        ->assertJsonPath('notifications.0.is_read', false);
})->group('phase2');

it('sends a deep link built for the reader\'s own surface', function () {
    $this->notifications->notify(
        NotificationType::TaskCommented,
        $this->task,
        [$this->tapu, $this->admin],
        ['title' => $this->task->title],
    );

    $this->actingAs($this->tapu)->getJson('/notifications/recent')
        ->assertJsonPath('notifications.0.link', url('/employee/tasks/'.$this->task->id));

    // The same notification about the same task, read by an Admin, points at the Admin screen.
    $this->actingAs($this->admin)->getJson('/notifications/recent')
        ->assertJsonPath('notifications.0.link', url('/admin/tasks/'.$this->task->id));
})->group('phase2');

it('sends the grouped wording, not the raw payload', function () {
    foreach (range(1, 12) as $i) {
        $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->tapu], ['title' => $this->task->title]);
    }

    $this->actingAs($this->tapu)->getJson('/notifications/recent')
        ->assertOk()
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.count', 12)
        ->assertJsonPath('notifications.0.summary', sprintf('12 new comments in "%s"', $this->task->title))
        // The storage format never leaves the server.
        ->assertJsonMissingPath('notifications.0.payload')
        ->assertJsonMissingPath('notifications.0.group_key');
})->group('phase2');

/*
|--------------------------------------------------------------------------
| The Center
|--------------------------------------------------------------------------
*/

it('lists every tab and fakes none of the four that have no types yet', function () {
    $project = Project::where('name', 'Buffalo Modular — SEO')->firstOrFail();

    $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->admin], ['title' => 'a task']);
    $this->notifications->notify(NotificationType::ProjectCancelled, $project, [$this->admin], ['title' => 'a project']);

    $response = $this->actingAs($this->admin)->getJson('/notifications');

    $response->assertOk()
        ->assertJsonPath('tab', 'all')
        ->assertJsonCount(2, 'notifications')
        ->assertJsonPath('unread_count', 2);

    $tabs = collect($response->json('tabs'))->keyBy('key');

    expect($tabs->keys()->all())->toBe(['all', 'tasks', 'messages', 'meetings', 'leave', 'payroll', 'system'])
        ->and($tabs['tasks']['is_built'])->toBeTrue()
        ->and($tabs['system']['is_built'])->toBeTrue()
        ->and($tabs['meetings']['is_built'])->toBeFalse()
        ->and($tabs['payroll']['unread_count'])->toBe(0)
        ->and($tabs['tasks']['unread_count'])->toBe(1)
        ->and($tabs['system']['unread_count'])->toBe(1);

    // A tab narrows the list, and an unbuilt one is genuinely empty rather than filled with
    // something borrowed from another tab.
    $this->actingAs($this->admin)->getJson('/notifications?tab=system')
        ->assertOk()
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.type', NotificationType::ProjectCancelled->value);

    $this->actingAs($this->admin)->getJson('/notifications?tab=payroll')
        ->assertOk()
        ->assertJsonCount(0, 'notifications');
})->group('phase2');

it('falls back to All rather than erroring on a tab that does not exist', function () {
    $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->admin], ['title' => 'a task']);

    $this->actingAs($this->admin)->getJson('/notifications?tab=telepathy')
        ->assertOk()
        ->assertJsonPath('tab', 'all')
        ->assertJsonCount(1, 'notifications');
})->group('phase2');

it('answers the same list as a page to a browser and as JSON to anything else', function () {
    $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->admin], ['title' => 'a task']);

    // A browser gets the Notification Center. There is no second route and no second
    // controller: the Center IS this list, and the tab is the query parameter it already took.
    $this->actingAs($this->admin)->get('/notifications?tab=tasks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Shared/Notifications')
            ->where('tab', 'tasks')
            ->has('tabs', 7)
            ->has('notifications', 1)
            ->where('unread_count', 1)
            // The two blocks Pagination.vue takes, in the shape every other list here sends.
            ->has('links.prev')
            ->has('meta.current_page')
            ->has('meta.total'),
        );

    // And the contract the bell, the tests and the permission matrix were written against is
    // untouched for anybody who asks for JSON.
    $this->actingAs($this->admin)->getJson('/notifications?tab=tasks')
        ->assertOk()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonPath('tab', 'tasks')
        ->assertJsonCount(1, 'notifications');
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Marking read
|--------------------------------------------------------------------------
*/

it('marks one read and redirects back', function () {
    $row = $this->notifications->notify(NotificationType::TaskAssigned, $this->task, [$this->tapu], [])->first();

    $this->actingAs($this->tapu)->post('/notifications/'.$row->id.'/read')->assertRedirect();

    expect($row->fresh()->is_read)->toBeTrue()
        ->and($row->fresh()->read_at)->not->toBeNull()
        ->and($this->notifications->unreadCount($this->tapu))->toBe(0);
})->group('phase2');

it('answers 404 — never 403 — for somebody else\'s notification', function () {
    $tapus = $this->notifications->notify(NotificationType::TaskAssigned, $this->task, [$this->tapu], [])->first();

    // An Admin who can see everything else in the agency cannot see one line of somebody
    // else's mail, and is not told it exists.
    $this->actingAs($this->admin)->post('/notifications/'.$tapus->id.'/read')->assertNotFound();
    $this->actingAs($this->yaseen)->post('/notifications/'.$tapus->id.'/read')->assertNotFound();

    expect($tapus->fresh()->is_read)->toBeFalse();
})->group('phase2');

it('marks all read for the caller and nobody else', function () {
    $this->notifications->notify(NotificationType::TaskAssigned, $this->task, [$this->tapu, $this->admin], []);
    $this->travel(5)->minutes();
    $this->notifications->notify(NotificationType::TaskCommented, $this->task, [$this->tapu, $this->admin], []);

    $this->actingAs($this->tapu)->post('/notifications/read-all')->assertRedirect();

    expect($this->notifications->unreadCount($this->tapu))->toBe(0)
        ->and($this->notifications->unreadCount($this->admin))->toBe(2)
        // A read notification is still in the list; the bell is not a queue that empties.
        ->and($this->actingAs($this->tapu)->getJson('/notifications/recent')->json('notifications'))->toHaveCount(2);
})->group('phase2');

/*
|--------------------------------------------------------------------------
| Who may reach these at all
|--------------------------------------------------------------------------
*/

it('refuses the Accountant every notification route', function () {
    $row = $this->notifications->notify(NotificationType::TaskAssigned, $this->task, [$this->tapu], [])->first();

    // They hold no tasks.* key, and every Phase 2 notification type requires one — so there is
    // no kind of mail they could receive and no mailbox to open. 403, because this is about
    // their role and not about a record.
    expect($this->accountant->hasPermission(NotificationType::TaskAssigned->requires()))->toBeFalse();

    $this->actingAs($this->accountant)->get('/notifications')->assertForbidden();
    $this->actingAs($this->accountant)->get('/notifications/recent')->assertForbidden();
    $this->actingAs($this->accountant)->post('/notifications/read-all')->assertForbidden();
    $this->actingAs($this->accountant)->post('/notifications/'.$row->id.'/read')->assertForbidden();
})->group('phase2');

it('sends a guest to the login page', function () {
    $this->get('/notifications')->assertRedirect('/login');
    $this->get('/notifications/recent')->assertRedirect('/login');
    $this->post('/notifications/read-all')->assertRedirect('/login');
})->group('phase2');
