<?php

namespace App\Console\Commands;

use App\Services\SettingsService;
use App\Support\Permission;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Weekly restore test: restores the newest backup into a scratch database, runs smoke checks
 * and records settings.backup_last_verified_at when everything passes.
 */
#[Signature('hq:verify-backup {--disk= : Disk to read the newest backup from (default: the configured backup disk)}')]
#[Description('Restore the newest database backup into a scratch database and verify it')]
class VerifyBackup extends Command
{
    private const SCRATCH_DATABASE = 'goodtechies_verify';

    private const SCRATCH_CONNECTION = 'hq_verify_scratch';

    private const MIGRATOR_CONNECTION = 'pgsql_migrator';

    /**
     * A dump entry inside the archive, e.g. db-dumps/postgresql-goodtechies_hq.sql.gz.
     */
    private const DUMP_ENTRY_PATTERN = '#^db-dumps/[A-Za-z0-9._-]+\.sql(\.gz)?$#';

    private const RESTORE_TIMEOUT_SECONDS = 3600;

    public function handle(SettingsService $settings): int
    {
        $workDir = null;
        $scratchRequested = false;

        try {
            $password = (string) config('backup.backup.password');

            if ($password === '') {
                throw new RuntimeException('BACKUP_ARCHIVE_PASSWORD is not set, so the encrypted backup cannot be opened.');
            }

            $diskName = (string) ($this->option('disk') ?: Arr::first((array) config('backup.backup.destination.disks')));
            $disk = Storage::disk($diskName);
            $backupPath = $this->newestBackupPath($disk, $diskName);
            $backupSize = (int) $disk->size($backupPath);

            $workDir = $this->makeWorkDirectory();
            $archive = $workDir.'/backup.zip';
            $this->download($disk, $backupPath, $archive);

            $dumpFile = $this->extractDump($archive, $backupPath, $password, $workDir);

            $scratchRequested = true;
            $this->createScratchDatabase();
            $this->restore($dumpFile);
            $counts = $this->smokeCheck();

            $settings->recordBackupVerified(now());

            $this->info(sprintf(
                'Backup verified: %s:%s (%s) restored into %s, roles=%d permissions=%d settings=%d users=%d audit_logs=present',
                $diskName,
                $backupPath,
                Number::fileSize($backupSize, 1),
                self::SCRATCH_DATABASE,
                $counts['roles'],
                $counts['permissions'],
                $counts['settings'],
                $counts['users'],
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $message = 'Backup verification failed: '.$e->getMessage();

            $this->error($message);
            Log::error($message, ['exception' => $e]);

            return self::FAILURE;
        } finally {
            $this->cleanUp($workDir, $scratchRequested);
        }
    }

    private function newestBackupPath(Filesystem $disk, string $diskName): string
    {
        $backupName = (string) config('backup.backup.name');

        $newest = collect($disk->allFiles($backupName))
            ->filter(fn (string $path) => str_ends_with(strtolower($path), '.zip'))
            ->map(fn (string $path) => ['path' => $path, 'modified' => $disk->lastModified($path)])
            ->sortBy([['modified', 'desc'], ['path', 'desc']])
            ->first();

        if ($newest === null) {
            throw new RuntimeException("No backup archive found under \"{$backupName}/\" on disk \"{$diskName}\".");
        }

        return $newest['path'];
    }

    private function makeWorkDirectory(): string
    {
        $dir = rtrim((string) config('backup.backup.temporary_directory'), '/').'/verify-'.Str::random(16);

        if (! File::makeDirectory($dir, 0700, true)) {
            throw new RuntimeException("Could not create the temporary directory {$dir}.");
        }

        return $dir;
    }

    private function download(Filesystem $disk, string $path, string $target): void
    {
        $source = $disk->readStream($path);

        if (! is_resource($source)) {
            throw new RuntimeException("Could not read {$path} from the backup disk.");
        }

        $destination = fopen($target, 'wb');

        try {
            if ($destination === false || stream_copy_to_stream($source, $destination) === false) {
                throw new RuntimeException("Could not download {$path}.");
            }
        } finally {
            fclose($source);

            if (is_resource($destination)) {
                fclose($destination);
            }
        }
    }

    /**
     * Extracts the single database dump from the encrypted archive and returns the path of
     * the plain SQL file.
     */
    private function extractDump(string $archive, string $backupPath, string $password, string $workDir): string
    {
        $zip = new ZipArchive;
        $opened = $zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS);

        if ($opened !== true) {
            throw new RuntimeException("The backup archive {$backupPath} cannot be opened (zip error {$opened}).");
        }

        try {
            $entries = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (preg_match(self::DUMP_ENTRY_PATTERN, $name) === 1) {
                    $entries[] = $name;
                }
            }

            if (count($entries) !== 1) {
                throw new RuntimeException("Expected exactly one db-dumps/*.sql(.gz) entry in {$backupPath}, found ".count($entries).'.');
            }

            $entry = $entries[0];
            $zip->setPassword($password);

            if (! $zip->extractTo($workDir, $entry)) {
                throw new RuntimeException("Could not extract {$entry} from {$backupPath}: wrong archive password or damaged archive ({$zip->getStatusString()}).");
            }
        } finally {
            $zip->close();
        }

        $extracted = $workDir.'/'.$entry;

        if (! str_ends_with($entry, '.gz')) {
            return $extracted;
        }

        $sqlFile = $workDir.'/dump.sql';
        $this->gunzip($extracted, $sqlFile);
        File::delete($extracted);

        return $sqlFile;
    }

    private function gunzip(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = fopen($target, 'wb');
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);

        try {
            if ($in === false || $out === false || $inflate === false) {
                throw new RuntimeException('Could not open the database dump for decompression.');
            }

            while (! feof($in)) {
                $chunk = fread($in, 1024 * 1024);

                if ($chunk === false) {
                    throw new RuntimeException('Could not read the compressed database dump.');
                }

                $data = inflate_add($inflate, $chunk, ZLIB_SYNC_FLUSH);

                if ($data === false || fwrite($out, $data) === false) {
                    throw new RuntimeException('Could not decompress the database dump.');
                }
            }

            if (inflate_get_status($inflate) !== ZLIB_STREAM_END) {
                throw new RuntimeException('The compressed database dump is truncated.');
            }
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }

            if (is_resource($out)) {
                fclose($out);
            }
        }
    }

    private function migrator(): Connection
    {
        return DB::connection(self::MIGRATOR_CONNECTION);
    }

    private function createScratchDatabase(): void
    {
        $migrator = $this->migrator();
        $grammar = $migrator->getQueryGrammar();
        $owner = $grammar->wrap((string) $migrator->getConfig('username'));
        $database = $grammar->wrap(self::SCRATCH_DATABASE);

        $migrator->statement("DROP DATABASE IF EXISTS {$database}");
        $migrator->statement("CREATE DATABASE {$database} OWNER {$owner} TEMPLATE template0");
    }

    private function restore(string $sqlFile): void
    {
        $config = $this->migrator()->getConfig();

        $process = new Process(
            [
                'psql',
                '--no-psqlrc',
                '--quiet',
                '--single-transaction',
                '--set=ON_ERROR_STOP=1',
                '--host='.Arr::first(Arr::wrap($config['host'] ?? '127.0.0.1')),
                '--port='.($config['port'] ?? 5432),
                '--username='.$config['username'],
                '--dbname='.self::SCRATCH_DATABASE,
                '--file='.$sqlFile,
            ],
            null,
            [
                'PGPASSWORD' => (string) ($config['password'] ?? ''),
                'PGSSLMODE' => (string) ($config['sslmode'] ?? 'prefer'),
            ],
            null,
            self::RESTORE_TIMEOUT_SECONDS,
        );

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('psql restore failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /**
     * @return array{roles: int, permissions: int, settings: int, users: int}
     */
    private function smokeCheck(): array
    {
        config(['database.connections.'.self::SCRATCH_CONNECTION => array_merge(
            Arr::except($this->migrator()->getConfig(), ['name']),
            ['url' => null, 'database' => self::SCRATCH_DATABASE],
        )]);

        $scratch = DB::connection(self::SCRATCH_CONNECTION);

        if (! $scratch->getSchemaBuilder()->hasTable('audit_logs')) {
            throw new RuntimeException('Smoke check failed: the audit_logs table is missing.');
        }

        $counts = [];

        foreach (['roles', 'permissions', 'settings', 'users'] as $table) {
            $counts[$table] = $scratch->table($table)->count();
        }

        $expectations = [
            'roles' => [count(RolePermissionSeeder::MATRIX), true],
            'permissions' => [count(Permission::cases()), true],
            'settings' => [count(SettingsSeeder::DEFAULTS), false],
            'users' => [1, false],
        ];

        foreach ($expectations as $table => [$expected, $exact]) {
            $ok = $exact ? $counts[$table] === $expected : $counts[$table] >= $expected;

            if (! $ok) {
                throw new RuntimeException(sprintf(
                    'Smoke check failed: %s has %d rows, expected %s%d.',
                    $table,
                    $counts[$table],
                    $exact ? '' : 'at least ',
                    $expected,
                ));
            }
        }

        return $counts;
    }

    private function cleanUp(?string $workDir, bool $scratchRequested): void
    {
        DB::purge(self::SCRATCH_CONNECTION);
        config(['database.connections.'.self::SCRATCH_CONNECTION => null]);

        if ($scratchRequested) {
            try {
                $database = $this->migrator()->getQueryGrammar()->wrap(self::SCRATCH_DATABASE);
                $this->migrator()->statement("DROP DATABASE IF EXISTS {$database}");
            } catch (Throwable $e) {
                $message = 'Backup verification could not drop the scratch database: '.$e->getMessage();
                $this->error($message);
                Log::error($message, ['exception' => $e]);
            }
        }

        if ($workDir !== null) {
            File::deleteDirectory($workDir);
        }
    }
}
