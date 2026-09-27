<?php

namespace App\Services\Import\Draft;

use App\Support\TaskPriority;
use App\Support\TaskStatus;
use Illuminate\Support\Carbon;

/**
 * One row of an export that wants to become a task, already translated out of the source's
 * vocabulary and into this application's.
 *
 * `$status` is null when the source's status has no mapping. The raw text is kept beside it
 * because that is what the report has to print — "status `ON HOLD` is not mapped" is actionable,
 * "a status was not mapped" is not.
 *
 * `$assignee` is the source's text, never a resolved employee. Resolution is the importer's job
 * and can fail, and a failure there is a reported line rather than a new user (Phase 12 rule 4).
 */
final class TaskDraft
{
    public function __construct(
        public readonly int $line,
        public readonly string $sourceId,
        public readonly string $title,
        public readonly ?string $description,
        public readonly string $rawStatus,
        public readonly ?TaskStatus $status,
        public readonly TaskPriority $priority,
        public readonly ?Carbon $startDate,
        public readonly ?Carbon $dueDate,
        public readonly ?string $assignee,
        public readonly int $trackedSeconds,
    ) {}
}
