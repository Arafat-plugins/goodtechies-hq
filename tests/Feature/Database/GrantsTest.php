<?php

use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('connects each connection as its own role', function (string $connection, string $role) {
    expect(DB::connection($connection)->selectOne('SELECT current_user AS name')->name)->toBe($role);
})->with([
    'runtime' => ['pgsql', 'hq_app'],
    'migrator' => ['pgsql_migrator', 'hq_migrator'],
    'read-only' => ['pgsql_ro', 'hq_ro'],
])->group('phase0');

it('keeps hq_ro read-only', function () {
    // pgsql_ro is outside the test transaction; clean up through the migrator if the insert ever succeeds.
    $key = 'grants_test_ro_'.bin2hex(random_bytes(4));
    $exception = null;

    try {
        DB::connection('pgsql_ro')->table('settings')->insert([
            'key' => $key,
            'value' => json_encode(true),
        ]);
    } catch (QueryException $e) {
        $exception = $e;
    } finally {
        DB::connection('pgsql_migrator')->table('settings')->where('key', $key)->delete();
    }

    expect($exception)->toBeInstanceOf(QueryException::class)
        ->and($exception->getCode())->toBe('25006')
        ->and(DB::connection('pgsql_ro')->table('settings')->count())->toBeInt();
})->group('phase0');

it('lets hq_app insert, update and delete a settings row', function () {
    $setting = Setting::create(['key' => 'grants_test_app', 'value' => 1]);

    $setting->update(['value' => 2]);
    expect(Setting::where('key', 'grants_test_app')->first()->value)->toBe(2);

    $setting->delete();
    expect(Setting::where('key', 'grants_test_app')->exists())->toBeFalse();
})->group('phase0');

it('does not let hq_app create tables', function () {
    $exception = null;

    DB::beginTransaction();

    try {
        DB::statement('CREATE TABLE grants_test_forbidden (id integer)');
    } catch (QueryException $e) {
        $exception = $e;
    } finally {
        DB::rollBack();
    }

    expect($exception)->toBeInstanceOf(QueryException::class)
        ->and($exception->getCode())->toBe('42501');
})->group('phase0');
