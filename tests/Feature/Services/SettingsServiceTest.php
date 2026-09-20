<?php

use App\Exceptions\UnknownSettingException;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed();
    $this->settings = app(SettingsService::class);
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
});

it('reads single values and all values in defaults order', function () {
    expect($this->settings->get('late_grace_minutes'))->toBe(15)
        ->and($this->settings->get('half_day_auto'))->toBeFalse()
        ->and(array_keys($this->settings->all()))->toBe(array_keys(SettingsSeeder::DEFAULTS))
        ->and($this->settings->all())->toBe(SettingsSeeder::DEFAULTS);
})->group('phase0');

it('writes a changed value with one configuration.changed audit row', function () {
    $this->settings->set('late_grace_minutes', 20, $this->admin);

    $setting = Setting::where('key', 'late_grace_minutes')->firstOrFail();
    $logs = AuditLog::where('event', 'configuration.changed')->get();

    expect($setting->value)->toBe(20)
        ->and($this->settings->get('late_grace_minutes'))->toBe(20)
        ->and(app(SettingsService::class)->get('late_grace_minutes'))->toBe(20)
        ->and($logs)->toHaveCount(1)
        ->and($logs[0]->actor_id)->toBe($this->admin->id)
        ->and($logs[0]->target_type)->toBe('setting')
        ->and($logs[0]->target_id)->toBe($setting->id)
        ->and($logs[0]->old_value)->toBe(['late_grace_minutes' => 15])
        ->and($logs[0]->new_value)->toBe(['late_grace_minutes' => 20]);
})->group('phase0');

it('does nothing when the value is unchanged', function () {
    $this->settings->set('currency', 'USD', $this->admin);

    expect(AuditLog::count())->toBe(0);
})->group('phase0');

it('rejects unknown keys on read and write', function () {
    expect(fn () => $this->settings->get('nope'))->toThrow(UnknownSettingException::class)
        ->and(fn () => $this->settings->set('nope', 1, $this->admin))->toThrow(UnknownSettingException::class);

    expect(AuditLog::count())->toBe(0);
})->group('phase0');

it('refuses to set backup_last_verified_at', function () {
    expect(fn () => $this->settings->set('backup_last_verified_at', '2026-01-01T00:00:00+06:00', $this->admin))
        ->toThrow(UnknownSettingException::class, 'read-only');

    expect(Setting::where('key', 'backup_last_verified_at')->first()->value)->toBeNull()
        ->and(AuditLog::count())->toBe(0);
})->group('phase0');

it('refuses a non-admin', function () {
    expect(fn () => $this->settings->set('late_grace_minutes', 30, $this->tapu))
        ->toThrow(AuthorizationException::class);

    expect(Setting::where('key', 'late_grace_minutes')->first()->value)->toBe(15)
        ->and(AuditLog::count())->toBe(0);
})->group('phase0');

it('records backup verification without an audit row', function () {
    $at = Carbon::parse('2026-09-17 03:00:00', 'Asia/Dhaka');

    $this->settings->recordBackupVerified($at);

    expect(Setting::where('key', 'backup_last_verified_at')->first()->value)->toBe($at->toIso8601String())
        ->and($this->settings->get('backup_last_verified_at'))->toBe($at->toIso8601String())
        ->and(AuditLog::count())->toBe(0);
})->group('phase0');
