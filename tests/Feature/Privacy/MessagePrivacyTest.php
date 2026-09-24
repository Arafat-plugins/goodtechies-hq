<?php

use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\FileService;
use App\Services\MessageService;
use App\Support\Permission;
use App\Support\RoleName;
use App\Support\UserStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Messaging privacy: who is refused, and with which number
|--------------------------------------------------------------------------
|
| Part C's rule has two halves and this file is both of them:
|
|   - a route or capability a role may not use is **403**;
|   - a record they may not see is **absent**, which by id is **404** — they
|     never learn whether it existed.
|
| So the Accountant gets 403 on every messaging route (they hold no
| `messages.use`), and an employee asking for a project channel they are not
| in gets 404 on the same route somebody else gets 200 on.
|
| Constants here are global in Pest, so they are prefixed MPRIV_.
|
*/

const MPRIV_URL = '/messages';

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->conversations = app(ConversationService::class);
    $this->messages = app(MessageService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->tapusTask = Task::query()
        ->forEmployee($this->tapu->employee)
        ->notArchived()
        ->orderBy('id')
        ->firstOrFail();

    $this->tapusProject = Project::findOrFail($this->tapusTask->project_id);
    $this->projectChannel = $this->conversations->forProject($this->tapusProject);
    $this->dm = $this->conversations->dmBetween($this->tapu, $this->admin);

    $this->messages->post($this->tapu, $this->projectChannel, 'Only this project should read this.');
    $this->messages->post($this->admin, $this->dm, 'Only the two of us should read this.');
});

/*
|--------------------------------------------------------------------------
| The Accountant: 403 on every route, and nobody named them
|--------------------------------------------------------------------------
*/

it('refuses the accountant every messaging route', function (array $call) {
    [$method, $path] = $call;

    $this->actingAs($this->accountant)
        ->call($method, str_replace('{id}', (string) $this->conversations->team()->id, $path))
        ->assertForbidden();
})->with([
    'the page' => [['GET', MPRIV_URL]],
    'a thread' => [['GET', MPRIV_URL.'/{id}']],
    'posting' => [['POST', MPRIV_URL.'/{id}']],
    'marking read' => [['POST', MPRIV_URL.'/{id}/read']],
])->group('phase6');

it('refuses the accountant because of a permission and not a role', function () {
    // The whole of "the Accountant has no messaging routes", said the way this codebase says
    // everything: `messages.use` is the key, the route group is gated on it, and no policy,
    // controller or route file contains the word Accountant.
    expect($this->accountant->hasPermission(Permission::MessagesUse))->toBeFalse()
        ->and($this->tapu->hasPermission(Permission::MessagesUse))->toBeTrue();

    // Give it to them and the routes open, with no code change anywhere. That is the proof
    // that the rule is the key and not the person.
    DB::table('role_permissions')->insert([
        'role_id' => $this->accountant->employee->role_id,
        'permission_id' => DB::table('permissions')->where('key', Permission::MessagesUse->value)->value('id'),
    ]);

    $this->actingAs($this->accountant->fresh())->get(MPRIV_URL)->assertOk();
})->group('phase6');

/*
|--------------------------------------------------------------------------
| Somebody else's conversation is 404, never 403
|--------------------------------------------------------------------------
*/

it('answers 404 for a project channel somebody is not in', function () {
    // Yaseen is on neither of Tapu's SEO projects. He gets the same answer he gets for the
    // project itself, for its tasks and for its files: absent.
    $this->actingAs($this->yaseen)
        ->getJson(MPRIV_URL.'/'.$this->projectChannel->id)
        ->assertNotFound();

    $this->actingAs($this->yaseen)
        ->post(MPRIV_URL.'/'.$this->projectChannel->id, ['body' => 'Hello?'])
        ->assertNotFound();

    $this->actingAs($this->yaseen)
        ->post(MPRIV_URL.'/'.$this->projectChannel->id.'/read')
        ->assertNotFound();
})->group('phase6');

it('answers 404 for a DM between two other people, even to an admin', function () {
    // The Admin can see every project, every client and every task in the agency, and cannot
    // see two colleagues talking — because a DM's audience is the two columns on its row and
    // nothing else consults a role.
    $manager = Employee::factory()->forRole(RoleName::MANAGER)->create()->user;
    $theirs = $this->conversations->dmBetween($this->yaseen, $manager);

    $this->actingAs($this->admin)
        ->getJson(MPRIV_URL.'/'.$theirs->id)
        ->assertNotFound();

    $this->actingAs($this->yaseen)
        ->getJson(MPRIV_URL.'/'.$this->dm->id)
        ->assertNotFound();
})->group('phase6');

it('never leaks another conversation body into the inbox', function () {
    // The list rows carry an excerpt of the last thing said, so the list itself has to be
    // policy-checked row by row — a row for a conversation you may not open would print a
    // sentence from it.
    $page = $this->actingAs($this->yaseen)->get(MPRIV_URL)->assertOk();

    $page->assertDontSee('Only this project should read this.')
        ->assertDontSee('Only the two of us should read this.');

    $ids = array_column($page->inertiaPage()['props']['conversations'], 'id');

    expect($ids)->not->toContain($this->projectChannel->id)
        ->not->toContain($this->dm->id);
})->group('phase6');

/*
|--------------------------------------------------------------------------
| The rule closes when access changes, with no sync having run
|--------------------------------------------------------------------------
*/

it('closes a project channel the moment somebody leaves the project', function () {
    $this->actingAs($this->tapu)->getJson(MPRIV_URL.'/'.$this->projectChannel->id)->assertOk();

    // Off the project. Nothing touched the conversation and nothing synced a membership list.
    $this->tapusProject->members()->detach($this->tapu->employee->getKey());

    $this->actingAs($this->tapu->fresh())
        ->getJson(MPRIV_URL.'/'.$this->projectChannel->id)
        ->assertNotFound();
})->group('phase6');

it('buys a stale membership row exactly nothing, in a channel and in a DM', function () {
    // Decision 2-24's test, now covering Phase 6's types. A row of exactly the shape a
    // departure leaves behind holds a timestamp and grants its holder no access at all.
    foreach ([$this->projectChannel, $this->dm] as $conversation) {
        $conversation->members()->attach($this->yaseen->getKey(), ['last_read_at' => now()]);
    }

    expect(DB::table('conversation_members')->where('user_id', $this->yaseen->id)->count())->toBe(2);

    $this->actingAs($this->yaseen)
        ->getJson(MPRIV_URL.'/'.$this->projectChannel->id)
        ->assertNotFound();

    $this->actingAs($this->yaseen)
        ->getJson(MPRIV_URL.'/'.$this->dm->id)
        ->assertNotFound();
})->group('phase6');

it('shuts a deactivated person out of every conversation they were in', function () {
    $this->tapu->forceFill(['status' => UserStatus::Inactive->value])->save();

    // `isActive()` is the first line of every ability in ConversationPolicy, so the DM they
    // were half of is closed to them too — the one type where the "membership" is a column
    // with their own id in it.
    expect($this->tapu->fresh()->can('view', $this->dm->fresh()))->toBeFalse()
        ->and($this->tapu->fresh()->can('view', $this->projectChannel->fresh()))->toBeFalse()
        ->and($this->tapu->fresh()->can('view', $this->conversations->team()))->toBeFalse();
})->group('phase6');

/*
|--------------------------------------------------------------------------
| An attachment is as visible as the conversation it is in
|--------------------------------------------------------------------------
*/

it('refuses a forwarded attachment link from a conversation somebody may not read', function () {
    // Decision 2-25: a signed link is a policy check, not a bearer token. The link is perfectly
    // valid and it is 404 for somebody who may not see the owning record.
    $message = $this->messages->post(
        $this->tapu,
        $this->projectChannel,
        'The crawl.',
        UploadedFile::fake()->create('crawl.pdf', 10, 'application/pdf'),
    );

    $url = app(FileService::class)->url($message->attachments->first());

    $this->actingAs($this->tapu)->get($url)->assertOk();
    $this->actingAs($this->yaseen)->get($url)->assertNotFound();
})->group('phase6');
