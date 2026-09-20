<?php

use App\Models\Setting;
use App\Services\SettingsService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

const VERIFY_TEST_DISK = 'verify-test';

beforeEach(function () {
    $this->diskRoot = sys_get_temp_dir().'/hq-verify-test-'.Str::random(12);
    File::makeDirectory($this->diskRoot, 0700, true);

    config([
        'filesystems.disks.'.VERIFY_TEST_DISK => ['driver' => 'local', 'root' => $this->diskRoot],
        'backup.backup.destination.disks' => [VERIFY_TEST_DISK],
        'backup.backup.password' => 'verify-test-archive-password',
    ]);

    $this->backupDir = $this->diskRoot.'/'.config('backup.backup.name');
    File::makeDirectory($this->backupDir, 0700, true);
});

afterEach(function () {
    File::deleteDirectory($this->diskRoot);
});

function lastVerifiedRow(): mixed
{
    return Setting::query()->where('key', 'backup_last_verified_at')->value('value');
}

function verifyBackup(): int
{
    return Artisan::call('hq:verify-backup', ['--disk' => VERIFY_TEST_DISK]);
}

function scratchDatabaseExists(): bool
{
    return DB::connection('pgsql_migrator')
        ->table('pg_database')
        ->where('datname', 'goodtechies_verify')
        ->exists();
}

/**
 * An AES-256 archive shaped like a spatie backup, for the failure cases that need no real dump.
 */
function writeEncryptedArchive(string $path, string $password): void
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('db-dumps/postgresql-goodtechies_hq_test.sql.gz', gzencode('SELECT 1;'));
    $zip->setEncryptionName('db-dumps/postgresql-goodtechies_hq_test.sql.gz', ZipArchive::EM_AES_256, $password);
    $zip->close();
}

it('restores the newest backup into a scratch database and records the verification', function () {
    // pg_dump runs in its own session and only sees committed rows, so this test leaves the
    // RefreshDatabase wrapper transaction: it rolls that transaction back on the default
    // connection and seeds through pgsql_migrator, which commits. On teardown RefreshDatabase
    // sees the connection is no longer in a transaction and resets RefreshDatabaseState::$migrated,
    // so the next test runs migrate:fresh and starts from an empty schema again.
    DB::connection()->rollBack();
    $this->artisan('db:seed', ['--database' => 'pgsql_migrator', '--force' => true])->assertSuccessful();

    // --config makes spatie re-read config('backup'), which this test changed at runtime.
    $this->artisan('backup:run', ['--only-db' => true, '--disable-notifications' => true, '--config' => 'backup'])
        ->assertSuccessful();

    expect(File::files($this->backupDir))->toHaveCount(1);

    $this->freezeSecond(function (Carbon $now) {
        expect(verifyBackup())->toBe(0);

        $output = Artisan::output();

        expect($output)->toContain('Backup verified: verify-test:'.config('backup.backup.name').'/')
            ->and($output)->toContain('restored into goodtechies_verify, roles=5 permissions=23 settings=11 users=')
            ->and(lastVerifiedRow())->toBe($now->toIso8601String())
            ->and(app(SettingsService::class)->get('backup_last_verified_at'))->toBe($now->toIso8601String());
    });

    expect(scratchDatabaseExists())->toBeFalse()
        ->and(File::glob(config('backup.backup.temporary_directory').'/verify-*'))->toBe([]);
})->group('phase0');

it('fails without touching the setting when the disk holds no backup', function () {
    $this->seed(SettingsSeeder::class);
    app(SettingsService::class)->recordBackupVerified(Carbon::parse('2026-01-04 04:00:00'));
    $before = lastVerifiedRow();

    expect(verifyBackup())->toBe(1)
        ->and(Artisan::output())->toContain('Backup verification failed: No backup archive found')
        ->and(lastVerifiedRow())->toBe($before)
        ->and(scratchDatabaseExists())->toBeFalse();
})->group('phase0');

it('fails when the archive password is wrong', function () {
    $this->seed(SettingsSeeder::class);
    writeEncryptedArchive($this->backupDir.'/2026-09-13-02-00-00.zip', 'another-password');

    expect(verifyBackup())->toBe(1)
        ->and(Artisan::output())->toContain('wrong archive password')
        ->and(lastVerifiedRow())->toBeNull()
        ->and(scratchDatabaseExists())->toBeFalse()
        ->and(File::glob(config('backup.backup.temporary_directory').'/verify-*'))->toBe([]);
})->group('phase0');

it('fails when the newest archive is corrupt', function () {
    $this->seed(SettingsSeeder::class);
    writeEncryptedArchive($this->backupDir.'/2026-09-13-02-00-00.zip', 'verify-test-archive-password');
    touch($this->backupDir.'/2026-09-13-02-00-00.zip', time() - 3600);
    File::put($this->backupDir.'/2026-09-14-02-00-00.zip', 'PK this is not a zip archive');

    expect(verifyBackup())->toBe(1)
        ->and(Artisan::output())->toContain('2026-09-14-02-00-00.zip cannot be opened')
        ->and(lastVerifiedRow())->toBeNull()
        ->and(scratchDatabaseExists())->toBeFalse();
})->group('phase0');

it('fails clearly when the archive password is not configured', function () {
    config(['backup.backup.password' => null]);
    writeEncryptedArchive($this->backupDir.'/2026-09-13-02-00-00.zip', 'verify-test-archive-password');

    expect(verifyBackup())->toBe(1)
        ->and(Artisan::output())->toContain('BACKUP_ARCHIVE_PASSWORD is not set');
})->group('phase0');
