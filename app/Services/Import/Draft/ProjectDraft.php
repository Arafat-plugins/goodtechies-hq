<?php

namespace App\Services\Import\Draft;

use App\Support\ProjectStatus;

/**
 * A project the import wants to create, and the client it hangs off.
 *
 * `$clientName` is null for a source that has no notion of a client — an Asana export is
 * Project → Task → Subtask with nothing above the project, and `projects.client_id` is nullable
 * precisely so an internal project can exist without one. It is never invented.
 */
final class ProjectDraft
{
    /**
     * @param  list<TaskDraft>  $tasks
     */
    public function __construct(
        public readonly int $line,
        public readonly string $sourceId,
        public readonly string $name,
        public readonly ?string $clientName,
        public readonly string $rawStatus,
        public readonly ?ProjectStatus $status,
        public readonly array $tasks,
    ) {}
}
