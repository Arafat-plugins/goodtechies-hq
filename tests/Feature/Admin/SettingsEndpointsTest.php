<?php

use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\AuditEvent;
use Database\Seeders\SettingsSeeder;

/*
|--------------------------------------------------------------------------
| Admin → Settings
|--------------------------------------------------------------------------
|
| Part E, Phase 12: "settings changes audit-logged and every key except
| `backup_last_verified_at` editable". Both halves are asserted here, and the
| audit half is asserted per key rather than once, because the audit row is the
| only record of a configuration change and a key that wrote no row would look
| exactly like a key that was never touched.
|
| The write path is SettingsService::set(), which existed from Phase 0 with no
| caller. Nothing below reaches around it: every assertion about a refusal is an
| assertion about validation or about the service's own rules.
|
*/

/**
 * A valid value for every editable key that is NOT its seeded default, so each case proves a
 * write happened rather than that nothing changed.
 */
const SETTINGS_NEW_VALUES = [
    'timezone' => 'Europe/London',
    'currency' => 'BDT',
    'late_grace_minutes' => 20,
    'half_day_auto' => true,
    'timer_max_session_hours' => 9,
    'heartbeat_timeout_minutes' => 7,
    'manual_time_requires_approval' => false,
    'notification_group_window_minutes' => 5,
    'idle_pause_minutes' => 8,
    'idle_flag_percent' => 30,
];

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();
    $this->accountant = User::where('email', 'accountant@goodtechies.test')->firstOrFail();
});

function settingsStored(string $key): mixed
{
    return Setting::query()->where('key', $key)->sole()->value;
}

/*
|--------------------------------------------------------------------------
| The screen
|--------------------------------------------------------------------------
*/

it('offers a field for every Part D §20 key except the read-only one', function () {
    $props = $this->actingAs($this->admin)->get('/admin/settings')->assertOk()->inertiaProps();

    $editable = array_diff(array_keys(SettingsSeeder::DEFAULTS), SettingsService::READ_ONLY);

    expect(array_column($props['fields'], 'key'))->toBe(array_values($editable))
        // The read-only key is not a field. It is the backup health card, and nothing on this
        // page can type over it.
        ->and(array_column($props['fields'], 'key'))->not->toContain('backup_last_verified_at')
        ->and($props['sections'])->toBe(['General', 'Attendance', 'Timer', 'Notifications']);

    // Every field names a group the page draws, so none can be described and then not shown.
    foreach ($props['fields'] as $field) {
        expect($props['sections'])->toContain($field['section'])
            ->and($field['type'])->toBeIn(['timezone', 'currency', 'integer', 'boolean'])
            ->and($field['help'])->not->toBe('');
    }
});

it('says why each driver cannot be changed here, not just that it cannot', function () {
    $props = $this->actingAs($this->admin)->get('/admin/settings')->inertiaProps();

    expect($props['drivers']['realtime'])->toBe(config('broadcasting.default'))
        ->and($props['drivers']['googleCalendar'])->toBe('manual')
        ->and($props['drivers']['realtimeEnv'])->toContain('BROADCAST_CONNECTION')
        ->and($props['drivers']['googleCalendarEnv'])->toBe('GOOGLE_CALENDAR_DRIVER')
        // The reason, in words. A read-only row that only says "read-only" reads as an
        // unfinished feature — Part D §20 says these cannot switch at runtime, and the screen
        // has to carry that sentence.
        ->and($props['drivers']['realtimeWhy'])->toContain('rebuild')
        ->and($props['drivers']['googleCalendarWhy'])->toContain('credentials');
});

/*
|--------------------------------------------------------------------------
| Every key is editable, and every change is audited
|--------------------------------------------------------------------------
*/

it('saves every editable key and writes one audit row for each', function (string $key, mixed $value) {
    $before = app(SettingsService::class)->get($key);

    expect($value)->not->toBe($before);

    $this->actingAs($this->admin)
        ->put('/admin/settings', [$key => $value])
        ->assertRedirect()
        ->assertSessionHas('success')
        ->assertSessionHasNoErrors();

    // Stored, with the TYPE the readers expect — `Setting::value` is a JSON column, so a form's
    // "20" would be stored as a string and every `=== ` comparison against it would be false.
    expect(settingsStored($key))->toBe($value);

    $audit = AuditLog::query()
        ->where('event', AuditEvent::ConfigurationChanged->value)
        ->where('target_type', 'setting')
        ->get();

    expect($audit)->toHaveCount(1)
        ->and($audit->first()->actor_id)->toBe($this->admin->id)
        ->and($audit->first()->old_value)->toBe([$key => $before])
        ->and($audit->first()->new_value)->toBe([$key => $value]);
})->with(array_map(
    fn (string $key): array => [$key, SETTINGS_NEW_VALUES[$key]],
    array_keys(SETTINGS_NEW_VALUES),
));

it('writes one audit row per key when a whole section is saved at once', function () {
    $this->actingAs($this->admin)
        ->put('/admin/settings', [
            'timer_max_session_hours' => 8,
            'heartbeat_timeout_minutes' => 3,
            'manual_time_requires_approval' => false,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $audit = AuditLog::query()
        ->where('event', AuditEvent::ConfigurationChanged->value)
        ->orderBy('id')
        ->get();

    expect($audit)->toHaveCount(3)
        ->and($audit->pluck('old_value')->all())->toBe([
            ['timer_max_session_hours' => 10],
            ['heartbeat_timeout_minutes' => 5],
            ['manual_time_requires_approval' => true],
        ]);
});

it('writes nothing at all when the value sent is the value stored', function () {
    $this->actingAs($this->admin)
        ->put('/admin/settings', ['late_grace_minutes' => SettingsSeeder::DEFAULTS['late_grace_minutes']])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('event', AuditEvent::ConfigurationChanged->value)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Validation is per key, and it is what stops a typo breaking a rule
|--------------------------------------------------------------------------
*/

it('refuses a value outside the key\'s own range and changes nothing', function (string $key, mixed $value) {
    $before = app(SettingsService::class)->get($key);

    $this->actingAs($this->admin)
        ->put('/admin/settings', [$key => $value])
        ->assertRedirect()
        ->assertSessionHasErrors($key);

    expect(app(SettingsService::class)->get($key))->toBe($before)
        ->and(AuditLog::query()->where('event', AuditEvent::ConfigurationChanged->value)->count())->toBe(0);
})->with([
    // A timezone Carbon cannot construct is not a wrong answer, it is an exception on the next
    // scheduled run — which is exactly the failure a "any string is fine" form would ship.
    'timezone that does not exist' => ['timezone', 'Asia/Dacca-ish'],
    'timezone left empty' => ['timezone', ''],
    'currency that is not three letters' => ['currency', 'US'],
    'currency with digits' => ['currency', 'US1'],
    'late grace below zero' => ['late_grace_minutes', -1],
    'late grace beyond two hours' => ['late_grace_minutes', 121],
    'late grace as prose' => ['late_grace_minutes', 'fifteen'],
    'late grace as a fraction' => ['late_grace_minutes', 15.5],
    'half day as prose' => ['half_day_auto', 'yes please'],
    'timer session of zero hours' => ['timer_max_session_hours', 0],
    'timer session longer than a day' => ['timer_max_session_hours', 25],
    'heartbeat timeout of zero' => ['heartbeat_timeout_minutes', 0],
    'heartbeat timeout beyond an hour' => ['heartbeat_timeout_minutes', 61],
    'idle pause of zero' => ['idle_pause_minutes', 0],
    'idle flag above a hundred percent' => ['idle_flag_percent', 101],
    'idle flag below zero' => ['idle_flag_percent', -5],
    'group window beyond an hour' => ['notification_group_window_minutes', 61],
]);

it('upper-cases a currency so usd and USD are one stored value', function () {
    $this->actingAs($this->admin)
        ->put('/admin/settings', ['currency' => 'bdt'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(settingsStored('currency'))->toBe('BDT');
});

it('accepts a group window of zero, which the engine reads as grouping off', function () {
    // NotificationService::windowStart() treats a window of zero or less as grouping OFF rather
    // than grouping everything, so 0 is a real answer and the range has to allow it.
    $this->actingAs($this->admin)
        ->put('/admin/settings', ['notification_group_window_minutes' => 0])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(settingsStored('notification_group_window_minutes'))->toBe(0);
});

it('accepts a late grace of zero, which means a minute late is late', function () {
    $this->actingAs($this->admin)
        ->put('/admin/settings', ['late_grace_minutes' => 0])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(settingsStored('late_grace_minutes'))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The keys that are not editable, and the keys that do not exist
|--------------------------------------------------------------------------
*/

it('refuses the read-only backup key with the sentence that explains it', function () {
    $this->actingAs($this->admin)
        ->put('/admin/settings', ['backup_last_verified_at' => now()->toIso8601String()])
        ->assertRedirect()
        ->assertSessionHasErrors('backup_last_verified_at');

    expect(session('errors')->first('backup_last_verified_at'))->toContain('written by the system')
        ->and(app(SettingsService::class)->get('backup_last_verified_at'))->toBeNull()
        ->and(AuditLog::query()->where('event', AuditEvent::ConfigurationChanged->value)->count())->toBe(0);
});

it('fails a key it does not recognise rather than saving nothing and saying it saved', function () {
    $this->actingAs($this->admin)
        ->put('/admin/settings', ['productivity_score_enabled' => true])
        ->assertRedirect()
        ->assertSessionHasErrors('productivity_score_enabled')
        ->assertSessionMissing('success');
});

it('fails a request carrying no setting at all', function () {
    $this->actingAs($this->admin)
        ->put('/admin/settings', [])
        ->assertRedirect()
        ->assertSessionHasErrors('settings')
        ->assertSessionMissing('success');
});

it('keeps the Form Request table and the seeded key list in step', function () {
    // Part D §20's list is closed: "Nothing else is added without a recorded decision." So the
    // editable table can only ever be the seeded keys minus the read-only ones — no extra key
    // can appear in the form, and no key can quietly stop being editable.
    expect(array_keys(UpdateSettingsRequest::FIELDS))
        ->toBe(array_values(array_diff(array_keys(SettingsSeeder::DEFAULTS), SettingsService::READ_ONLY)));
});

/*
|--------------------------------------------------------------------------
| Who may do this
|--------------------------------------------------------------------------
*/

it('refuses every role but the Admin, on the read and on the write', function () {
    foreach ([$this->yaseen, $this->accountant] as $user) {
        $this->actingAs($user)->get('/admin/settings')->assertForbidden();
        $this->actingAs($user)->put('/admin/settings', ['late_grace_minutes' => 30])->assertForbidden();
    }

    expect(settingsStored('late_grace_minutes'))->toBe(15);
});
