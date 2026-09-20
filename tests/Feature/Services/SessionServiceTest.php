<?php

use App\Models\User;
use App\Services\SessionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['session.driver' => 'database']);
    $this->seed();
    $this->service = app(SessionService::class);
    $this->admin = User::where('email', 'shahadat@goodtechies.test')->firstOrFail();
    $this->tapu = User::where('email', 'tapu@goodtechies.test')->firstOrFail();
    $this->yaseen = User::where('email', 'yaseen@goodtechies.test')->firstOrFail();

    $rows = [
        ['tapu-old', $this->tapu->id, now()->subHours(3)->timestamp],
        ['tapu-current', $this->tapu->id, now()->timestamp],
        ['tapu-phone', $this->tapu->id, now()->subHour()->timestamp],
        ['yaseen-1', $this->yaseen->id, now()->timestamp],
    ];

    foreach ($rows as [$id, $userId, $lastActivity]) {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '198.51.100.7',
            'user_agent' => 'PestAgent',
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => $lastActivity,
        ]);
    }
});

it('lists a user\'s sessions newest first and marks the current one', function () {
    $sessions = $this->service->forUser($this->tapu, 'tapu-current');

    expect($sessions->pluck('id')->all())->toBe(['tapu-current', 'tapu-phone', 'tapu-old'])
        ->and($sessions->pluck('is_current')->all())->toBe([true, false, false])
        ->and($sessions[0]['ip_address'])->toBe('198.51.100.7')
        ->and($sessions[0]['user_agent'])->toBe('PestAgent')
        ->and($sessions[1]['last_active_at']->timestamp)->toBe(now()->subHour()->timestamp);
})->group('phase0');

it('lets the owner revoke another of their sessions', function () {
    $this->service->revoke($this->tapu, $this->tapu, 'tapu-old', 'tapu-current');

    expect(DB::table('sessions')->where('user_id', $this->tapu->id)->pluck('id')->sort()->values()->all())
        ->toBe(['tapu-current', 'tapu-phone']);
})->group('phase0');

it('refuses to revoke the current session', function () {
    expect(fn () => $this->service->revoke($this->tapu, $this->tapu, 'tapu-current', 'tapu-current'))
        ->toThrow(LogicException::class);

    expect(DB::table('sessions')->where('id', 'tapu-current')->exists())->toBeTrue();
})->group('phase0');

it('refuses an employee revoking someone else\'s session', function () {
    expect(fn () => $this->service->revoke($this->tapu, $this->yaseen, 'yaseen-1', 'tapu-current'))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('sessions')->where('id', 'yaseen-1')->exists())->toBeTrue();
})->group('phase0');

it('lets an admin revoke anyone\'s session', function () {
    $this->service->revoke($this->admin, $this->tapu, 'tapu-phone', 'admin-current');

    expect(DB::table('sessions')->where('id', 'tapu-phone')->exists())->toBeFalse()
        ->and(DB::table('sessions')->count())->toBe(3);
})->group('phase0');

it('does not revoke a session that belongs to someone other than the owner given', function () {
    expect(fn () => $this->service->revoke($this->tapu, $this->tapu, 'yaseen-1', 'tapu-current'))
        ->toThrow(ModelNotFoundException::class);

    expect(DB::table('sessions')->where('id', 'yaseen-1')->exists())->toBeTrue();
})->group('phase0');
