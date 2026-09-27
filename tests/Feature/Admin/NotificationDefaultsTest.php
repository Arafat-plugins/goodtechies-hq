<?php

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Task;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\AuditEvent;
use App\Support\NotificationChannel;
use App\Support\NotificationTab;
use App\Support\NotificationType;

/*
|--------------------------------------------------------------------------
| Admin → Notifications defaults
|--------------------------------------------------------------------------
|
| Part D §20: `notification_preferences (type, channel, enabled)` — "global
| defaults set by Admin, Phase 12; the engine reads them". Part E adds the
| channel rule: "other channels listed but disabled until spec post-MVP
| Phase 2".
|
| The assertions that matter are the two about the engine. A screen that stores
| a preference nothing reads looks identical to one that works, so the tests
| below turn a type off and count ROWS IN `notifications`, not rendered output.
|
| `NotificationService` keeps its copy of the preference table for the life of
| the instance (see its `$preferences`), so each engine assertion resolves the
| service fresh — the way a request, a job or a command each would.
|
*/

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();

    $this->task = Task::query()->forEmployee($this->tapu->employee)->notArchived()->orderBy('id')->firstOrFail();

    // These tests count rows absolutely.
    Notification::query()->delete();
});

/** One comment on a task, through the engine, from a service resolved right now. */
function deliverTaskComment(Task $task, User $recipient, User $actor): int
{
    app(NotificationService::class)->notify(
        NotificationType::TaskCommented,
        $task,
        [$recipient],
        ['title' => $task->title],
        $actor,
    );

    return Notification::query()->forUser($recipient)->count();
}

/*
|--------------------------------------------------------------------------
| The screen
|--------------------------------------------------------------------------
*/

it('lists every notification type in its own tab, and no tab that cannot hold one', function () {
    $props = $this->actingAs($this->admin)->get('/admin/notifications')->assertOk()->inertiaProps();

    $listed = collect($props['groups'])->flatMap(fn (array $group): array => array_column($group['types'], 'value'));

    expect($listed->sort()->values()->all())
        ->toBe(collect(NotificationType::values())->sort()->values()->all())
        // `All` is the absence of a filter rather than a tab, and Payroll has no types yet, so
        // neither is drawn — an empty group reads as a bug.
        ->and(array_column($props['groups'], 'key'))->not->toContain(NotificationTab::All->value)
        ->and(array_column($props['groups'], 'key'))->not->toContain(NotificationTab::Payroll->value);
});

it('shows web push and email, off, and not switchable', function () {
    $props = $this->actingAs($this->admin)->get('/admin/notifications')->inertiaProps();

    $channels = collect($props['channels'])->keyBy('value');

    expect($channels->keys()->all())->toBe(['in_app', 'web_push', 'mail'])
        ->and($channels['in_app']['available'])->toBeTrue()
        ->and($channels['web_push']['available'])->toBeFalse()
        ->and($channels['mail']['available'])->toBeFalse()
        ->and($channels['web_push']['note'])->toContain('Nothing sends on this channel yet');

    foreach ($props['groups'] as $group) {
        foreach ($group['types'] as $type) {
            $cells = collect($type['channels'])->keyBy('channel');

            expect($cells['in_app']['switchable'])->toBeTrue()
                ->and($cells['in_app']['enabled'])->toBeTrue()
                ->and($cells['web_push']['switchable'])->toBeFalse()
                ->and($cells['web_push']['enabled'])->toBeFalse()
                ->and($cells['mail']['switchable'])->toBeFalse()
                ->and($cells['mail']['enabled'])->toBeFalse();
        }
    }
});

it('starts with an empty table, because absent means default', function () {
    // Nothing seeds this table and nothing needs to. A row per type × channel would go stale the
    // moment a NotificationType case was added, and a missing row would then read as "off" for a
    // type nobody has ever configured.
    expect(NotificationPreference::query()->count())->toBe(0);

    expect(deliverTaskComment($this->task, $this->tapu, $this->admin))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The switch persists, and it is audited
|--------------------------------------------------------------------------
*/

it('stores a preference when a type is switched off, audited with no previous value', function () {
    $this->actingAs($this->admin)
        ->put('/admin/notifications', [
            'type' => NotificationType::TaskCommented->value,
            'channel' => NotificationChannel::InApp->value,
            'enabled' => false,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $preference = NotificationPreference::query()->sole();

    expect($preference->type)->toBe('task.commented')
        ->and($preference->channel)->toBe('in_app')
        ->and($preference->enabled)->toBeFalse();

    $audit = AuditLog::query()
        ->where('event', AuditEvent::ConfigurationChanged->value)
        ->where('target_type', 'notification_preference')
        ->sole();

    expect($audit->actor_id)->toBe($this->admin->id)
        ->and($audit->target_id)->toBe($preference->id)
        // `null`, not `false`: nobody had ever set this pair. That distinction is not
        // recoverable from the table afterwards, so the audit row is where it lives.
        ->and($audit->old_value)->toBe(['type' => 'task.commented', 'channel' => 'in_app', 'enabled' => null])
        ->and($audit->new_value)->toBe(['type' => 'task.commented', 'channel' => 'in_app', 'enabled' => false]);
});

it('keeps one row per pair when it is switched back on', function () {
    foreach ([false, true] as $enabled) {
        $this->actingAs($this->admin)
            ->put('/admin/notifications', [
                'type' => NotificationType::TaskCommented->value,
                'channel' => NotificationChannel::InApp->value,
                'enabled' => $enabled,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    expect(NotificationPreference::query()->count())->toBe(1)
        ->and(NotificationPreference::query()->sole()->enabled)->toBeTrue()
        ->and(AuditLog::query()->where('target_type', 'notification_preference')->count())->toBe(2)
        ->and(AuditLog::query()->where('target_type', 'notification_preference')->orderByDesc('id')->first()->old_value)
        ->toBe(['type' => 'task.commented', 'channel' => 'in_app', 'enabled' => false]);
});

it('writes nothing when the switch is already where it was asked to go', function () {
    // Absent means default means on, so switching "on" on a pair nobody has touched changes
    // nothing and must not leave a row behind claiming somebody decided something.
    $this->actingAs($this->admin)
        ->put('/admin/notifications', [
            'type' => NotificationType::TaskCommented->value,
            'channel' => NotificationChannel::InApp->value,
            'enabled' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(NotificationPreference::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('target_type', 'notification_preference')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The engine honours it — the whole point of the screen
|--------------------------------------------------------------------------
*/

it('writes no notification row at all once a type is switched off', function () {
    expect(deliverTaskComment($this->task, $this->tapu, $this->admin))->toBe(1);

    Notification::query()->delete();

    $this->actingAs($this->admin)->put('/admin/notifications', [
        'type' => NotificationType::TaskCommented->value,
        'channel' => NotificationChannel::InApp->value,
        'enabled' => false,
    ])->assertSessionHasNoErrors();

    // Not written-and-hidden and not filtered at read time: there is no row.
    expect(deliverTaskComment($this->task, $this->tapu, $this->admin))->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
});

it('writes one again once the type is switched back on', function () {
    NotificationPreference::query()->create([
        'type' => NotificationType::TaskCommented->value,
        'channel' => NotificationChannel::InApp->value,
        'enabled' => false,
    ]);

    expect(deliverTaskComment($this->task, $this->tapu, $this->admin))->toBe(0);

    $this->actingAs($this->admin)->put('/admin/notifications', [
        'type' => NotificationType::TaskCommented->value,
        'channel' => NotificationChannel::InApp->value,
        'enabled' => true,
    ])->assertSessionHasNoErrors();

    expect(deliverTaskComment($this->task, $this->tapu, $this->admin))->toBe(1);
});

it('switches one type off and leaves every other type alone', function () {
    NotificationPreference::query()->create([
        'type' => NotificationType::TaskCommented->value,
        'channel' => NotificationChannel::InApp->value,
        'enabled' => false,
    ]);

    expect(deliverTaskComment($this->task, $this->tapu, $this->admin))->toBe(0);

    app(NotificationService::class)->notify(
        NotificationType::TaskAssigned,
        $this->task,
        [$this->tapu],
        ['title' => $this->task->title],
        $this->admin,
    );

    expect(Notification::query()->forUser($this->tapu)->count())->toBe(1)
        ->and(Notification::query()->forUser($this->tapu)->sole()->type)->toBe(NotificationType::TaskAssigned);
});

it('returns the rows it actually wrote, so a caller cannot be handed a null', function () {
    NotificationPreference::query()->create([
        'type' => NotificationType::TaskCommented->value,
        'channel' => NotificationChannel::InApp->value,
        'enabled' => false,
    ]);

    $written = app(NotificationService::class)->notify(
        NotificationType::TaskCommented,
        $this->task,
        [$this->tapu, $this->yaseen],
        ['title' => $this->task->title],
        $this->admin,
    );

    expect($written)->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| A channel with no sender cannot be switched on, and not only in the UI
|--------------------------------------------------------------------------
*/

it('refuses a channel nothing sends on', function (string $channel) {
    $this->actingAs($this->admin)
        ->put('/admin/notifications', [
            'type' => NotificationType::TaskCommented->value,
            'channel' => $channel,
            'enabled' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('channel');

    expect(session('errors')->first('channel'))->toContain('Nothing sends on that channel yet')
        ->and(NotificationPreference::query()->count())->toBe(0);
})->with([
    'browser push' => [NotificationChannel::WebPush->value],
    'email' => [NotificationChannel::Mail->value],
]);

it('cannot be talked into delivering on a channel even with a row forced into the table', function () {
    // The schema does not police `channel`, deliberately (see the migration). So the rule has to
    // hold on the read side too: the type's own channels() list is asked FIRST, and a stored row
    // for a channel it does not name cannot switch one on.
    NotificationPreference::query()->create([
        'type' => NotificationType::TaskCommented->value,
        'channel' => NotificationChannel::WebPush->value,
        'enabled' => true,
    ]);

    expect(NotificationPreference::enabledIn(
        NotificationPreference::overrides(),
        NotificationType::TaskCommented,
        NotificationChannel::WebPush,
    ))->toBeFalse()
        // And the in-app row is still written, because that row said nothing about in-app.
        ->and(deliverTaskComment($this->task, $this->tapu, $this->admin))->toBe(1);
});

it('ignores a stored row for a type the enum no longer has', function () {
    // The migration puts no CHECK on `type`, so a row left behind by a renamed or removed case
    // is possible. It must be inert rather than fatal: the read path looks preferences up BY the
    // case it is already holding, and hydration must not blow up on the stale value either.
    NotificationPreference::query()->create([
        'type' => 'task.abandoned_in_a_later_phase',
        'channel' => NotificationChannel::InApp->value,
        'enabled' => false,
    ]);

    expect(deliverTaskComment($this->task, $this->tapu, $this->admin))->toBe(1);

    $this->actingAs($this->admin)->get('/admin/notifications')->assertOk();
});

it('refuses a type that is not a notification type', function () {
    $this->actingAs($this->admin)
        ->put('/admin/notifications', [
            'type' => 'task.invented',
            'channel' => NotificationChannel::InApp->value,
            'enabled' => false,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('type');

    expect(NotificationPreference::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Who may do this
|--------------------------------------------------------------------------
*/

it('refuses every role but the Admin, on the read and on the write', function () {
    foreach ([$this->yaseen, $this->accountant] as $user) {
        $this->actingAs($user)->get('/admin/notifications')->assertForbidden();
        $this->actingAs($user)->put('/admin/notifications', [
            'type' => NotificationType::TaskCommented->value,
            'channel' => NotificationChannel::InApp->value,
            'enabled' => false,
        ])->assertForbidden();
    }

    expect(NotificationPreference::query()->count())->toBe(0);
});
