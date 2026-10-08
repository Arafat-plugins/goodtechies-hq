<?php

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\ProjectServiceBoxes;
use App\Support\ProjectType;
use Illuminate\Support\Facades\DB;

/*
| Polish 033: Admin → Projects by client. The sidebar lists the clients with their project
| counts; `?client=` shows that client's projects in service boxes (the Admin's own list,
| `project_service_boxes`), each project with its OPEN tasks, and an overview of the time spent
| per box and per task.
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->project = Project::query()->whereNotNull('client_id')->whereNull('archived_at')->whereHas('tasks')->firstOrFail();
});

it('counts every client\'s projects in the sidebar tree', function () {
    $clients = $this->actingAs($this->admin)->getJson('/admin/projects/tree')->assertOk()->json('clients');
    $row = collect($clients)->firstWhere('id', $this->project->client_id);

    expect($row['project_count'])->toBe(Project::query()->where('client_id', $this->project->client_id)->whereNull('archived_at')->count());
});

it('sorts a client\'s projects into the default boxes with their open tasks only', function () {
    $this->project->update(['project_type' => ProjectType::WooCommerce]);

    $done = Task::query()->where('project_id', $this->project->id)->whereNull('parent_id')->firstOrFail();
    // Straight to the table: the status machine is not what this test is about.
    DB::table('tasks')->where('id', $done->id)->update(['status' => 'completed', 'tracked_seconds' => 5400]);

    $props = $this->actingAs($this->admin)
        ->get("/admin/projects?client={$this->project->client_id}")
        ->assertOk()
        ->inertiaProps();

    $board = $props['clientBoard'];
    $development = collect($board['boxes'])->firstWhere('name', 'Development');
    $card = collect($development['projects'])->firstWhere('id', $this->project->id);

    expect($board['client']['id'])->toBe($this->project->client_id)
        ->and(collect($board['boxes'])->pluck('projects')->flatten(1)->every(fn ($project) => $project !== []))->toBeTrue()
        // WooCommerce is in the Development box by default; the done task is not listed …
        ->and(collect($card['tasks'])->pluck('id'))->not->toContain($done->id)
        // … but its time still counts in the overview.
        ->and(collect($board['overview']['tasks'])->firstWhere('id', $done->id)['tracked_seconds'])->toBe(5400)
        ->and($props['serviceBoxes']['boxes'])->toBe(ProjectServiceBoxes::DEFAULT);
});

it('hides boxes the client has no project in', function () {
    $props = $this->actingAs($this->admin)->get("/admin/projects?client={$this->project->client_id}")->inertiaProps();

    expect(collect($props['clientBoard']['boxes'])->every(fn ($box) => count($box['projects']) > 0))->toBeTrue();
});

it('lets the Admin add a box and move a type into it, audited', function () {
    $this->project->update(['project_type' => ProjectType::WooCommerce]);

    $this->actingAs($this->admin)
        ->put('/admin/project-service-boxes', ['boxes' => [
            ['name' => 'Development', 'types' => ['website_development', 'web_application']],
            ['name' => 'Shops', 'types' => ['woocommerce']],
        ]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(AuditLog::where('event', AuditEvent::ConfigurationChanged->value)->count())->toBe(1);

    $board = $this->actingAs($this->admin)->get("/admin/projects?client={$this->project->client_id}")->inertiaProps()['clientBoard'];

    expect(collect(collect($board['boxes'])->firstWhere('name', 'Shops')['projects'])->pluck('id'))->toContain($this->project->id);
});

it('puts a type in no box into "Other"', function () {
    $this->project->update(['project_type' => ProjectType::Marketing]);

    $this->actingAs($this->admin)->put('/admin/project-service-boxes', ['boxes' => [['name' => 'SEO', 'types' => ['seo']]]]);

    $board = $this->actingAs($this->admin)->get("/admin/projects?client={$this->project->client_id}")->inertiaProps()['clientBoard'];

    expect(collect(collect($board['boxes'])->firstWhere('key', 'other')['projects'])->pluck('id'))->toContain($this->project->id);
});

it('refuses a type in two boxes, a blank name and an unknown type', function () {
    $this->actingAs($this->admin)
        ->put('/admin/project-service-boxes', ['boxes' => [
            ['name' => 'A', 'types' => ['seo']],
            ['name' => '', 'types' => ['seo', 'crm']],
        ]])
        ->assertSessionHasErrors(['boxes.1.name', 'boxes.1.types', 'boxes.1.types.1']);
});

it('does not let an employee edit the boxes', function () {
    $this->actingAs($this->yaseen)
        ->put('/admin/project-service-boxes', ['boxes' => [['name' => 'Mine', 'types' => []]]])
        ->assertForbidden();
});

it('shows nothing for a client the viewer cannot see or that does not exist', function () {
    $this->actingAs($this->admin)
        ->get('/admin/projects?client=999999')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('clientBoard', null));

    $this->actingAs($this->admin)
        ->get('/admin/projects?client=abc')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('clientBoard', null));
});
