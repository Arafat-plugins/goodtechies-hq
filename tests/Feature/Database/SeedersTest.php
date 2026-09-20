<?php

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\User;
use App\Support\Permission as PermissionKey;
use App\Support\RoleName;
use App\Support\TrackingMode;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\TeamSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * @return array<string, array{0: string, 1: RoleName, 2: TrackingMode, 3: bool}>
 */
function seededTeam(): array
{
    return [
        'Shahadat' => ['shahadat@goodtechies.test', RoleName::ADMIN, TrackingMode::OfficeAttendance, true],
        'Faruk' => ['faruk@goodtechies.test', RoleName::ADMIN, TrackingMode::OfficeAttendance, true],
        'Tapu' => ['tapu@goodtechies.test', RoleName::REMOTE_EMPLOYEE, TrackingMode::RemoteTimer, false],
        'Yaseen' => ['yaseen@goodtechies.test', RoleName::EMPLOYEE, TrackingMode::OfficeAttendance, false],
        'Accountant' => ['accountant@goodtechies.test', RoleName::ACCOUNTANT, TrackingMode::None, true],
    ];
}

/**
 * @return array<string, int>
 */
function seededCounts(): array
{
    return [
        'users' => User::count(),
        'employees' => Employee::count(),
        'schedules' => Schedule::count(),
        'roles' => Role::count(),
        'permissions' => Permission::count(),
        'role_permissions' => DB::table('role_permissions')->count(),
        'settings' => Setting::count(),
    ];
}

beforeEach(function () {
    $this->seed();
});

it('seeds the five roles and every permission key', function () {
    expect(Role::count())->toBe(5)
        ->and(Permission::count())->toBe(23)
        ->and(count(PermissionKey::cases()))->toBe(23)
        ->and(array_keys(RolePermissionSeeder::MATRIX))
        ->toEqualCanonicalizing(array_map(fn (RoleName $r) => $r->value, RoleName::cases()));
})->group('phase0');

it('grants each role exactly its matrix keys', function (RoleName $roleName) {
    $keys = Role::where('name', $roleName->value)->firstOrFail()
        ->permissions
        ->map(fn (Permission $permission) => $permission->key->value)
        ->all();

    expect($keys)->toEqualCanonicalizing(RolePermissionSeeder::MATRIX[$roleName->value]);
})->with(RoleName::cases())->group('phase0');

it('gives admins every key except timer.use', function () {
    $admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();

    expect($admin->hasPermission(PermissionKey::TimerUse))->toBeFalse();

    foreach (PermissionKey::cases() as $key) {
        if ($key !== PermissionKey::TimerUse) {
            expect($admin->hasPermission($key))->toBeTrue();
        }
    }
})->group('phase0');

it('seeds no MANAGER user', function () {
    expect(Role::where('name', RoleName::MANAGER->value)->firstOrFail()->employees()->count())->toBe(0);
})->group('phase0');

it('seeds every settings default', function () {
    expect(Setting::count())->toBe(count(SettingsSeeder::DEFAULTS));

    foreach (SettingsSeeder::DEFAULTS as $key => $default) {
        $setting = Setting::where('key', $key)->first();

        expect($setting)->not->toBeNull()
            ->and($setting->value)->toBe($default);
    }
})->group('phase0');

it('seeds the team with role, tracking mode and 2FA state', function (string $email, RoleName $role, TrackingMode $mode, bool $twoFactor) {
    $user = User::where('email', $email)->firstOrFail();

    expect($user->role())->toBe($role)
        ->and($user->employee->tracking_mode)->toBe($mode)
        ->and($user->isActive())->toBeTrue()
        ->and($user->hasConfirmedTwoFactor())->toBe($twoFactor)
        ->and($user->requiresTwoFactor())->toBe(in_array($role, [RoleName::ADMIN, RoleName::ACCOUNTANT], true));

    if ($twoFactor) {
        expect($user->two_factor_recovery_codes)->toHaveCount(8);

        foreach ($user->two_factor_recovery_codes as $code) {
            expect(Hash::isHashed($code))->toBeTrue();
        }
    } else {
        expect($user->two_factor_secret)->toBeNull()
            ->and($user->two_factor_recovery_codes)->toBeNull();
    }
})->with(seededTeam())->group('phase0');

it('stores passwords hashed and the 2FA secret encrypted', function () {
    $seedPassword = env('SEED_PASSWORD');
    $seedSecret = env('SEED_TWO_FACTOR_SECRET');

    foreach (DB::table('users')->pluck('password') as $stored) {
        expect($stored)->not->toBe($seedPassword)
            ->and(Hash::check($seedPassword, $stored))->toBeTrue();
    }

    $admin = User::where('email', 'faruk@goodtechies.test')->firstOrFail();
    $raw = DB::table('users')->where('id', $admin->id)->value('two_factor_secret');

    expect($raw)->not->toBeNull()
        ->and($raw)->not->toBe($seedSecret)
        ->and($raw)->not->toContain($seedSecret)
        ->and($admin->two_factor_secret)->toBe($seedSecret);
})->group('phase0');

it('keeps counts stable when seeding twice', function () {
    $before = seededCounts();

    $this->seed();

    expect(seededCounts())->toBe($before)
        ->and($before)->toBe([
            'users' => 5,
            'employees' => 5,
            'schedules' => 4,
            'roles' => 5,
            'permissions' => 23,
            'role_permissions' => 52,
            'settings' => 11,
        ]);
})->group('phase0');

it('refuses to seed the team without SEED_PASSWORD', function () {
    $saved = [$_ENV['SEED_PASSWORD'] ?? null, $_SERVER['SEED_PASSWORD'] ?? null, getenv('SEED_PASSWORD')];

    $_ENV['SEED_PASSWORD'] = '';
    $_SERVER['SEED_PASSWORD'] = '';
    putenv('SEED_PASSWORD=');

    try {
        expect(fn () => $this->app->make(TeamSeeder::class)->run())
            ->toThrow(RuntimeException::class, 'SEED_PASSWORD');
    } finally {
        [$env, $server, $put] = $saved;

        if ($env === null) {
            unset($_ENV['SEED_PASSWORD']);
        } else {
            $_ENV['SEED_PASSWORD'] = $env;
        }

        if ($server === null) {
            unset($_SERVER['SEED_PASSWORD']);
        } else {
            $_SERVER['SEED_PASSWORD'] = $server;
        }

        putenv($put === false ? 'SEED_PASSWORD' : 'SEED_PASSWORD='.$put);
    }
})->group('phase0');
