<?php

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AuditLogger;
use App\Support\AuditEvent;
use Illuminate\Support\Facades\Route;

it('writes a row with actor, target, old and new values, ip and user agent', function () {
    $actor = User::factory()->create();
    $target = Employee::factory()->create();

    Route::middleware('web')->get('/_test/audit', function () use ($actor, $target) {
        return app(AuditLogger::class)
            ->record(AuditEvent::RoleChanged, $target, ['role' => 'EMPLOYEE'], ['role' => 'ADMIN'], $actor)
            ->id;
    });

    $id = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeader('User-Agent', 'PestAgent/1.0')
        ->get('/_test/audit')
        ->assertOk()
        ->getContent();

    $log = AuditLog::findOrFail((int) $id);

    expect($log->actor_id)->toBe($actor->id)
        ->and($log->event)->toBe('role.changed')
        ->and($log->target_type)->toBe($target->getMorphClass())
        ->and($log->target_id)->toBe($target->id)
        ->and($log->old_value)->toBe(['role' => 'EMPLOYEE'])
        ->and($log->new_value)->toBe(['role' => 'ADMIN'])
        ->and($log->ip)->toBe('203.0.113.9')
        ->and($log->user_agent)->toBe('PestAgent/1.0');
})->group('phase0');

it('defaults the actor to the authenticated user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $log = app(AuditLogger::class)->record(AuditEvent::ConfigurationChanged);

    expect($log->actor_id)->toBe($user->id)
        ->and($log->target_type)->toBeNull()
        ->and($log->target_id)->toBeNull();
})->group('phase0');

it('leaves ip and user agent empty in console context', function () {
    $log = app(AuditLogger::class)->recordFor(AuditEvent::ConfigurationChanged, 'setting', 7, ['a' => 1], ['a' => 2]);

    expect($log->ip)->toBeNull()
        ->and($log->user_agent)->toBeNull()
        ->and($log->actor_id)->toBeNull()
        ->and($log->target_type)->toBe('setting')
        ->and($log->target_id)->toBe(7);
})->group('phase0');

it('records activity and returns the timeline newest first', function () {
    $actor = User::factory()->create();
    $employee = Employee::factory()->create();
    $logger = app(ActivityLogger::class);

    $first = $logger->record($employee, 'First', $actor);
    $second = $logger->record($employee, 'Second', $actor);
    $logger->record(Employee::factory()->create(), 'Other object', $actor);

    expect($logger->for($employee)->pluck('id')->all())->toBe([$second->id, $first->id])
        ->and(ActivityLog::count())->toBe(3)
        ->and($first->actor_id)->toBe($actor->id);
})->group('phase0');
