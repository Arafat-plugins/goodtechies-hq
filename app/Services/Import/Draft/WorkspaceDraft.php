<?php

namespace App\Services\Import\Draft;

/**
 * Everything a reader made of one export file: the work it could translate, and every row it
 * could not.
 *
 * This is the seam the whole command is built on. A ClickUp export and an Asana export share
 * almost no columns and do not even agree on what sits above a task — ClickUp's top row is the
 * client, Asana's is the project — so there is one reader each. But what they PRODUCE is the
 * same shape, and there is exactly one thing that writes it to the database. Two readers, one
 * writer: the rules about what a valid task is live in the writer, once, and adding a third
 * source cannot quietly acquire its own idea of them.
 *
 * `$issues` is carried here rather than raised as exceptions because a row the reader cannot use
 * must not stop the rows it can. Every one of them ends up as a line in the import report.
 */
final class WorkspaceDraft
{
    /**
     * @param  list<ProjectDraft>  $projects
     * @param  list<RowIssue>  $issues
     * @param  list<string>  $headers  the export's real column names
     */
    public function __construct(
        public readonly string $source,
        public readonly array $projects,
        public readonly array $issues,
        public readonly array $headers,
        public readonly int $rowsRead,
    ) {}

    public function taskCount(): int
    {
        return array_sum(array_map(
            fn (ProjectDraft $project): int => count($project->tasks),
            $this->projects,
        ));
    }
}
