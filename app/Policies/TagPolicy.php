<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use App\Support\Permission;
use App\Support\RoleName;
use Illuminate\Support\Facades\Gate;

/**
 * Who may MANAGE a tag — create one, rename it, recolour it, remove it.
 *
 * The plan's line is short: "Tags: Admin/Manager create (global or per project), colour;
 * employees only assign existing tags; filter by tag." Everything in this file is that sentence
 * and the scoping rule the rest of the application already has.
 *
 * ## Assigning is not managing, and is not here
 *
 * Putting an existing label on a task is an edit of the TASK. It travels as `tag_ids` on
 * `PUT …/tasks/{task}`, it takes TaskPolicy::update, and TaskService::applyTags() refuses a tag
 * the task's project cannot use. None of that is this policy's business, and nothing in this
 * file widens or narrows it — an employee who may edit a task may still tag it, and still may
 * not create the label they wanted.
 *
 * ## Why the scope check is `view` on the project and not a new permission key
 *
 * A tag scoped to a project is part of that project's vocabulary: its name says what kind of
 * work that client buys. So managing one is gated on being able to see the project at all,
 * through ProjectPolicy — the same answer `Tag::visibleTo()` scopes the list with, so the
 * picker, the management list and the write all agree by construction rather than by three
 * matching implementations. Per AGENTS.md, a scoped rule is a key plus a scope check in a
 * policy, never a new key; the key here is `tasks.create`, because a tag is task vocabulary and
 * whoever may bring a task into existence may bring its labels.
 *
 * ## The Accountant
 *
 * Not mentioned once, and that is the enforcement: they hold no `tasks.create`, so every
 * ability below falls at its first line — before the role check, and before the surface
 * middleware would have refused them anyway.
 */
class TagPolicy extends Policy
{
    /**
     * Seeing the list of tags at all. Scoped to what they can see — `Tag::visibleTo()` does the
     * per-row half, because a tag's NAME names somebody's project.
     */
    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    /**
     * One tag. Not the same question as `usableOn()`: this is the management view, so a tag
     * scoped to a project this user cannot see must read as absent.
     */
    public function view(User $user, Tag $tag): bool
    {
        if (! $this->manages($user)) {
            return false;
        }

        return $this->inScope($user, $tag->project);
    }

    /**
     * Creating. The project is the one the request asked for, or null for a global tag.
     *
     * A global tag is usable on every project, so it is tempting to reserve it for Admins. The
     * plan says "Admin/Manager create (global or per project)" and a narrower rule than the
     * spec is still a rule nobody asked for, so both may — and a Manager is already trusted
     * with every project's tasks.
     */
    public function create(User $user, ?Project $project = null): bool
    {
        return $this->manages($user) && $this->inScope($user, $project);
    }

    /**
     * Renaming or recolouring. Same standing as creating, plus visibility of the tag itself.
     *
     * A tag's SCOPE is deliberately not editable — see TagService::update(). Moving a global
     * tag into a project would silently strip it off every task in every other project, and
     * moving a scoped one out would publish a client's vocabulary to the whole agency; neither
     * is a rename, and neither has an endpoint.
     */
    public function update(User $user, Tag $tag): bool
    {
        return $this->view($user, $tag);
    }

    /**
     * Deleting. The same people, because a tag carries no history worth a second rule: it is a
     * label, not a record. What deleting a tag that is IN USE does is TagService::delete()'s
     * decision, not a permission question.
     */
    public function delete(User $user, Tag $tag): bool
    {
        return $this->view($user, $tag);
    }

    /**
     * The role and permission half: an Admin or a Manager holding `tasks.create`.
     */
    private function manages(User $user): bool
    {
        return $this->allows($user, Permission::TasksCreate)
            && $user->hasRole(RoleName::ADMIN, RoleName::MANAGER);
    }

    /**
     * The project half. A global tag (null) belongs to everybody who manages tags at all; a
     * scoped one belongs to the people who can see its project.
     */
    private function inScope(User $user, ?Project $project): bool
    {
        return $project === null || Gate::forUser($user)->allows('view', $project);
    }
}
