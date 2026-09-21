<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A colour-coded label on a task.
 *
 * A tag is global (project_id null) or scoped to exactly one project. Who may CREATE one is a
 * policy question for slice 2 — Admins and Managers do, employees only assign what exists.
 */
#[Fillable(['project_id', 'name', 'colour'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsToMany<Task, $this>
     */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_tags');
    }

    public function isGlobal(): bool
    {
        return $this->project_id === null;
    }

    /**
     * The tags that may be put on a task of this project: its own, plus every global one.
     *
     * @param  Builder<Tag>  $query
     * @return Builder<Tag>
     */
    public function scopeUsableOn(Builder $query, Project $project): Builder
    {
        return $query->where(function (Builder $q) use ($project) {
            $q->whereNull('project_id')->orWhere('project_id', $project->getKey());
        });
    }

    /**
     * Every tag this user could meet across the work they can see: the global ones, plus the
     * ones scoped to a project `Project::visibleTo()` shows them.
     *
     * This is the LIST's question, which is not `usableOn()`'s. A single task's picker asks
     * "what may go on this one task", and the answer is one project's tags. A filter chip on a
     * list spanning many projects asks "what labels could any row here be wearing", and the
     * union over the visible projects is that set — narrower would hide a label the list is
     * actually showing, wider leaks a name.
     *
     * And a tag's name is a name: "Bengal Meat — packaging" scoped to a project an employee is
     * not on tells them that client and that job exist. A filter listing every tag in the system
     * was doing exactly that on the Employee surface.
     *
     * @param  Builder<Tag>  $query
     * @return Builder<Tag>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $q) => $q
            ->whereNull('project_id')
            ->orWhereIn('project_id', Project::query()->visibleTo($user)->select('projects.id')),
        );
    }
}
