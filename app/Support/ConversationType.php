<?php

namespace App\Support;

/**
 * What a conversation is attached to (master prompt Part D, communication tables).
 *
 * All five cases are declared now and only ONE of them is built. That is deliberate and it is
 * the cheap half of the Phase 6 promise: the `conversations` table's CHECK constraint is
 * written from this list, so the day Phase 6 opens a team channel or a DM it inserts a row —
 * it does not migrate a column, widen a constraint, or discover that `type` was an enum with
 * three values in it.
 *
 * Phase 2 builds `task` and nothing else. ConversationPolicy denies every other type outright
 * rather than guessing at rules that have not been specified: a conversation whose access rule
 * does not exist yet is a conversation nobody may open, which is the deny-by-default this
 * codebase applies everywhere else.
 */
enum ConversationType: string
{
    /** Everybody. Phase 6. */
    case Team = 'team';

    /** One project's channel. Phase 6. */
    case Project = 'project';

    /** One task's discussion — what the plan calls "comments". The only type Phase 2 builds. */
    case Task = 'task';

    /** Two people. Phase 6. */
    case Dm = 'dm';

    /** One-way, from an Admin. Phase 6. */
    case Announcement = 'announcement';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Which column, if any, a conversation of this type must be linked through.
     *
     * The CHECK constraint on `conversations` is generated from this, so "a task conversation
     * has a task and nothing else" is one statement rather than a comment and a constraint that
     * could disagree.
     */
    public function linkColumn(): ?string
    {
        return match ($this) {
            self::Project => 'linked_project_id',
            self::Task => 'linked_task_id',
            self::Team, self::Dm, self::Announcement => null,
        };
    }

    /**
     * Is this a type whose membership is COMPUTED from something else, rather than stored?
     *
     * The whole Phase 2 privacy decision lives in this method. A task conversation's audience
     * is "whoever may see the task", which is TaskPolicy's answer and changes the instant a
     * task is reassigned — so it is computed, every time, and `conversation_members` holds only
     * read state for it. The Phase 6 types have no such source to compute from: a DM's audience
     * IS the membership rows, and those will be the grant.
     *
     * Stating it here rather than in the policy means the table's two meanings are named in one
     * place instead of being inferred from whichever branch a reader happens to open first.
     */
    public function membershipIsComputed(): bool
    {
        return match ($this) {
            self::Task => true,
            // Phase 6 decides for the rest, and until it does, nothing may open them.
            self::Project, self::Team, self::Dm, self::Announcement => false,
        };
    }
}
