<?php

it('encrypts backup archives with the password from the environment', function () {
    expect(config('backup.backup.encryption'))->toBe('default')
        ->and(config('backup.backup.password'))->not->toBeEmpty()
        ->and(config('backup.backup.password'))->toBe(env('BACKUP_ARCHIVE_PASSWORD'));
})->group('phase0');

it('stores backups on the off-provider backups disk', function () {
    expect(config('backup.backup.destination.disks'))->toBe(['backups'])
        ->and(config('backup.monitor_backups.0.disks'))->toBe(['backups'])
        ->and(config('filesystems.disks.backups.driver'))->toBe('s3')
        ->and(config('filesystems.disks.backups.throw'))->toBeTrue();
})->group('phase0');

it('keeps 14 daily, 8 weekly and 12 monthly backups', function () {
    expect(config('backup.cleanup.default_strategy'))->toMatchArray([
        'keep_all_backups_for_days' => 7,
        'keep_daily_backups_for_days' => 14,
        'keep_weekly_backups_for_weeks' => 8,
        'keep_monthly_backups_for_months' => 12,
        'keep_yearly_backups_for_years' => 2,
        'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
    ]);
})->group('phase0');

it('dumps the database through the schema owner and includes no files', function () {
    expect(config('backup.backup.source.databases'))->toBe(['pgsql_migrator'])
        ->and(config('backup.backup.source.files.include'))->toBe([]);
})->group('phase0');
