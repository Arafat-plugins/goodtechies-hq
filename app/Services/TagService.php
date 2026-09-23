<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\TagColour;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Tags: the management side. Creating a label, renaming it, recolouring it, removing it.
 *
 * ASSIGNING a tag is not here and never will be — it is `tag_ids` on the task update, it is
 * TaskService::applyTags(), and it takes the ability an edit of the task takes. The split is
 * the plan's: "Admin/Manager create them; employees only assign existing tags". Two verbs, two
 * abilities, two services, and no way to reach one through the other.
 *
 * Validation is in the Form Requests. Authorization is in TagPolicy; this class asks the gate
 * and turns a refusal into an exception, so a console command or a job is refused exactly the
 * way an HTTP request is.
 */
class TagService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * The tags this user may manage, alphabetically: the global ones plus the ones scoped to a
     * project they can see.
     *
     * The same scope the pickers and the filter chip use, deliberately — a management list that
     * asked a different question would be a fourth place the rule lives.
     *
     * @return Collection<int, Tag>
     */
    public function manageable(User $user): Collection
    {
        return Tag::query()
            ->visibleTo($user)
            ->with('project')
            ->withCount('tasks')
            ->orderBy('name')
            ->get();
    }

    /**
     * Create a tag, global or scoped to one project.
     *
     * @throws AuthorizationException
     */
    public function create(User $actor, string $name, TagColour $colour, ?Project $project = null): Tag
    {
        if (! Gate::forUser($actor)->allows('create', [Tag::class, $project])) {
            throw new AuthorizationException('You are not allowed to create tags.');
        }

        return DB::transaction(function () use ($actor, $name, $colour, $project): Tag {
            $tag = Tag::create([
                'project_id' => $project?->getKey(),
                'name' => $name,
                'colour' => $colour,
            ]);

            $this->activity->record($tag, sprintf(
                'Tag created: %s (%s, %s)',
                $tag->name,
                $colour->label(),
                $project === null ? 'global' : 'scoped to '.$project->name,
            ), $actor);

            return $tag;
        });
    }

    /**
     * Rename or recolour a tag.
     *
     * Its SCOPE is not editable, and that is a decision rather than an omission. Moving a global
     * tag into a project would strip it off every task in every other project the instant it
     * saved — `task_tags` rows that no longer satisfy `usableOn()` — and moving a scoped one out
     * would publish one client's vocabulary to the whole agency. Neither is a rename. A tag that
     * is in the wrong scope is deleted and created again, which is visible in the audit trail
     * and tells everybody whose task lost a label.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws AuthorizationException
     */
    public function update(User $actor, Tag $tag, array $attributes): Tag
    {
        if (! Gate::forUser($actor)->allows('update', $tag)) {
            throw new AuthorizationException('You are not allowed to edit this tag.');
        }

        return DB::transaction(function () use ($actor, $tag, $attributes): Tag {
            $before = ['name' => $tag->name, 'colour' => $tag->colour?->value];

            $tag->fill(array_intersect_key($attributes, array_flip(['name', 'colour'])));

            if ($tag->isDirty()) {
                $tag->save();

                $this->activity->record($tag, sprintf(
                    'Tag updated: %s%s',
                    $before['name'] === $tag->name ? $tag->name : $before['name'].' → '.$tag->name,
                    $before['colour'] === $tag->colour?->value
                        ? ''
                        : ' ('.$tag->colour?->label().')',
                ), $actor);
            }

            return $tag->refresh();
        });
    }

    /**
     * Delete a tag — including one that tasks are wearing right now.
     *
     * ## What happens, and why it is not a refusal
     *
     * The tag goes, and `task_tags` cascades, so every task carrying it loses the label. The
     * two alternatives were both worse:
     *
     *   - REFUSE while in use ("detach it from 14 tasks first"). The only way to detach is the
     *     tag picker on each of those 14 task detail pages — and for a SCOPED tag on a task
     *     that has since moved project, the picker does not even offer it, because it lists
     *     what the current project can use. That makes some tags undeletable by construction,
     *     which is how a tag list turns into a graveyard nobody can tidy.
     *   - SOFT-delete the tag. FileService says the same thing about a file: a delete that
     *     frees nothing is a hidden flag, not a delete. A soft-deleted tag would still occupy
     *     its name against the unique index, so the obvious next move — recreate it spelled
     *     properly — would fail with a constraint violation about a row nobody can see.
     *
     * So it is a real delete. What it is NOT is a silent one. It changes other people's tasks,
     * so before the row goes:
     *
     *   - every affected task gets a line on its own timeline saying which label came off and
     *     that the tag itself was deleted, so the assignee finds out on the task rather than
     *     wondering where their label went;
     *   - one `audit_logs` row records the tag as it was and the id of every task it came off.
     *     `audit_logs` is append-only and `hq_app` holds no UPDATE or DELETE on it, so that
     *     record outlives everybody's ability to tidy it up. It is also the only place the
     *     pivot rows survive at all, which is exactly why it is written first, while there is
     *     still something to describe.
     *
     * @return int how many tasks lost the label
     *
     * @throws AuthorizationException
     */
    public function delete(User $actor, Tag $tag): int
    {
        if (! Gate::forUser($actor)->allows('delete', $tag)) {
            throw new AuthorizationException('You are not allowed to delete this tag.');
        }

        return DB::transaction(function () use ($actor, $tag): int {
            /** @var Collection<int, Task> $tasks */
            $tasks = $tag->tasks()->get();

            // Audit first, while the pivot rows are still there to be listed. Once the delete
            // has cascaded, nothing anywhere remembers which tasks were wearing this label.
            $this->audit->record(AuditEvent::TagDeleted, $tag, [
                'name' => $tag->name,
                'colour' => $tag->colour?->value,
                'project_id' => $tag->project_id,
                'task_ids' => $tasks->map(fn (Task $task): int => (int) $task->getKey())->all(),
            ], null, $actor);

            foreach ($tasks as $task) {
                $this->activity->record($task, sprintf(
                    'Tag removed: %s (the tag was deleted)',
                    $tag->name,
                ), $actor);
            }

            $tag->delete();

            return $tasks->count();
        });
    }
}
