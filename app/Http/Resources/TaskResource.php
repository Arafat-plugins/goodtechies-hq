<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskLink;
use App\Models\User;
use App\Support\TaskStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The only way a task leaves the server (master prompt Part B §3 rule 1).
 *
 * The project fragment is NOT built here. It is `new ProjectResource($project)`, which decides
 * per field and per requester what survives — so a finance column cannot appear on a task
 * payload by somebody adding it to a select list in this file. If a task must never leak a
 * price, the way to guarantee that is to never be the code that reads one.
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,

            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            // The StatusBadge `StatusKey` to paint with, resolved on the server so the screen
            // never maps a status to a colour itself and the two cannot drift.
            'status_tone' => $this->status?->tone(),

            'priority' => $this->priority?->value,
            'priority_label' => $this->priority?->label(),

            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            // Computed, never stored. The as-of date rides on the request so a test can pin it.
            'is_overdue' => $this->resource->isOverdue($this->asOf($request)),

            'estimated_minutes' => $this->estimated_minutes,
            'tracked_seconds' => (int) $this->tracked_seconds,

            'position' => (int) $this->position,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'is_archived' => $this->resource->isArchived(),

            'work_summary' => $this->work_summary,
            // Who wrote it. Completion checks this against the primary assignee, so the screen
            // can say whose summary is on the task and why it is or is not enough.
            'work_summary_by' => $this->person($this->resource->workSummaryAuthor),
            'work_summary_at' => $this->work_summary_at?->toIso8601String(),

            'completed_at' => $this->completed_at?->toIso8601String(),
            'completed_by' => $this->person($this->resource->completer),

            // The FIRST completion, preserved through every reopening. Present whether or not
            // the task is completed now — that is the point of it.
            'first_completion' => $this->firstCompletion(),

            'created_at' => $this->created_at?->toIso8601String(),
            // The spec's "original assigner" is created_by; there is no second field for it.
            'created_by' => $this->person($this->resource->creator),

            // Composed, not re-derived. See the class docblock.
            //
            // resolve() rather than handing the resource over whole: a nested JsonResource
            // serialises as {"data": {…}} and the List view would have to know that. This is
            // the same ProjectResource deciding the same fields, flattened one level.
            'project' => $this->whenLoaded(
                'project',
                fn (): array => (new ProjectResource($this->resource->project))->resolve($request),
            ),

            'assignees' => $this->assignees(),
            'primary_assignee' => $this->primaryAssignee(),
            'tags' => $this->tags(),

            // Checklists arrive in slice 2. The List view has a "Subtasks" column now, so the
            // key exists now and reads 0 until there is a relation to count; when slice 2 adds
            // its withCount the payload shape does not change.
            'subtask_count' => (int) ($this->resource->checklist_items_count ?? 0),
            'subtasks_done_count' => (int) ($this->resource->checklist_items_done_count ?? 0),

            // How many files are on the task, counting each one once whatever its version
            // history looks like. Slice 3's board card printed a paperclip with nothing beside
            // it because the server sent nothing; it sends this now. Every query that produces
            // a TaskResource sets the count — TaskService::query() for the three list views and
            // the two controllers' visible() for the detail page — for the same reason
            // `subtask_count` is a withCount and not a relation read: a list must not become a
            // query per row to print a number.
            'attachment_count' => (int) ($this->resource->attachment_count ?? 0),

            // The detail page's panels. Behind whenLoaded so the List view's payload does not
            // grow three relations per row it never draws.
            'checklist' => $this->whenLoaded('checklistItems', fn (): array => $this->checklist()),
            'links' => $this->whenLoaded('links', fn (): array => $this->links()),
            // The attachment panel's rows, each through FileResource — which is what mints the
            // signed URL. Behind whenLoaded like the rest: a board of two hundred cards must
            // not sign two hundred links to draw a paperclip.
            'attachments' => $this->whenLoaded(
                'files',
                fn (): array => FileResource::collection($this->resource->files)->toArray($request),
            ),
            'dependencies' => $this->whenLoaded('dependencies', fn (): array => $this->taskStubs($this->resource->dependencies)),
            'dependents' => $this->whenLoaded('dependents', fn (): array => $this->taskStubs($this->resource->dependents)),

            // Which moves this requester may actually make from here, resolved on the server so
            // the detail page's status control offers nothing it would then be refused for.
            // Only on the detail page: it is a gate call per candidate status, which is fine
            // once and wrong on a list of two hundred rows.
            'available_transitions' => $this->when(
                $request->attributes->get('task_detail') === true,
                fn (): array => $this->availableTransitions($user),
            ),

            'permissions' => $this->permissions($user),
        ];
    }

    /**
     * The original completion, kept on the row so a reopening cannot lose it.
     *
     * @return array{at: string, by: array{id: int, name: string|null}|null, work_summary: string|null}|null
     */
    private function firstCompletion(): ?array
    {
        if ($this->resource->first_completed_at === null) {
            return null;
        }

        return [
            'at' => $this->resource->first_completed_at->toIso8601String(),
            'by' => $this->person($this->resource->firstCompleter),
            'work_summary' => $this->resource->first_work_summary,
        ];
    }

    /**
     * @return list<array{id: int, title: string, is_done: bool, completed_by: array{id: int, name: string|null}|null, completed_at: string|null}>
     */
    private function checklist(): array
    {
        return $this->resource->checklistItems
            ->map(fn (TaskChecklistItem $item): array => [
                'id' => $item->id,
                'title' => $item->title,
                'is_done' => (bool) $item->is_done,
                'completed_by' => $this->person($item->completer),
                'completed_at' => $item->completed_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, url: string, label: string}>
     */
    private function links(): array
    {
        return $this->resource->links
            ->map(fn (TaskLink $link): array => [
                'id' => $link->id,
                'url' => $link->url,
                'label' => $link->displayLabel(),
            ])
            ->values()
            ->all();
    }

    /**
     * A dependency is named and located, never serialised whole: a task the requester may not
     * see must not arrive as a full payload through the back of another task's panel.
     *
     * @param  Collection<int, Task>  $tasks
     * @return list<array{id: int, title: string, status: string|null, status_label: string|null, status_tone: string|null}>
     */
    private function taskStubs(Collection $tasks): array
    {
        return $tasks
            ->map(fn (Task $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status?->value,
                'status_label' => $task->status?->label(),
                'status_tone' => $task->status?->tone(),
            ])
            ->values()
            ->all();
    }

    /**
     * Every status this user may move this task to right now: the machine's legal moves,
     * filtered by TaskPolicy::transition, which is the same check the endpoint runs.
     *
     * @return list<array{value: string, label: string, tone: string}>
     */
    private function availableTransitions(?User $user): array
    {
        if ($user === null || $this->resource->status === null) {
            return [];
        }

        $gate = Gate::forUser($user);

        return array_values(array_map(
            fn (TaskStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
                'tone' => $status->tone(),
            ],
            array_filter(
                $this->resource->status->allowedTransitions(),
                fn (TaskStatus $status): bool => $gate->allows('transition', [$this->resource, $status]),
            ),
        ));
    }

    /**
     * Everyone assigned, with the primary flagged. Read from the loaded relation only, so a
     * list of tasks never becomes a query per row.
     *
     * @return list<array{id: int, user_id: int|null, name: string|null, is_primary: bool}>
     */
    private function assignees(): array
    {
        if (! $this->resource->relationLoaded('assignees')) {
            return [];
        }

        return $this->resource->assignees
            ->map(fn (Employee $employee): array => $this->employee($employee) + [
                'is_primary' => (bool) $employee->pivot?->is_primary,
            ])
            ->values()
            ->all();
    }

    /**
     * The assignee who owns completion — the work summary has to be theirs.
     *
     * @return array{id: int, user_id: int|null, name: string|null}|null
     */
    private function primaryAssignee(): ?array
    {
        if (! $this->resource->relationLoaded('assignees')) {
            return null;
        }

        $primary = $this->resource->assignees
            ->first(fn (Employee $employee): bool => (bool) $employee->pivot?->is_primary);

        return $primary === null ? null : $this->employee($primary);
    }

    /**
     * An assignee, as every payload here names one.
     *
     * `id` is the EMPLOYEE's, which is the convention ProjectResource sets for `pm` and
     * `members` and which the assignee endpoints take back — and `user_id` is the user behind
     * that employee row, which is a different thing and is therefore a different key.
     *
     * The pair is here because the completion rule compares two of them: the server decides
     * whether a task may be completed by testing `work_summary_by` (a USER) against the primary
     * assignee's `user_id` (an EMPLOYEE's user), and without this key the screen could only
     * bridge the two by display name. Two people called Rahim are not the same person, and a
     * screen that says a task is completable because two strings matched is guessing.
     *
     * @return array{id: int, user_id: int|null, name: string|null}
     */
    private function employee(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'user_id' => $employee->user_id === null ? null : (int) $employee->user_id,
            'name' => $employee->user?->name,
        ];
    }

    /**
     * @return list<array{id: int, name: string, colour: string, is_global: bool}>
     */
    private function tags(): array
    {
        if (! $this->resource->relationLoaded('tags')) {
            return [];
        }

        return $this->resource->tags
            ->map(fn (Tag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'colour' => $tag->colour?->value,
                'is_global' => $tag->isGlobal(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, name: string|null}|null
     */
    private function person(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }

    /**
     * What the requester may do with this task, so the UI hides controls it could not use.
     * The UI is never the enforcement point; these mirror TaskPolicy.
     *
     * @return array<string, bool>
     */
    private function permissions(?User $user): array
    {
        if ($user === null) {
            return [
                'can_update' => false,
                'can_delete' => false,
                'can_archive' => false,
                'can_review' => false,
            ];
        }

        $gate = Gate::forUser($user);

        return [
            'can_update' => $gate->allows('update', $this->resource),
            'can_delete' => $gate->allows('delete', $this->resource),
            'can_archive' => $gate->allows('archive', $this->resource),
            'can_review' => $gate->allows('review', $this->resource),
        ];
    }

    /**
     * The date "overdue" is measured against. Defaults to today; a caller that needs a fixed
     * date (a test, a report for a past day) puts a Carbon on the request attributes.
     */
    private function asOf(Request $request): ?Carbon
    {
        $asOf = $request->attributes->get('tasks_as_of');

        return $asOf instanceof Carbon ? $asOf : null;
    }
}
