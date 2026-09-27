<?php

namespace App\Services\Import;

use App\Services\Import\Draft\RowIssue;
use App\Services\Import\Draft\WorkspaceDraft;

/**
 * What the import did, or — in a dry run — what it would have done.
 *
 * ## One report, from one run
 *
 * There is no "predict" pass and no "explain" pass. A dry run executes the real import inside a
 * transaction and rolls it back, filling this object on the way through, so the sentence a dry
 * run prints was produced by the code that would have written the row. A separate estimator
 * could disagree with the writer; this cannot, because there is only one of them.
 *
 * ## Four buckets, and the last two are the point
 *
 * created / already there / skipped / could not be mapped. A report that says "312 rows
 * imported" is a report nobody can check: the questions a person actually has at cutover are
 * *which* clients am I about to create, *what* did you refuse, and *what* arrived incomplete.
 * Skips and unmappables are listed **per row, with the file's line number**, never counted.
 *
 * The one thing that is summarised rather than listed is the tasks: a real export has hundreds,
 * and a per-project count with the created names is readable where three hundred lines are not.
 * Every task that did NOT arrive whole is still listed individually.
 */
final class ImportReport
{
    /** @var list<string> */
    private array $clientsCreated = [];

    /** @var list<string> */
    private array $clientsMatched = [];

    /** @var list<string> */
    private array $projectsCreated = [];

    /** @var list<string> */
    private array $projectsMatched = [];

    /**
     * project name => [created, existing, time entries, seconds]
     *
     * @var array<string, array{created: int, existing: int, entries: int, seconds: int, timeExisting: int}>
     */
    private array $perProject = [];

    /** @var list<RowIssue> */
    private array $issues = [];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(
        public readonly WorkspaceDraft $draft,
        public readonly bool $dryRun,
        public readonly string $path,
        public readonly string $actor,
        /** @var array<string, list<string>> */
        public readonly array $assumedColumns,
    ) {
        $this->issues = $draft->issues;
    }

    public function note(string $line): void
    {
        if (! in_array($line, $this->notes, true)) {
            $this->notes[] = $line;
        }
    }

    public function skipped(int $line, string $subject, string $why): void
    {
        $this->issues[] = RowIssue::skipped($line, $subject, $why);
    }

    public function unmapped(int $line, string $subject, string $why): void
    {
        $this->issues[] = RowIssue::unmapped($line, $subject, $why);
    }

    public function clientCreated(string $name): void
    {
        $this->clientsCreated[] = $name;
    }

    public function clientMatched(string $name): void
    {
        $this->clientsMatched[] = $name;
    }

    public function projectCreated(string $name): void
    {
        $this->projectsCreated[] = $name;
        $this->bucket($name);
    }

    public function projectMatched(string $name): void
    {
        $this->projectsMatched[] = $name;
        $this->bucket($name);
    }

    public function taskCreated(string $project): void
    {
        $this->bucket($project);
        $this->perProject[$project]['created']++;
    }

    public function taskMatched(string $project): void
    {
        $this->bucket($project);
        $this->perProject[$project]['existing']++;
    }

    public function timeCreated(string $project, int $seconds): void
    {
        $this->bucket($project);
        $this->perProject[$project]['entries']++;
        $this->perProject[$project]['seconds'] += $seconds;
    }

    public function timeMatched(string $project, int $rows = 1): void
    {
        $this->bucket($project);
        $this->perProject[$project]['timeExisting'] += $rows;
    }

    public function createdCounts(): array
    {
        return [
            'clients' => count($this->clientsCreated),
            'projects' => count($this->projectsCreated),
            'tasks' => array_sum(array_column($this->perProject, 'created')),
            'time_entries' => array_sum(array_column($this->perProject, 'entries')),
        ];
    }

    public function existingCounts(): array
    {
        return [
            'clients' => count($this->clientsMatched),
            'projects' => count($this->projectsMatched),
            'tasks' => array_sum(array_column($this->perProject, 'existing')),
            'time_entries' => array_sum(array_column($this->perProject, 'timeExisting')),
        ];
    }

    /**
     * @return list<RowIssue>
     */
    public function issues(string $kind): array
    {
        return array_values(array_filter($this->issues, fn (RowIssue $one): bool => $one->kind === $kind));
    }

    /**
     * Did this run change nothing? The question a second run has to be able to answer yes to.
     */
    public function createdNothing(): bool
    {
        return array_sum($this->createdCounts()) === 0;
    }

    /**
     * The whole report as printable lines.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $out = [];
        $created = $this->createdCounts();
        $existing = $this->existingCounts();

        $out[] = sprintf(
            'Import report — %s%s',
            $this->draft->source,
            $this->dryRun ? '  (DRY RUN — the database was rolled back, nothing was written)' : '',
        );
        $out[] = str_repeat('=', 78);
        $out[] = sprintf('File      %s', $this->path);
        $out[] = sprintf('Rows read %d', $this->draft->rowsRead);
        $out[] = sprintf('Acting as %s', $this->actor);
        $out[] = '';

        $out[] = $this->dryRun ? 'WOULD CREATE' : 'CREATED';
        $out[] = sprintf('  Clients       %4d%s', $created['clients'], $this->names($this->clientsCreated));
        $out[] = sprintf('  Projects      %4d%s', $created['projects'], $this->names($this->projectsCreated));
        $out[] = sprintf('  Tasks         %4d', $created['tasks']);
        $out[] = $created['time_entries'] === 0
            ? '  Time entries     0'
            : sprintf(
                '  Time entries  %4d   %s, manual, reason "%s"',
                $created['time_entries'],
                self::duration(array_sum(array_column($this->perProject, 'seconds'))),
                WorkspaceImporter::TIME_REASON,
            );
        $out[] = '';

        $out[] = 'ALREADY THERE (matched, left exactly as they were)';
        $out[] = sprintf('  Clients       %4d%s', $existing['clients'], $this->names($this->clientsMatched));
        $out[] = sprintf('  Projects      %4d%s', $existing['projects'], $this->names($this->projectsMatched));
        $out[] = sprintf('  Tasks         %4d', $existing['tasks']);
        $out[] = sprintf('  Time entries  %4d', $existing['time_entries']);
        $out[] = '';

        if ($this->perProject !== []) {
            $names = array_map(strval(...), array_keys($this->perProject));
            $width = max(7, max(array_map(mb_strlen(...), $names)));

            $out[] = sprintf('PER PROJECT%s  new tasks   already there   new time entries', str_repeat(' ', max(0, $width - 9)));

            foreach ($this->perProject as $name => $counts) {
                $out[] = sprintf(
                    '  %-'.$width.'s  %9d   %13d   %s',
                    (string) $name,
                    $counts['created'],
                    $counts['existing'],
                    $counts['entries'] === 0
                        ? ($counts['timeExisting'] === 0 ? '-' : sprintf('0 (%d already there)', $counts['timeExisting']))
                        : sprintf('%d (%s)', $counts['entries'], self::duration($counts['seconds'])),
                );
            }

            $out[] = '';
        }

        $out = array_merge($out, $this->issueBlock(
            RowIssue::SKIPPED,
            'SKIPPED — nothing about these rows is in the database',
            'Nothing was skipped.',
        ));

        $out = array_merge($out, $this->issueBlock(
            RowIssue::UNMAPPED,
            'COULD NOT BE MAPPED — the row was imported, the named part of it was not',
            'Every row that was imported arrived whole.',
        ));

        if ($this->notes !== []) {
            $out[] = 'NOTES';

            foreach ($this->notes as $note) {
                $out[] = '  - '.$note;
            }

            $out[] = '';
        }

        $out[] = 'ASSUMED COLUMNS — the client\'s real export has NOT been through this command';
        $out[] = '  (GATE A). Each is tried in order; the first one the file has is used.';

        foreach ($this->assumedColumns as $what => $aliases) {
            $out[] = sprintf('  %-22s %s', $what, implode('  |  ', $aliases));
        }

        $out[] = '';
        $out[] = 'COLUMNS THIS FILE ACTUALLY HAS';
        $out[] = '  '.implode(', ', $this->draft->headers);

        return $out;
    }

    /**
     * @return list<string>
     */
    private function issueBlock(string $kind, string $title, string $whenEmpty): array
    {
        $issues = $this->issues($kind);

        if ($issues === []) {
            return [$title, '  '.$whenEmpty, ''];
        }

        usort($issues, fn (RowIssue $a, RowIssue $b): int => $a->line <=> $b->line);

        $out = [sprintf('%s — %d', $title, count($issues))];
        $width = max(array_map(fn (RowIssue $one): int => mb_strlen($one->subject), $issues));
        $width = min($width, 44);

        foreach ($issues as $issue) {
            $out[] = sprintf(
                '  line %-5d %-'.$width.'s  %s',
                $issue->line,
                mb_strimwidth($issue->subject, 0, $width, '…'),
                $issue->why,
            );
        }

        $out[] = '';

        return $out;
    }

    private function bucket(string $project): void
    {
        $this->perProject[$project] ??= [
            'created' => 0,
            'existing' => 0,
            'entries' => 0,
            'seconds' => 0,
            'timeExisting' => 0,
        ];
    }

    /**
     * @param  list<string>  $names
     */
    private function names(array $names): string
    {
        if ($names === []) {
            return '';
        }

        $shown = array_slice($names, 0, 8);
        $more = count($names) - count($shown);

        return '   '.implode(', ', $shown).($more > 0 ? sprintf(' … and %d more', $more) : '');
    }

    public static function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0m';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours === 0 ? sprintf('%dm', $minutes) : sprintf('%dh %02dm', $hours, $minutes);
    }
}
