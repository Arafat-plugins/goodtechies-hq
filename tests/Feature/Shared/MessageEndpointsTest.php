<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\MessageService;
use App\Support\ConversationType;
use App\Support\UserStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The Messages endpoints
|--------------------------------------------------------------------------
|
| Five shared routes, because whose mail a thread is belongs to the person and
| not to the shell they are in — the same reasoning that put /notifications,
| /attendance and /leave in routes/shared.php.
|
| Constants here are global in Pest, so they are prefixed MSGX_.
|
*/

const MSGX_URL = '/messages';

beforeEach(function () {
    $this->seed();
    Storage::fake(config('filesystems.default'));

    $this->conversations = app(ConversationService::class);
    $this->messages = app(MessageService::class);

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->team = $this->conversations->team();

    $this->tapusTask = Task::query()
        ->forEmployee($this->tapu->employee)
        ->notArchived()
        ->orderBy('id')
        ->firstOrFail();

    $this->tapusProject = Project::findOrFail($this->tapusTask->project_id);
});

/*
|--------------------------------------------------------------------------
| The page
|--------------------------------------------------------------------------
*/

it('renders the page with every conversation this person may open', function () {
    $dm = $this->conversations->dmBetween($this->tapu, $this->admin);

    $props = $this->actingAs($this->tapu)->get(MSGX_URL)->assertOk()->inertiaPage()['props'];

    $ids = array_column($props['conversations'], 'id');

    expect($ids)->toContain($this->team->id)
        ->toContain($dm->id)
        ->toContain($this->conversations->forProject($this->tapusProject)->id)
        // A task discussion is read inside its task and is never in the inbox.
        ->and(array_column($props['conversations'], 'type'))->not->toContain('task');
})->group('phase6');

it('opens the team channel when nothing is asked for', function () {
    $props = $this->actingAs($this->tapu)->get(MSGX_URL)->assertOk()->inertiaPage()['props'];

    expect($props['active']['conversation_id'])->toBe($this->team->id)
        ->and($props['active']['type'])->toBe('team');
})->group('phase6');

it('opens the conversation the query string names', function () {
    $channel = $this->conversations->forProject($this->tapusProject);

    $props = $this->actingAs($this->tapu)
        ->get(MSGX_URL.'?conversation='.$channel->id)
        ->assertOk()
        ->inertiaPage()['props'];

    expect($props['active']['conversation_id'])->toBe($channel->id)
        ->and($props['active']['label'])->toBe($this->tapusProject->name);
})->group('phase6');

it('falls back to the team channel when the query string names one they may not open', function () {
    // A bookmark to a project somebody has since left. That is not an error screen: the page
    // opens on the channel everybody can read, rather than 404ing a whole screen because one
    // query parameter went stale.
    $channel = $this->conversations->forProject($this->tapusProject);

    $props = $this->actingAs($this->yaseen)
        ->get(MSGX_URL.'?conversation='.$channel->id)
        ->assertOk()
        ->inertiaPage()['props'];

    expect($props['active']['conversation_id'])->toBe($this->team->id);
})->group('phase6');

it('names a DM after the other person, from each side', function () {
    $dm = $this->conversations->dmBetween($this->tapu, $this->admin);

    $forTapu = $this->actingAs($this->tapu)
        ->getJson(MSGX_URL.'/'.$dm->id)->assertOk()->json('label');
    $forAdmin = $this->actingAs($this->admin)
        ->getJson(MSGX_URL.'/'.$dm->id)->assertOk()->json('label');

    expect($forTapu)->toBe($this->admin->name)
        ->and($forAdmin)->toBe($this->tapu->name);
})->group('phase6');

/*
|--------------------------------------------------------------------------
| The thread payload
|--------------------------------------------------------------------------
*/

it('sends exactly the documented thread keys', function () {
    $this->messages->post($this->admin, $this->team, 'Morning.');

    $payload = $this->actingAs($this->tapu)
        ->getJson(MSGX_URL.'/'.$this->team->id)
        ->assertOk()
        ->json();

    expect(array_keys($payload))->toEqualCanonicalizing([
        'conversation_id', 'type', 'label', 'messages', 'has_more', 'can_post',
        'mentionable', 'last_read_at', 'unread_count',
    ])
        ->and(array_keys($payload['messages'][0]))->toEqualCanonicalizing([
            'id', 'body', 'author', 'is_mine', 'created_at', 'attachments',
            'mentions', 'mentions_me',
        ])
        // The picker's options never include the reader themselves, and never the Accountant.
        ->and(array_column($payload['mentionable'], 'id'))
        ->not->toContain($this->tapu->id)
        ->not->toContain($this->accountant->id);
})->group('phase6');

it('resolves mentions_me on the server, per reader', function () {
    $this->messages->post(
        $this->admin,
        $this->team,
        '@'.$this->tapu->name.' one for you',
        mentionIds: [$this->tapu->id],
    );

    $forTapu = $this->actingAs($this->tapu)
        ->getJson(MSGX_URL.'/'.$this->team->id)->assertOk()->json('messages.0');
    $forYaseen = $this->actingAs($this->yaseen)
        ->getJson(MSGX_URL.'/'.$this->team->id)->assertOk()->json('messages.0');

    expect($forTapu['mentions_me'])->toBeTrue()
        ->and($forYaseen['mentions_me'])->toBeFalse()
        // Both see WHO was named: it is what the body says out loud, and it is not a permission.
        ->and($forYaseen['mentions'])->toBe([['id' => $this->tapu->id, 'name' => $this->tapu->name]]);
})->group('phase6');

it('walks backwards through history a window at a time', function () {
    // The thread opens on the newest window; `?before=` fetches what is behind it. A channel
    // that has been running a year is not a payload.
    for ($i = 1; $i <= ConversationService::THREAD_WINDOW + 5; $i++) {
        Message::factory()->inConversation($this->team)->by($this->admin)->create(['body' => 'Line '.$i]);
    }

    $first = $this->actingAs($this->tapu)
        ->getJson(MSGX_URL.'/'.$this->team->id)->assertOk()->json();

    expect($first['messages'])->toHaveCount(ConversationService::THREAD_WINDOW)
        ->and($first['has_more'])->toBeTrue();

    $oldest = $first['messages'][0]['id'];

    $earlier = $this->actingAs($this->tapu)
        ->getJson(MSGX_URL.'/'.$this->team->id.'?before='.$oldest)->assertOk()->json();

    expect($earlier['messages'])->toHaveCount(5)
        ->and($earlier['has_more'])->toBeFalse()
        ->and(end($earlier['messages'])['id'])->toBeLessThan($oldest);
})->group('phase6');

/*
|--------------------------------------------------------------------------
| Posting
|--------------------------------------------------------------------------
*/

it('posts a message into a channel', function () {
    $this->actingAs($this->tapu)
        ->post(MSGX_URL.'/'.$this->team->id, ['body' => 'Canonicals are done.'])
        ->assertRedirect();

    expect($this->team->messages()->count())->toBe(1)
        ->and($this->team->messages()->first()->body)->toBe('Canonicals are done.');
})->group('phase6');

it('posts a message with a file through the same FileService as everything else', function () {
    $this->actingAs($this->tapu)
        ->post(MSGX_URL.'/'.$this->team->id, [
            'body' => 'The crawl.',
            'file' => UploadedFile::fake()->create('crawl.pdf', 20, 'application/pdf'),
        ])
        ->assertRedirect();

    $attachment = $this->actingAs($this->tapu)
        ->getJson(MSGX_URL.'/'.$this->team->id)->assertOk()->json('messages.0.attachments.0');

    expect($attachment['name'])->toBe('crawl.pdf')
        // A signed link into the application, re-checked by FilePolicy on every fetch — never
        // a bearer URL at the disk (decision 2-25).
        ->and($attachment['url'])->toContain('/files/')
        ->and($attachment['url'])->toContain('signature=')
        ->and($attachment)->not->toHaveKey('path')
        ->and($attachment)->not->toHaveKey('disk');
})->group('phase6');

it('refuses a message with nothing in it, in the request front way', function () {
    $this->actingAs($this->tapu)
        ->post(MSGX_URL.'/'.$this->team->id, ['body' => '   '])
        ->assertSessionHasErrors('body');

    expect($this->team->messages()->count())->toBe(0);
})->group('phase6');

it('records a mention posted through the endpoint', function () {
    $this->actingAs($this->admin)
        ->post(MSGX_URL.'/'.$this->team->id, [
            'body' => '@'.$this->tapu->name.' one for you',
            'mentions' => [$this->tapu->id],
        ])
        ->assertRedirect();

    expect($this->team->messages()->first()->mentions->pluck('id')->all())
        ->toBe([$this->tapu->id]);
})->group('phase6');

/*
|--------------------------------------------------------------------------
| Announcements: read by everybody, written by one permission
|--------------------------------------------------------------------------
*/

it('lets an announcer post an announcement and refuses everybody else with 403', function () {
    $channel = $this->conversations->announcements();

    // Nothing about the channel is hidden, so the refusal is 403 and not 404: an employee can
    // read it and see that there is a thing here they may not do.
    $this->actingAs($this->tapu)
        ->getJson(MSGX_URL.'/'.$channel->id)
        ->assertOk()
        ->assertJsonPath('can_post', false);

    $this->actingAs($this->tapu)
        ->post(MSGX_URL.'/'.$channel->id, ['body' => 'Free lunch'])
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->post(MSGX_URL.'/'.$channel->id, ['body' => 'Office closed on Thursday.'])
        ->assertRedirect();

    expect($channel->messages()->count())->toBe(1);
})->group('phase6');

it('puts the newest announcement on the Messages page as a banner', function () {
    $channel = $this->conversations->announcements();

    $this->messages->post($this->admin, $channel, 'Office closed on Thursday.');

    $props = $this->actingAs($this->tapu)->get(MSGX_URL)->assertOk()->inertiaPage()['props'];

    expect($props['announcement']['body'])->toBe('Office closed on Thursday.')
        ->and($props['announcement']['author'])->toBe($this->admin->name)
        // Unread until the channel is read. The banner is not dismissed by a button: it is one
        // state and not two.
        ->and($props['announcement']['is_unread'])->toBeTrue();
})->group('phase6');

/*
|--------------------------------------------------------------------------
| Direct messages
|--------------------------------------------------------------------------
*/

it('opens a DM and lands on it', function () {
    $this->actingAs($this->tapu)
        ->post(MSGX_URL.'/direct/'.$this->admin->id)
        ->assertRedirect();

    $dm = Conversation::query()->ofType(ConversationType::Dm)->firstOrFail();

    expect($dm->isDmParticipant($this->tapu))->toBeTrue()
        ->and($dm->isDmParticipant($this->admin))->toBeTrue();
})->group('phase6');

it('opens the same DM the second time, not a second one', function () {
    $this->actingAs($this->tapu)->post(MSGX_URL.'/direct/'.$this->admin->id)->assertRedirect();
    $this->actingAs($this->admin)->post(MSGX_URL.'/direct/'.$this->tapu->id)->assertRedirect();

    expect(Conversation::query()->ofType(ConversationType::Dm)->count())->toBe(1);
})->group('phase6');

it('answers 404 for a DM with somebody who may not use messaging', function () {
    // The Accountant, and a deactivated colleague. Both absent rather than refused, so the
    // requester never learns whether the account exists (Part C).
    $this->actingAs($this->tapu)
        ->post(MSGX_URL.'/direct/'.$this->accountant->id)
        ->assertNotFound();

    $this->yaseen->forceFill(['status' => UserStatus::Inactive->value])->save();

    $this->actingAs($this->tapu)
        ->post(MSGX_URL.'/direct/'.$this->yaseen->id)
        ->assertNotFound();

    expect(Conversation::query()->ofType(ConversationType::Dm)->count())->toBe(0);
})->group('phase6');

it('offers nobody who may not use messaging in the direct-message picker', function () {
    $props = $this->actingAs($this->tapu)->get(MSGX_URL)->assertOk()->inertiaPage()['props'];

    $ids = array_column($props['people'], 'id');

    expect($ids)->toContain($this->admin->id)
        ->not->toContain($this->accountant->id)
        // And never yourself.
        ->not->toContain($this->tapu->id);
})->group('phase6');

/*
|--------------------------------------------------------------------------
| Read state
|--------------------------------------------------------------------------
*/

it('marks a thread read when it is fetched, and again on request', function () {
    $this->messages->post($this->admin, $this->team, 'Something to read');

    expect($this->conversations->readState($this->tapu, $this->team)['unread_count'])->toBe(1);

    $this->actingAs($this->tapu)->getJson(MSGX_URL.'/'.$this->team->id)->assertOk();

    expect($this->conversations->readState($this->tapu, $this->team)['unread_count'])->toBe(0);

    $this->messages->post($this->admin, $this->team, 'And another');

    $this->actingAs($this->tapu)
        ->post(MSGX_URL.'/'.$this->team->id.'/read')
        ->assertRedirect();

    expect($this->conversations->readState($this->tapu, $this->team)['unread_count'])->toBe(0);
})->group('phase6');
