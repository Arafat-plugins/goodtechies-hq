<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\ProjectService;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| An archived project's channel is put away with it
|--------------------------------------------------------------------------
|
| Out of the Messages rail and out of the shell's unread total; still readable by its own URL;
| back in both the moment the project is unarchived.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $this->project = Project::factory()->create(['name' => 'sdfsdf']);
    $this->channel = app(ConversationService::class)->forProject($this->project);

    // One unread message for the Admin in the channel.
    Message::factory()->inConversation($this->channel)->by($this->yaseen)->create();
});

/** @return list<int> */
function APC_railIds(object $test): array
{
    $props = $test->actingAs($test->admin)->get('/messages')->assertOk()->inertiaPage()['props'];

    return array_map(fn (array $row): int => $row['id'], $props['conversations']);
}

/** @return array<string, mixed> */
function APC_shell(object $test): array
{
    return $test->actingAs($test->admin)->get('/admin/dashboard', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'Admin/Dashboard',
        'X-Inertia-Partial-Data' => 'shell',
    ])->assertOk()->json('props.shell');
}

it('lists an active project channel in the rail and counts it in the unread total', function () {
    $shell = APC_shell($this);

    expect(APC_railIds($this))->toContain($this->channel->id)
        ->and($shell['unreadByConversation'])->toHaveKey((string) $this->channel->id)
        ->and($shell['unreadMessages'])->toBeGreaterThanOrEqual(1);
})->group('messaging');

it('drops an archived project channel from the rail and the unread total, and brings it back on unarchive', function () {
    $before = APC_shell($this)['unreadMessages'];

    app(ProjectService::class)->archive($this->admin, $this->project);

    $shell = APC_shell($this);

    expect(APC_railIds($this))->not->toContain($this->channel->id)
        ->and($shell['unreadByConversation'])->not->toHaveKey((string) $this->channel->id)
        ->and($shell['unreadMessages'])->toBe($before - 1);

    // Still readable by its own URL.
    $this->actingAs($this->admin)->getJson('/messages/'.$this->channel->id.'?read=0')->assertOk();

    app(ProjectService::class)->unarchive($this->admin, $this->project->fresh());

    $shell = APC_shell($this);

    expect(APC_railIds($this))->toContain($this->channel->id)
        ->and($shell['unreadByConversation'])->toHaveKey((string) $this->channel->id)
        ->and($shell['unreadMessages'])->toBe($before);
})->group('messaging');
