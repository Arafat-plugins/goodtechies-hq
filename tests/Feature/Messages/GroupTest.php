<?php

use App\Events\InboxActivity;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\User;
use App\Services\GroupService;
use App\Services\MessageService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Message groups (decision 12-81)
|--------------------------------------------------------------------------
|
| A holder of `messages.manage` creates a named group of chosen people, with a picture, and
| later renames it, changes the picture, and adds or removes people. Members see and post in it
| like any channel; everybody else gets 404 and does not see it in their inbox.
|
| Helpers are global in Pest, so everything here is prefixed GROUP_.
|
*/

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->faruk = User::where('email', 'faruk@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
});

/**
 * Shahadat's group with Yaseen and Tapu, made through the service.
 */
function GROUP_make(object $test, bool $withAvatar = false): Conversation
{
    return app(GroupService::class)->create(
        $test->admin,
        'Design crew',
        [$test->yaseen->id, $test->tapu->id],
        $withAvatar ? UploadedFile::fake()->image('g.png', 200, 200) : null,
    );
}

/**
 * The Messages page's inbox rows, as a partial Inertia reload.
 *
 * @return list<array<string, mixed>>
 */
function GROUP_inbox(object $test, User $user): array
{
    return $test->actingAs($user)
        ->get('/messages', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Partial-Component' => 'Shared/Messages',
            'X-Inertia-Partial-Data' => 'conversations',
        ])
        ->assertOk()
        ->json('props.conversations');
}

it('lets an Admin create a group with chosen people and a picture, audited', function () {
    $response = $this->actingAs($this->admin)
        ->postJson('/messages/groups', [
            'name' => '  Design crew  ',
            'member_ids' => [$this->yaseen->id, $this->tapu->id],
            'avatar' => UploadedFile::fake()->image('g.png', 200, 200),
        ])
        ->assertCreated();

    $group = Conversation::findOrFail($response->json('conversation_id'));

    expect($group->type->value)->toBe('group')
        ->and($group->title)->toBe('Design crew')
        ->and((int) $group->created_by)->toBe($this->admin->id)
        ->and($group->avatar_path)->toStartWith('group-avatars/'.$group->id.'-');

    Storage::disk(config('filesystems.default'))->assertExists($group->avatar_path);

    $members = DB::table('conversation_group_members')->where('conversation_id', $group->id)->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

    expect($members)->toBe(collect([$this->admin->id, $this->yaseen->id, $this->tapu->id])->sort()->values()->all())
        ->and(AuditLog::where('event', 'message_group.created')->where('target_id', $group->id)->count())->toBe(1);
});

it('refuses an Employee who tries to create a group', function () {
    $this->actingAs($this->yaseen)
        ->postJson('/messages/groups', ['name' => 'Mine', 'member_ids' => [$this->tapu->id]])
        ->assertForbidden();

    expect(Conversation::where('type', 'group')->count())->toBe(0);
});

it('shows a member the group in the inbox and lets them post in it', function () {
    $group = GROUP_make($this, true);

    $row = collect(GROUP_inbox($this, $this->yaseen))->firstWhere('id', $group->id);

    expect($row)->not->toBeNull()
        ->and($row['type'])->toBe('group')
        ->and($row['label'])->toBe('Design crew')
        ->and($row['group'])->toBe('Groups')
        ->and($row['avatar_url'])->toStartWith('/messages/groups/'.$group->id.'/avatar')
        ->and($row['member_count'])->toBe(3);

    $team = collect(GROUP_inbox($this, $this->yaseen))->firstWhere('type', 'team');

    expect($team['avatar_url'])->toBeNull()
        ->and($team['member_count'])->toBeNull();

    $this->actingAs($this->yaseen)
        ->postJson('/messages/'.$group->id, ['body' => 'Hello crew'])
        ->assertCreated();

    $thread = $this->actingAs($this->yaseen)->getJson('/messages/'.$group->id)->assertOk();

    expect($thread->json('group.name'))->toBe('Design crew')
        ->and($thread->json('group.can_manage'))->toBeFalse()
        ->and(collect($thread->json('group.members'))->pluck('name')->all())
        ->toBe(collect([$this->admin, $this->tapu, $this->yaseen])->pluck('name')->sort()->values()->all());

    $context = $this->actingAs($this->yaseen)->getJson('/messages/'.$group->id.'/context')->assertOk();

    expect(collect($context->json('members'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->admin->id, $this->tapu->id, $this->yaseen->id])->sort()->values()->all());
});

it('hides the group from a non-member: 404 by id, absent from the inbox', function () {
    $group = GROUP_make($this);

    $this->actingAs($this->faruk)->getJson('/messages/'.$group->id)->assertNotFound();
    $this->actingAs($this->faruk)->getJson('/messages/'.$group->id.'/context')->assertNotFound();
    $this->actingAs($this->faruk)->postJson('/messages/groups/'.$group->id, ['name' => 'Taken'])->assertNotFound();

    expect(collect(GROUP_inbox($this, $this->faruk))->firstWhere('id', $group->id))->toBeNull();
});

it('serves the picture to members and 404s everybody else', function () {
    $group = GROUP_make($this, true);

    $response = $this->actingAs($this->tapu)->get('/messages/groups/'.$group->id.'/avatar')->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('image/png')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=86400')
        ->and($response->headers->get('Cache-Control'))->toContain('private');

    $this->actingAs($this->faruk)->get('/messages/groups/'.$group->id.'/avatar')->assertNotFound();

    $bare = GROUP_make($this);
    $this->actingAs($this->tapu)->get('/messages/groups/'.$bare->id.'/avatar')->assertNotFound();
});

it('renames the group and replaces its picture, deleting the old file', function () {
    $group = GROUP_make($this, true);
    $old = $group->avatar_path;

    $response = $this->actingAs($this->admin)
        ->postJson('/messages/groups/'.$group->id, [
            'name' => 'Design guild',
            'avatar' => UploadedFile::fake()->image('new.png', 100, 100),
        ])
        ->assertOk()
        ->assertJsonPath('group.name', 'Design guild')
        ->assertJsonPath('group.can_manage', true);

    $group->refresh();

    expect($group->avatar_path)->not->toBe($old)
        ->and($response->json('group.avatar_url'))->not->toBeNull()
        ->and(AuditLog::where('event', 'message_group.updated')->count())->toBe(1);

    Storage::disk(config('filesystems.default'))->assertMissing($old);
    Storage::disk(config('filesystems.default'))->assertExists($group->avatar_path);

    $this->actingAs($this->admin)
        ->postJson('/messages/groups/'.$group->id, ['remove_avatar' => true])
        ->assertOk()
        ->assertJsonPath('group.avatar_url', null);

    Storage::disk(config('filesystems.default'))->assertMissing($group->avatar_path);
});

it('lets an added member open the group and a removed one no longer', function () {
    $group = GROUP_make($this);

    $this->actingAs($this->faruk)->getJson('/messages/'.$group->id)->assertNotFound();

    $this->actingAs($this->admin)
        ->postJson('/messages/groups/'.$group->id.'/members', ['user_ids' => [$this->faruk->id]])
        ->assertOk()
        ->assertJsonCount(4, 'group.members');

    $this->actingAs($this->faruk)->getJson('/messages/'.$group->id)->assertOk();

    $this->actingAs($this->admin)
        ->deleteJson('/messages/groups/'.$group->id.'/members/'.$this->tapu->id)
        ->assertOk()
        ->assertJsonCount(3, 'group.members');

    $this->actingAs($this->tapu)->getJson('/messages/'.$group->id)->assertNotFound();

    expect(AuditLog::where('event', 'message_group.members_changed')->count())->toBe(2);
});

it('refuses to remove the last member', function () {
    $group = GROUP_make($this);

    $this->actingAs($this->admin)->deleteJson('/messages/groups/'.$group->id.'/members/'.$this->tapu->id)->assertOk();
    $this->actingAs($this->admin)->deleteJson('/messages/groups/'.$group->id.'/members/'.$this->yaseen->id)->assertOk();

    $this->actingAs($this->admin)
        ->deleteJson('/messages/groups/'.$group->id.'/members/'.$this->admin->id)
        ->assertUnprocessable();

    expect(DB::table('conversation_group_members')->where('conversation_id', $group->id)->count())->toBe(1);
});

it('refuses to add somebody who cannot use messaging', function () {
    $group = GROUP_make($this);

    $this->actingAs($this->admin)
        ->postJson('/messages/groups/'.$group->id.'/members', ['user_ids' => [$this->accountant->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_ids');

    $this->actingAs($this->admin)
        ->postJson('/messages/groups', ['name' => 'With books', 'member_ids' => [$this->accountant->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('member_ids');

    expect(DB::table('conversation_group_members')->where('user_id', $this->accountant->id)->count())->toBe(0);
});

it('rings exactly the other members when somebody posts in a group', function () {
    $group = GROUP_make($this);

    Event::fake([InboxActivity::class]);

    app(MessageService::class)->post($this->yaseen, $group, 'Ringing the crew.');

    Event::assertDispatchedTimes(InboxActivity::class, 1);

    $event = Event::dispatched(InboxActivity::class)->first()[0];
    $channels = array_map(fn ($channel): string => (string) $channel, $event->broadcastOn());
    sort($channels);

    $expected = ['private-notifications.'.$this->admin->id, 'private-notifications.'.$this->tapu->id];
    sort($expected);

    expect($channels)->toBe($expected);
});

it('tells the Messages page whether the viewer may manage groups', function () {
    $prop = fn (User $user) => $this->actingAs($user)
        ->get('/messages', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->assertOk()
        ->json('props.can_manage_groups');

    expect($prop($this->admin))->toBeTrue()
        ->and($prop($this->yaseen))->toBeFalse();
});
