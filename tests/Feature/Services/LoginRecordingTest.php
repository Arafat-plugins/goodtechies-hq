<?php

use App\Listeners\RecordFailedLogin;
use App\Listeners\RecordSuccessfulLogin;
use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;

/**
 * How many times a listener class is registered for an event.
 */
function listenerRegistrations(string $event, string $listener): int
{
    return collect(Event::getRawListeners()[$event] ?? [])
        ->filter(fn (mixed $registered): bool => is_string($registered) && str_starts_with($registered, $listener)
            || is_array($registered) && ($registered[0] ?? null) === $listener)
        ->count();
}

it('registers each login listener exactly once', function () {
    expect(listenerRegistrations(Login::class, RecordSuccessfulLogin::class))->toBe(1)
        ->and(listenerRegistrations(Failed::class, RecordFailedLogin::class))->toBe(1);
})->group('phase0');

it('records a successful login with history, last_login_at and one audit row', function () {
    $user = User::factory()->create(['last_login_at' => null]);

    event(new Login('web', $user, false));

    $history = LoginHistory::sole();
    $audit = AuditLog::sole();

    expect($history->user_id)->toBe($user->id)
        ->and($history->email)->toBe($user->email)
        ->and($history->succeeded)->toBeTrue()
        ->and($user->fresh()->last_login_at)->not->toBeNull()
        ->and($audit->event)->toBe('user.login')
        ->and($audit->actor_id)->toBe($user->id)
        ->and($audit->target_type)->toBe($user->getMorphClass())
        ->and($audit->target_id)->toBe($user->id)
        ->and($audit->new_value)->toBe(['guard' => 'web']);
})->group('phase0');

it('records a failed login for an unknown email without an audit row', function () {
    event(new Failed('web', null, ['email' => 'x@y.z', 'password' => 'secret']));

    $history = LoginHistory::sole();

    expect($history->user_id)->toBeNull()
        ->and($history->email)->toBe('x@y.z')
        ->and($history->succeeded)->toBeFalse()
        ->and(AuditLog::count())->toBe(0);
})->group('phase0');

it('links a failed login to the existing user', function () {
    $user = User::factory()->create(['email' => 'known@goodtechies.test']);

    event(new Failed('web', $user, ['email' => 'known@goodtechies.test']));
    event(new Failed('web', null, ['email' => 'Known@GoodTechies.test']));

    expect(LoginHistory::pluck('user_id')->all())->toBe([$user->id, $user->id])
        ->and(AuditLog::count())->toBe(0);
})->group('phase0');
