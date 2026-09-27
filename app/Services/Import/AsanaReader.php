<?php

namespace App\Services\Import;

use App\Services\Import\Draft\ProjectDraft;
use App\Services\Import\Draft\RowIssue;
use App\Services\Import\Draft\TaskDraft;
use App\Services\Import\Draft\WorkspaceDraft;
use App\Support\ProjectStatus;
use App\Support\TaskPriority;
use App\Support\TaskStatus;

/**
 * Reads an Asana "Export project as CSV" and says what it would become here.
 *
 * ## It shares almost nothing with the ClickUp reader, and that is why it is a separate class
 *
 * The brief asked to say so plainly if the two shapes did not fit one pipeline. They do not:
 *
 *   - **Different hierarchy.** ClickUp's top-level task is the CLIENT and the work hangs off it.
 *     Asana has no such row: a file is one project, named in the `Projects` column, and every
 *     row in it is work. So a ClickUp import creates clients and an Asana import does not — and
 *     it must not invent one, because `projects.client_id` is nullable exactly so that a project
 *     with no customer can exist.
 *   - **Different status model.** ClickUp has a per-row `Status`. Asana has none: what a board
 *     column is called lives in `Section/Column`, and whether the work is finished lives in
 *     `Completed At` — a timestamp, not a status. Completion therefore comes from a DATE here
 *     and from a WORD there.
 *   - **Different parent link.** ClickUp's `Parent ID` points at a `Task ID`. Asana's
 *     `Parent task` holds the parent's NAME, which is not unique and can be blank on a re-export.
 *   - **No tracked time at all.** An Asana CSV has no time column unless the workspace has an
 *     Actual Time field, and a plain export has none. So the "Time tracked → manual time_entries"
 *     half of Part E's mapping has nothing to read, and this reader reports that rather than
 *     pretending it imported zero hours.
 *
 * What the two DO share is what they produce — a WorkspaceDraft — and there is one thing that
 * writes one of those to the database. Two readers, one writer. A shared reader that tried to
 * serve both would need a branch on the source in every method, which is two readers with extra
 * steps and one place for their rules to leak into each other.
 *
 * ## Subtasks
 *
 * Asana nests tasks; this application does not — a task belongs to a project and has a checklist,
 * not children. A row with a `Parent task` therefore becomes an ordinary task in the same
 * project, and the report says so per row so nobody discovers the flattening later.
 *
 * ## Everything here is an assumption until GATE A
 *
 * These are Asana's documented CSV export columns. The client's real export has not been through
 * this command.
 */
final class AsanaReader implements SourceReader
{
    private const ID = ['Task ID'];

    private const NAME = ['Name', 'Task Name'];

    private const NOTES = ['Notes', 'Description'];

    private const PROJECTS = ['Projects', 'Project'];

    private const SECTION = ['Section/Column', 'Section', 'Column'];

    private const ASSIGNEE = ['Assignee', 'Assignee Email'];

    private const DUE = ['Due Date'];

    private const START = ['Start Date'];

    private const COMPLETED = ['Completed At'];

    private const CREATED = ['Created At'];

    private const PARENT = ['Parent task', 'Parent Task'];

    private const PRIORITY = ['Priority'];

    /**
     * A section name is free text an Asana user typed, so this map is short and everything
     * outside it is a reported skip — never a guess, and never "put it in Backlog because we did
     * not understand it".
     *
     * A row with `Completed At` set short-circuits this entirely: in Asana, done is a date.
     */
    private const SECTIONS = [
        'to do' => TaskStatus::Todo,
        'todo' => TaskStatus::Todo,
        'new' => TaskStatus::Todo,
        'untitled section' => TaskStatus::Todo,
        'backlog' => TaskStatus::Backlog,
        'ideas' => TaskStatus::Backlog,
        'draft' => TaskStatus::Backlog,
        'in progress' => TaskStatus::InProgress,
        'doing' => TaskStatus::InProgress,
        'in review' => TaskStatus::InReview,
        'review' => TaskStatus::InReview,
        'waiting' => TaskStatus::Waiting,
        'blocked' => TaskStatus::Waiting,
        'on hold' => TaskStatus::Waiting,
        'done' => TaskStatus::Completed,
        'complete' => TaskStatus::Completed,
        'completed' => TaskStatus::Completed,
        'cancelled' => TaskStatus::Cancelled,
        'canceled' => TaskStatus::Cancelled,
    ];

    private const PRIORITIES = [
        'urgent' => TaskPriority::Urgent,
        'high' => TaskPriority::High,
        'medium' => TaskPriority::Medium,
        'normal' => TaskPriority::Medium,
        'low' => TaskPriority::Low,
    ];

    public function key(): string
    {
        return 'asana';
    }

    public function label(): string
    {
        return 'Asana';
    }

    public function columns(): array
    {
        return [
            'task id' => self::ID,
            'project' => self::PROJECTS,
            'title' => self::NAME,
            'description' => self::NOTES,
            'status (board column)' => self::SECTION,
            'status (done)' => self::COMPLETED,
            'priority' => self::PRIORITY,
            'assignee' => self::ASSIGNEE,
            'start date' => self::START,
            'due date' => self::DUE,
            'parent task' => self::PARENT,
        ];
    }

    public function statusMap(): array
    {
        $map = ['(any row with Completed At set)' => TaskStatus::Completed->label()];

        foreach (self::SECTIONS as $from => $to) {
            $map[$from] = $to->label();
        }

        return $map;
    }

    public function read(CsvFile $file): WorkspaceDraft
    {
        if (! $file->hasAnyColumn(...self::NAME)) {
            throw ImportFileException::notRecognised($file->path(), 'Asana', self::NAME);
        }

        if (! $file->hasAnyColumn(...self::PROJECTS)) {
            throw ImportFileException::notRecognised($file->path(), 'Asana', self::PROJECTS);
        }

        /** @var array<string, list<TaskDraft>> $byProject */
        $byProject = [];
        $issues = [];
        $read = 0;

        foreach ($file->rows() as $row) {
            $read++;

            $title = $row->get(...self::NAME);

            if ($title === null) {
                $issues[] = RowIssue::skipped($row->line, '(no name)', 'the row has no task name');

                continue;
            }

            // A row can belong to several projects. Asana writes them comma-separated; the
            // first is the one the export was taken from and the one this reader uses.
            $projects = $row->list(...self::PROJECTS);

            if ($projects === []) {
                $issues[] = RowIssue::skipped($row->line, $title, 'the row names no project, '
                    .'and this application has no home for a task outside one');

                continue;
            }

            if (count($projects) > 1) {
                $issues[] = RowIssue::unmapped($row->line, $title, sprintf(
                    'it is in %d Asana projects (%s); it was imported into the first only',
                    count($projects),
                    implode(', ', $projects),
                ));
            }

            $task = $this->task($row, $title, $issues);

            if ($task !== null) {
                $byProject[$projects[0]][] = $task;
            }
        }

        $projects = [];
        $line = 0;

        foreach ($byProject as $name => $tasks) {
            $line++;
            // An Asana export carries no status for the project itself — there is no row for the
            // project, only its name on every task. Active is not a guess about their data, it
            // is what ProjectService::create() makes every project anyway; nothing is moved.
            $projects[] = new ProjectDraft(
                $line,
                (string) $name,
                (string) $name,
                null,
                '(Asana has no project status)',
                ProjectStatus::Active,
                $tasks,
            );
        }

        return new WorkspaceDraft($this->label(), $projects, $issues, $file->headers(), $read);
    }

    /**
     * @param  list<RowIssue>  $issues
     */
    private function task(CsvRow $row, string $title, array &$issues): ?TaskDraft
    {
        $completedAt = $row->date(...self::COMPLETED);
        $section = $row->get(...self::SECTION);

        // Done is a date in Asana, and it outranks the column the card happens to be sitting in:
        // a task finished from the "In Progress" column is finished.
        $status = $completedAt !== null
            ? TaskStatus::Completed
            : (self::SECTIONS[mb_strtolower(trim((string) $section))] ?? null);

        if ($status === null) {
            $issues[] = RowIssue::skipped($row->line, $title, $section === null
                ? 'the row has no Section/Column and no Completed At, so there is nothing to read a status from'
                : sprintf('section "%s" is not mapped', $section));

            return null;
        }

        if ($row->get(...self::PARENT) !== null) {
            $issues[] = RowIssue::unmapped($row->line, $title, sprintf(
                'it is a subtask of "%s"; this application does not nest tasks, so it was '
                .'imported as an ordinary task in the same project',
                (string) $row->get(...self::PARENT),
            ));
        }

        $rawStatus = $completedAt !== null
            ? 'Completed At '.$completedAt->toDateString()
            : (string) $section;

        return new TaskDraft(
            $row->line,
            $row->get(...self::ID) ?? $title,
            $title,
            $row->get(...self::NOTES),
            $rawStatus,
            $status,
            self::PRIORITIES[mb_strtolower(trim((string) $row->get(...self::PRIORITY)))] ?? TaskPriority::Medium,
            $row->date(...self::START) ?? $row->date(...self::CREATED),
            $row->date(...self::DUE),
            $row->get(...self::ASSIGNEE),
            // Asana exports no tracked time. Not zero-because-nobody-worked: zero because the
            // column does not exist. WorkspaceImporter reports it once for the whole run.
            0,
        );
    }
}
