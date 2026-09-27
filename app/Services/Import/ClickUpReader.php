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
 * Reads a ClickUp "export view as CSV" and says what it would become here.
 *
 * ## The shape, from the client's own workspace
 *
 * `docs/design-refs/10-clickup-current-workspace.png` is their live List view: Space *Marketing*
 * → List *PROJECTS*, grouped by status, with **the top-level task as the client** — Buffalo
 * Modular (10 subtasks), APH (11), Abbey Heating (7), Woodfordoil (4) — and the real work as
 * subtasks. Part E's mapping follows from that and is what this reader implements:
 *
 *   top-level task  →  a Client AND a Project of the same name
 *   subtask         →  a Task in that project
 *   status          →  mapped, or the row is skipped and reported
 *   Time Logged     →  a manual `time_entries` row, reason "imported from ClickUp"
 *
 * ## What the screenshot also shows, and the mapping does not cover
 *
 * Not every top-level row in that list is a client. *Keyword Selection*, *SITES CREDENTIALS*,
 * *Primary keyword research*, *SEO sheet Global template* are top-level rows too, and they are
 * internal work, not customers. **Nothing in the export distinguishes them** — there is no
 * column that says "this one is a client". This reader therefore does what the mapping says and
 * turns every parent row into a client, and the dry run's created-clients list is where a person
 * catches it. A top-level row with no subtasks is the one case it refuses: a client with no work
 * is not a project, so it is reported as unmappable rather than created empty.
 *
 * ## Everything here is an assumption until GATE A
 *
 * The client's real export has not been run through this. The column names below are ClickUp's
 * documented CSV export columns; each is tried with aliases, and the report prints both what was
 * assumed and what the file actually had.
 */
final class ClickUpReader implements SourceReader
{
    /**
     * The identity column, and the one this reader cannot work without: `Parent ID` points at it,
     * and that link is the whole hierarchy.
     */
    private const ID = ['Task ID', 'Task Custom ID', 'id'];

    private const PARENT = ['Parent ID', 'Parent', 'Parent Task ID'];

    private const NAME = ['Task Name', 'Name', 'Task'];

    private const CONTENT = ['Task Content', 'Description', 'Content'];

    private const STATUS = ['Status'];

    private const PRIORITY = ['Priority'];

    private const ASSIGNEES = ['Assignees', 'Assignee'];

    private const DUE = ['Due Date Text', 'Due Date'];

    private const START = ['Start Date Text', 'Start Date'];

    private const LOGGED = ['Time Logged', 'Time Logged Text', 'Time Tracked', 'Time Spent'];

    /**
     * ClickUp's status vocabulary is per-space, so this is THEIR three (the screenshot) plus the
     * defaults every ClickUp space starts with. Anything else is a reported skip: a status this
     * app does not have is a decision about somebody's work, and the import does not get to make
     * it.
     */
    private const STATUSES = [
        // The three in the client's PROJECTS list.
        'in progress' => TaskStatus::InProgress,
        'draft' => TaskStatus::Backlog,
        'complete' => TaskStatus::Completed,
        'completed' => TaskStatus::Completed,
        // ClickUp's own defaults.
        'open' => TaskStatus::Todo,
        'to do' => TaskStatus::Todo,
        'todo' => TaskStatus::Todo,
        'backlog' => TaskStatus::Backlog,
        'in review' => TaskStatus::InReview,
        'review' => TaskStatus::InReview,
        'blocked' => TaskStatus::Waiting,
        'on hold' => TaskStatus::Waiting,
        'closed' => TaskStatus::Completed,
        'cancelled' => TaskStatus::Cancelled,
        'canceled' => TaskStatus::Cancelled,
    ];

    /**
     * The same three, read as what a PROJECT is doing — a separate map, because they are a
     * separate question. A client row that says COMPLETED means the engagement is finished, and
     * `ProjectStatus` has no Backlog to put DRAFT in: a project nobody has started is On Hold.
     */
    private const PROJECT_STATUSES = [
        'in progress' => ProjectStatus::Active,
        'open' => ProjectStatus::Active,
        'to do' => ProjectStatus::Active,
        'todo' => ProjectStatus::Active,
        'backlog' => ProjectStatus::OnHold,
        'draft' => ProjectStatus::OnHold,
        'on hold' => ProjectStatus::OnHold,
        'blocked' => ProjectStatus::OnHold,
        'in review' => ProjectStatus::Active,
        'review' => ProjectStatus::Active,
        'complete' => ProjectStatus::Completed,
        'completed' => ProjectStatus::Completed,
        'closed' => ProjectStatus::Completed,
        'cancelled' => ProjectStatus::Cancelled,
        'canceled' => ProjectStatus::Cancelled,
    ];

    private const PRIORITIES = [
        'urgent' => TaskPriority::Urgent,
        '1' => TaskPriority::Urgent,
        'high' => TaskPriority::High,
        '2' => TaskPriority::High,
        'normal' => TaskPriority::Medium,
        'medium' => TaskPriority::Medium,
        '3' => TaskPriority::Medium,
        'low' => TaskPriority::Low,
        '4' => TaskPriority::Low,
    ];

    public function key(): string
    {
        return 'clickup';
    }

    public function label(): string
    {
        return 'ClickUp';
    }

    public function columns(): array
    {
        return [
            'task id' => self::ID,
            'parent id' => self::PARENT,
            'title' => self::NAME,
            'description' => self::CONTENT,
            'status' => self::STATUS,
            'priority' => self::PRIORITY,
            'assignee' => self::ASSIGNEES,
            'start date' => self::START,
            'due date' => self::DUE,
            'time tracked' => self::LOGGED,
        ];
    }

    public function statusMap(): array
    {
        $map = [];

        foreach (self::STATUSES as $from => $to) {
            $map[mb_strtoupper($from)] = $to->label();
        }

        return $map;
    }

    public function read(CsvFile $file): WorkspaceDraft
    {
        if (! $file->hasAnyColumn(...self::ID)) {
            throw ImportFileException::notRecognised($file->path(), 'ClickUp', self::ID);
        }

        /** @var array<string, array{row: CsvRow, id: string, parent: ?string}> $rows */
        $rows = [];
        $order = [];
        $issues = [];
        $read = 0;

        foreach ($file->rows() as $row) {
            $read++;

            $id = $row->get(...self::ID);
            $title = $row->get(...self::NAME);

            if ($title === null) {
                $issues[] = RowIssue::skipped($row->line, $id ?? '(no id)', 'the row has no task name');

                continue;
            }

            if ($id === null) {
                $issues[] = RowIssue::skipped($row->line, $title, 'the row has no Task ID, so nothing can point at it');

                continue;
            }

            if (array_key_exists($id, $rows)) {
                $issues[] = RowIssue::skipped($row->line, $title, sprintf(
                    'Task ID %s already appeared on line %d',
                    $id,
                    $rows[$id]['row']->line,
                ));

                continue;
            }

            $rows[$id] = ['row' => $row, 'id' => $id, 'parent' => $row->get(...self::PARENT)];
            $order[] = $id;
        }

        return new WorkspaceDraft(
            $this->label(),
            $this->assemble($rows, $order, $issues),
            $issues,
            $file->headers(),
            $read,
        );
    }

    /**
     * Parents become projects, children become their tasks.
     *
     * A row whose `Parent ID` names something that is not in this file is treated as top level
     * and reported: a subtask exported without its parent has nowhere to go, and silently
     * promoting it to a client is worse than saying so.
     *
     * @param  array<string, array{row: CsvRow, id: string, parent: ?string}>  $rows
     * @param  list<string>  $order
     * @param  list<RowIssue>  $issues
     * @return list<ProjectDraft>
     */
    private function assemble(array $rows, array $order, array &$issues): array
    {
        /** @var array<string, list<string>> $children */
        $children = [];
        $roots = [];

        foreach ($order as $id) {
            $parent = $rows[$id]['parent'];

            if ($parent !== null && $parent !== $id && array_key_exists($parent, $rows)) {
                $children[$parent][] = $id;

                continue;
            }

            if ($parent !== null && ! array_key_exists($parent, $rows)) {
                $issues[] = RowIssue::unmapped(
                    $rows[$id]['row']->line,
                    (string) $rows[$id]['row']->get(...self::NAME),
                    sprintf('its parent %s is not in this export, so it was read as a top-level row', $parent),
                );
            }

            $roots[] = $id;
        }

        $projects = [];

        foreach ($roots as $id) {
            $row = $rows[$id]['row'];
            $name = (string) $row->get(...self::NAME);
            $rawStatus = $row->get(...self::STATUS) ?? '';
            $mine = $children[$id] ?? [];

            if ($mine === []) {
                // The mapping is "the top-level task is the client and its subtasks are the
                // work". A parent with no work is not a client; it is a loose task that this
                // shape has nowhere to put, so it is reported rather than turned into an empty
                // client and an empty project.
                $issues[] = RowIssue::unmapped($row->line, $name, 'a top-level row with no subtasks — '
                    .'there is no client and no project in it, so nothing was created');

                continue;
            }

            $status = self::PROJECT_STATUSES[mb_strtolower(trim($rawStatus))] ?? null;

            if ($status === null) {
                $issues[] = RowIssue::skipped($row->line, $name, $rawStatus === ''
                    ? 'the row has no status'
                    : sprintf('status "%s" is not mapped to a project status', $rawStatus));

                foreach ($mine as $childId) {
                    $child = $rows[$childId]['row'];
                    $issues[] = RowIssue::skipped(
                        $child->line,
                        (string) $child->get(...self::NAME),
                        sprintf('its parent "%s" (line %d) was skipped', $name, $row->line),
                    );
                }

                continue;
            }

            $tasks = [];

            foreach ($mine as $childId) {
                $task = $this->task($rows[$childId]['row'], $childId, $issues);

                if ($task !== null) {
                    $tasks[] = $task;
                }
            }

            $projects[] = new ProjectDraft($row->line, $id, $name, $name, $rawStatus, $status, $tasks);
        }

        return $projects;
    }

    /**
     * @param  list<RowIssue>  $issues
     */
    private function task(CsvRow $row, string $id, array &$issues): ?TaskDraft
    {
        $title = (string) $row->get(...self::NAME);
        $rawStatus = $row->get(...self::STATUS) ?? '';
        $status = self::STATUSES[mb_strtolower(trim($rawStatus))] ?? null;

        if ($status === null) {
            $issues[] = RowIssue::skipped($row->line, $title, $rawStatus === ''
                ? 'the row has no status'
                : sprintf('status "%s" is not mapped', $rawStatus));

            return null;
        }

        $assignees = $row->list(...self::ASSIGNEES);

        if (count($assignees) > 1) {
            $issues[] = RowIssue::unmapped($row->line, $title, sprintf(
                'ClickUp lists %d assignees (%s); only the first was used, the rest were dropped',
                count($assignees),
                implode(', ', $assignees),
            ));
        }

        $logged = $row->seconds(...self::LOGGED);

        if ($logged === null && $row->get(...self::LOGGED) !== null) {
            $issues[] = RowIssue::unmapped($row->line, $title, sprintf(
                'time tracked "%s" is not a duration this reader can read, so no time was imported',
                (string) $row->get(...self::LOGGED),
            ));
        }

        return new TaskDraft(
            $row->line,
            $id,
            $title,
            $row->get(...self::CONTENT),
            $rawStatus,
            $status,
            self::PRIORITIES[mb_strtolower(trim((string) $row->get(...self::PRIORITY)))] ?? TaskPriority::Medium,
            $row->date(...self::START),
            $row->date(...self::DUE),
            $assignees[0] ?? null,
            max(0, $logged ?? 0),
        );
    }
}
