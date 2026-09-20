<?php

use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Run a statement inside a savepoint and return the exception it raised, if any.
 */
function runAuditLogStatementInSavepoint(string $sql): ?QueryException
{
    $caught = null;

    DB::beginTransaction();

    try {
        DB::statement($sql);
    } catch (QueryException $e) {
        $caught = $e;
    } finally {
        DB::rollBack();
    }

    return $caught;
}

function createAuditLogRow(): AuditLog
{
    return AuditLog::create([
        'event' => 'phase0.test',
        'target_type' => 'user',
        'target_id' => 1,
        'new_value' => ['status' => 'active'],
        'ip' => '127.0.0.1',
    ]);
}

it('lets hq_app insert an audit row', function () {
    expect(DB::selectOne('SELECT current_user AS name')->name)->toBe('hq_app');

    $log = createAuditLogRow();

    expect(AuditLog::count())->toBe(1)
        ->and($log->fresh()->new_value)->toBe(['status' => 'active'])
        ->and($log->fresh()->created_at)->not->toBeNull();
})->group('phase0');

it('denies hq_app UPDATE, DELETE and TRUNCATE on audit_logs', function (string $sql) {
    $log = createAuditLogRow();

    $exception = runAuditLogStatementInSavepoint(str_replace(':id', (string) $log->id, $sql));

    expect($exception)->toBeInstanceOf(QueryException::class)
        ->and($exception->getCode())->toBe('42501')
        ->and(AuditLog::count())->toBe(1)
        ->and(AuditLog::find($log->id)->event)->toBe('phase0.test');
})->with([
    'update' => "UPDATE audit_logs SET event = 'tampered' WHERE id = :id",
    'delete' => 'DELETE FROM audit_logs WHERE id = :id',
    'truncate' => 'TRUNCATE audit_logs',
])->group('phase0');

it('refuses Eloquent updates of an audit row', function () {
    $log = createAuditLogRow();

    expect(fn () => $log->update(['event' => 'tampered']))->toThrow(LogicException::class);
    expect(AuditLog::find($log->id)->event)->toBe('phase0.test');
})->group('phase0');

it('refuses Eloquent deletes of an audit row', function () {
    $log = createAuditLogRow();

    expect(fn () => $log->delete())->toThrow(LogicException::class);
    expect(AuditLog::count())->toBe(1);
})->group('phase0');
