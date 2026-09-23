<?php

use App\Models\Notification;
use App\Models\User;
use App\Support\NotificationType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The notifications table
|--------------------------------------------------------------------------
|
| The spec's column list is `user_id, type, payload JSON, group_key, count,
| is_read, read_at, created_at`. The two things worth asserting beyond that
| are the two decisions this migration made:
|
|   - `updated_at` EXISTS, unlike audit_logs'. That table is append-only and
|     the runtime role has no UPDATE on it at all; this one is updated on its
|     two hottest paths — the dedup count going up and the row being marked
|     read — so a column that says only when it was first written would be
|     unable to answer when a group last grew.
|   - Every rule the engine depends on is the DATABASE's. A constraint nobody
|     has ever violated is indistinguishable from a comment, so each one is
|     violated here.
|
*/

beforeEach(fn () => $this->seed());

it('has the columns the spec names, plus updated_at', function () {
    expect(Schema::hasColumns('notifications', [
        'id', 'user_id', 'type', 'payload', 'group_key', 'count', 'is_read', 'read_at', 'created_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('notifications', 'updated_at'))->toBeTrue()
        // audit_logs is the contrast, and it is deliberate: it can never be updated, so it has
        // no column that would claim to say when it was.
        ->and(Schema::hasColumn('audit_logs', 'updated_at'))->toBeFalse();
})->group('phase2');

it('is not append-only, because the engine updates it', function () {
    $user = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    $notification = Notification::factory()->forUser($user)->create();

    // Running as hq_app, the same role audit_logs has UPDATE revoked from. A count that could
    // not be incremented would make §11's dedup rule unimplementable.
    DB::table('notifications')->where('id', $notification->id)->update(['count' => 12]);

    expect((int) $notification->fresh()->count)->toBe(12);
})->group('phase2');

it('refuses a type nothing has ever defined', function () {
    $user = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    expect(fn () => DB::table('notifications')->insert([
        'user_id' => $user->id,
        'type' => 'task.telepathy',
        'payload' => json_encode([]),
        'group_key' => 'probe',
        'count' => 1,
        'is_read' => false,
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->group('phase2');

it('accepts every type in the enum', function (NotificationType $type) {
    $user = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    $id = DB::table('notifications')->insertGetId([
        'user_id' => $user->id,
        'type' => $type->value,
        'payload' => json_encode(['title' => 'Probe']),
        'group_key' => $type->value.':probe',
        'count' => 1,
        'is_read' => false,
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($id)->toBeGreaterThan(0);
})->with(array_map(
    fn (NotificationType $type): array => [$type],
    NotificationType::cases(),
))->group('phase2');

it('refuses a group of zero', function () {
    $user = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    expect(fn () => DB::table('notifications')->insert([
        'user_id' => $user->id,
        'type' => NotificationType::TaskAssigned->value,
        'payload' => json_encode([]),
        'group_key' => 'probe',
        'count' => 0,
        'is_read' => false,
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->group('phase2');

it('refuses a read state that contradicts itself', function (bool $isRead, bool $hasTimestamp) {
    $user = User::where('email', 'tapu@goodtechies.test')->firstOrFail();

    expect(fn () => DB::table('notifications')->insert([
        'user_id' => $user->id,
        'type' => NotificationType::TaskAssigned->value,
        'payload' => json_encode([]),
        'group_key' => 'probe',
        'count' => 1,
        'is_read' => $isRead,
        'read_at' => $hasTimestamp ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->with([
    'read with no timestamp' => [true, false],
    'unread with a timestamp' => [false, true],
])->group('phase2');

it('takes a notification away with the person it was addressed to', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->forUser($user)->create();

    $user->delete();

    // Mail addressed to a deleted user is not addressed to anybody. The foreign key is real
    // and it cascades.
    expect(Notification::query()->whereKey($notification->id)->exists())->toBeFalse();
})->group('phase2');

it('indexes the dedup lookup and the unread badge with one partial index', function () {
    $indexes = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'notifications'"))
        ->pluck('indexdef', 'indexname');

    expect($indexes)->toHaveKey('notifications_unread')
        ->and($indexes['notifications_unread'])->toContain('WHERE (is_read = false)')
        ->and($indexes['notifications_unread'])->toContain('user_id')
        ->and($indexes['notifications_unread'])->toContain('group_key')
        // And the lists' index, which is not partial because a list shows read rows too.
        ->and($indexes)->toHaveKey('notifications_user_created');
})->group('phase2');
