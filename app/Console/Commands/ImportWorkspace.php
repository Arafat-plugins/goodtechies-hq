<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Import\AsanaReader;
use App\Services\Import\ClickUpReader;
use App\Services\Import\CsvFile;
use App\Services\Import\ImportFileException;
use App\Services\Import\ImportReport;
use App\Services\Import\SourceReader;
use App\Services\Import\WorkspaceImporter;
use App\Support\RoleName;
use App\Support\UserStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * `hq:import --from=clickup|asana --file=…csv` — the cutover (Part E, Phase 12).
 *
 * The client stops using ClickUp and starts using this, and this command is how their work comes
 * across. Part E's mapping: ClickUp task → Client + Project, subtask → Task, statuses mapped,
 * "Time tracked" → a manual `time_entries` row with the reason "imported from ClickUp".
 *
 * ## `--dry-run` is the feature, not a flag
 *
 * The first run against their real export is the one that matters, and nobody should have to
 * trust it blind. So a dry run performs the ENTIRE import — same services, same policies, same
 * state machine, same constraints — inside a transaction, prints the report, and rolls back. The
 * report a dry run prints was produced by the code that writes the rows, which is why it cannot
 * describe something a real run would not do.
 *
 * Run it, read the report, and only then run it for real. Read the created-CLIENTS line first:
 * a ClickUp export has no column that says which top-level rows are customers, so it is the one
 * thing a person has to check rather than the command.
 *
 * ## It is not done
 *
 * Part E's "done when" is *"the client's real ClickUp/Asana export imports cleanly"*. **We do not
 * have their export** — it is still an open GATE A item. Every column name this command looks for
 * is an assumption taken from ClickUp's and Asana's documented CSV exports, it is printed at the
 * bottom of every report beside the columns the file really had, and `--help` lists them. Until
 * their file has been through this, the criterion is not met.
 */
#[Signature(<<<'SIGNATURE'
hq:import
    {--from= : clickup or asana — which export this file is}
    {--file= : path to the exported CSV}
    {--dry-run : do the whole import inside a transaction, print the report, roll it back}
    {--as= : e-mail of the Admin to import as (default: the first active Admin)}
    {--notify : also send the notifications and realtime broadcasts the writes would normally produce}
SIGNATURE)]
#[Description('Import a ClickUp or Asana CSV export into clients, projects, tasks and tracked time')]
class ImportWorkspace extends Command
{
    public function __construct()
    {
        // Built from the readers rather than written out here, so the documented columns and the
        // columns the code looks for cannot drift apart.
        $this->help = self::helpText();

        parent::__construct();
    }

    public function handle(WorkspaceImporter $importer): int
    {
        $reader = $this->reader();

        if ($reader === null) {
            return self::FAILURE;
        }

        $path = trim((string) $this->option('file'));

        if ($path === '') {
            $this->error('Say which file to import: --file=path/to/export.csv');

            return self::FAILURE;
        }

        $actor = $this->actor();

        if ($actor === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $draft = $reader->read(new CsvFile($path));
        } catch (ImportFileException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $report = $importer->run(
            $draft,
            $actor,
            $dryRun,
            $path,
            $reader->columns(),
            (bool) $this->option('notify'),
        );

        foreach ($report->lines() as $line) {
            $this->print($line);
        }

        $this->closing($report, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Write one report line, byte for byte.
     *
     * `$this->line()` goes through `OutputStyle`, which collapses runs of spaces — harmless for
     * a sentence, fatal for a report whose columns are held apart by `sprintf` padding ("File
     * tests/…" instead of "File      tests/…", and every per-project row ragged). The report is
     * a fixed-width document, so it goes to the underlying output untouched. Everything that is
     * a SENTENCE — the errors and the closing summary — still uses the styled helpers.
     *
     * The buffered output `Artisan::call()` installs is reached the same way, so a test that
     * reads `Artisan::output()` sees exactly what a terminal does.
     */
    private function print(string $line): void
    {
        $this->output->getOutput()->writeln($line);
    }

    private function closing(ImportReport $report, bool $dryRun): void
    {
        $this->newLine();

        if ($dryRun) {
            $this->warn('DRY RUN — the transaction was rolled back. Nothing above was written.');
            $this->line('Read the created-clients line before running this for real: no ClickUp export');
            $this->line('says which top-level rows are customers, so that is the one thing this command');
            $this->line('cannot check for you.');

            return;
        }

        if ($report->createdNothing()) {
            $this->info('Nothing was created — everything in this export is already here.');

            return;
        }

        $created = $report->createdCounts();

        $this->info(sprintf(
            'Imported: %d client(s), %d project(s), %d task(s), %d time entr%s.',
            $created['clients'],
            $created['projects'],
            $created['tasks'],
            $created['time_entries'],
            $created['time_entries'] === 1 ? 'y' : 'ies',
        ));
    }

    private function reader(): ?SourceReader
    {
        $from = mb_strtolower(trim((string) $this->option('from')));

        foreach (self::readers() as $reader) {
            if ($reader->key() === $from) {
                return $reader;
            }
        }

        $this->error($from === ''
            ? 'Say which export this is: --from=clickup or --from=asana'
            : sprintf('Unknown source "%s". Use --from=clickup or --from=asana.', $from));

        return null;
    }

    /**
     * Who the import acts as.
     *
     * An Admin, and the command says why rather than assuming: the import creates clients,
     * projects and tasks and COMPLETES the ones the export says are finished, and completing is
     * a review verdict that only a project's reviewer may pass. Running as anybody else would
     * produce a run that half worked, which is worse than one that refuses.
     */
    private function actor(): ?User
    {
        $email = trim((string) $this->option('as'));

        $query = User::query()
            ->where('status', UserStatus::Active->value)
            ->whereHas('employee.role', fn (Builder $role) => $role->where('name', RoleName::ADMIN->value));

        if ($email !== '') {
            $user = (clone $query)->where('email', $email)->first();

            if ($user === null) {
                $this->error(sprintf('%s is not an active Admin on this installation.', $email));

                return null;
            }

            return $user;
        }

        $user = $query->orderBy('id')->first();

        if ($user === null) {
            $this->error('There is no active Admin to import as. Seed the team first, or pass --as=<email>.');

            return null;
        }

        $this->line(sprintf('Importing as %s <%s>.', $user->name, $user->email));

        return $user;
    }

    /**
     * @return list<SourceReader>
     */
    private static function readers(): array
    {
        return [new ClickUpReader, new AsanaReader];
    }

    private static function helpText(): string
    {
        $out = [
            'THE MAPPING',
            '  ClickUp  top-level task -> a Client AND a Project of the same name',
            '           subtask        -> a Task in that project',
            '           Time tracked   -> a manual time_entries row, reason "'.WorkspaceImporter::TIME_REASON.'"',
            '  Asana    Projects column-> a Project with NO client (Asana has nothing above a project)',
            '           every row      -> a Task; a subtask becomes an ordinary task, reported per row',
            '           tracked time   -> none; a plain Asana CSV has no time column at all',
            '',
            'WHAT IT WILL NOT DO',
            '  - create a user. An assignee that matches no employee is reported and the task is unassigned.',
            '  - guess a status. One that is not in the map below is a reported skip.',
            '  - write a row without going through the services, so it obeys every policy you do.',
            '  - run twice. Clients key on name, projects on (client, name), tasks on (project, title),',
            '    time entries on (task, employee, manual, the reason above).',
            '',
            'ASSUMED COLUMNS — the client\'s real export has NOT been through this command (GATE A).',
            '  Each is tried in order; the first the file has is used. The report prints these beside',
            '  the columns the file really had.',
        ];

        foreach (self::readers() as $reader) {
            $out[] = '';
            $out[] = '  '.$reader->label();

            foreach ($reader->columns() as $what => $aliases) {
                $out[] = sprintf('    %-22s %s', $what, implode('  |  ', $aliases));
            }

            $out[] = '';
            $out[] = '  '.$reader->label().' statuses that are mapped (anything else is skipped and reported)';

            foreach ($reader->statusMap() as $from => $to) {
                $out[] = sprintf('    %-30s -> %s', $from, $to);
            }
        }

        return implode(PHP_EOL, $out);
    }
}
