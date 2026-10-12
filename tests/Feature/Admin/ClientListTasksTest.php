<?php

use App\Models\Client;
use App\Models\User;
use App\Support\ClientTaskCounts;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Polish 049: the Clients list shows each client's tasks as To do / In progress / Complete with
| their total, its projects, primary contact and last activity, and filters by open tasks; a
| client's page gets the same counts per project and for the client.
*/

beforeEach(function () {
    $this->seed();
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
});

function expectedCounts(int $clientId): array
{
    $rows = DB::table('tasks')->join('projects', 'projects.id', '=', 'tasks.project_id')
        ->where('projects.client_id', $clientId)->whereNull('tasks.deleted_at')->pluck('tasks.status');

    $todo = $rows->filter(fn ($s) => in_array($s, ClientTaskCounts::TODO, true))->count();
    $progress = $rows->filter(fn ($s) => in_array($s, ClientTaskCounts::PROGRESS, true))->count();
    $complete = $rows->filter(fn ($s) => in_array($s, ClientTaskCounts::COMPLETE, true))->count();

    return ['todo' => $todo, 'progress' => $progress, 'complete' => $complete, 'total' => $todo + $progress + $complete];
}

it('lists each client with task counts, project chips and last activity', function () {
    $client = Client::query()->whereHas('projects')->orderBy('name')->firstOrFail();

    $rows = $this->actingAs($this->admin)->get(route('admin.clients.index'))
        ->assertOk()
        ->inertiaProps()['clients']['data'];

    $row = collect($rows)->firstWhere('id', $client->id);

    expect($row['task_counts'])->toBe(expectedCounts($client->id))
        ->and($row['project_chips'])->toHaveCount($client->projects()->count())
        ->and($row['last_activity_at'])->not->toBeNull();
});

it('filters clients by open tasks', function () {
    $empty = Client::factory()->create(['name' => 'Zed No Tasks']);

    $open = collect($this->actingAs($this->admin)->get(route('admin.clients.index', ['tasks' => 'open']))
        ->inertiaProps()['clients']['data'])->pluck('id');
    $none = collect($this->actingAs($this->admin)->get(route('admin.clients.index', ['tasks' => 'none']))
        ->inertiaProps()['clients']['data'])->pluck('id');

    expect($open)->not->toContain($empty->id)
        ->and($none)->toContain($empty->id)
        ->and($open->intersect($none))->toBeEmpty();
});

it('gives a client page the counts per project and for the client', function () {
    $client = Client::query()->whereHas('projects')->firstOrFail();

    $this->actingAs($this->admin)->get(route('admin.clients.show', $client))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Clients/Show')
            ->where('taskCounts', expectedCounts($client->id))
            ->has('projectTasks'));
});

it('filters clients by having projects and finds a client by a contact email', function () {
    $empty = Client::factory()->create(['name' => 'Zed Empty', 'contact_info' => [['name' => 'Zoe', 'role' => 'Owner', 'email' => 'zoe@zedempty.test', 'phone' => '']]]);

    $with = collect($this->actingAs($this->admin)->get(route('admin.clients.index', ['projects' => 'with']))
        ->inertiaProps()['clients']['data'])->pluck('id');
    $without = collect($this->actingAs($this->admin)->get(route('admin.clients.index', ['projects' => 'without']))
        ->inertiaProps()['clients']['data'])->pluck('id');
    $byEmail = collect($this->actingAs($this->admin)->get(route('admin.clients.index', ['search' => 'ZOE@zedempty']))
        ->inertiaProps()['clients']['data'])->pluck('id');

    expect($with)->not->toContain($empty->id)
        ->and($without)->toContain($empty->id)
        ->and($byEmail->all())->toBe([$empty->id]);
});
