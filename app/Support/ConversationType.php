<?php

namespace App\Support;

/**
 * What a conversation is attached to (master prompt Part D §10).
 *
 * Phase 2 declared all five cases and built one. Phase 6 builds the other four on the same
 * table, the same policy shape and the same read state — no column moved, no constraint on
 * `type` widened, exactly as the Phase 2 migration promised.
 *
 * ## The membership rule, generalised rather than bent
 *
 * Decision 2-24 said a `task` conversation's membership is COMPUTED — `ConversationPolicy` asks
 * `TaskPolicy::view` about the linked task, and `conversation_members` holds `last_read_at` and
 * grants nothing. Phase 6 keeps that sentence true of **every** type, by giving each type a
 * source to compute from that is not `conversation_members`:
 *
 *   | type           | audience computed from                                             |
 *   | -------------- | ------------------------------------------------------------------ |
 *   | `task`         | `TaskPolicy::view` on `linked_task_id` — unchanged since Phase 2    |
 *   | `project`      | `ProjectPolicy::view` on `linked_project_id`                        |
 *   | `team`         | holding `messages.use`, and being active                            |
 *   | `announcement` | the same, plus `announcements.send` to POST rather than read        |
 *   | `dm`           | being one of the two users named ON THE CONVERSATION ROW itself     |
 *
 * The DM is the case that would have bent the rule, and the thing that stops it is a schema
 * choice: the pair lives in `conversations.dm_one_id` / `dm_two_id`, not in two
 * `conversation_members` rows. That keeps one sentence true with no exception — **nothing
 * anywhere reads `conversation_members` to decide who may do anything** — and it buys three
 * things a pair of member rows could not:
 *
 *   - a DM cannot become a group by an INSERT. Two columns hold two people; a third person has
 *     nowhere to go. A membership table would have needed a trigger or a count check to say so.
 *   - "the DM between A and B" is a unique index (`conversations_one_per_dm_pair`, on the
 *     ordered pair), so `dmBetween()` is a `firstOrCreate` that cannot race into two threads.
 *     Expressed as member rows the same guarantee is a self-join with no index able to enforce it.
 *   - a stale member row still buys its holder exactly zero, in a DM as in a task discussion.
 *     That is the test decision 2-24 is written about, and it now covers all five types.
 *
 * `team` and `announcement` have no linked object, and that is not an exception either: their
 * audience is computed from a PERMISSION, which is the same kind of answer `TaskPolicy` gives
 * and the same kind this codebase has always preferred to a role name (decisions 2-13, 2-31).
 */
enum ConversationType: string
{
    /** Everybody who may use messaging. One row, for the whole company. */
    case Team = 'team';

    /** One project's channel. Created with the project; backfilled for the ones that predate it. */
    case Project = 'project';

    /** One task's discussion — what the plan calls "comments". */
    case Task = 'task';

    /** Two people, named on the row. */
    case Dm = 'dm';

    /** One-way, from a holder of `announcements.send`. Everybody else reads. */
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
     * **True for every type**, which is the Phase 6 answer to the question Phase 2 left open.
     * See the class docblock for what each one computes from. The method survives because
     * `Conversation::membershipIsComputed()` and `ConversationPolicy` both read it, and because
     * a future type that arrives without a rule should land on `false` and be denied outright
     * rather than inherit somebody else's audience.
     */
    public function membershipIsComputed(): bool
    {
        return match ($this) {
            self::Task, self::Project, self::Team, self::Dm, self::Announcement => true,
        };
    }

    /**
     * Is this conversation about another record — a task or a project — whose own policy
     * answers for it?
     *
     * The two "delegating" types. `ConversationPolicy` fetches the subject and asks its policy;
     * everything else it decides from the permission and, for a DM, the pair on the row.
     */
    public function delegatesToSubject(): bool
    {
        return $this->linkColumn() !== null;
    }

    /**
     * Is this one of the channels the Messages page lists — as opposed to a task discussion,
     * which is read inside its task and never appears in the inbox?
     *
     * A task discussion is deliberately absent from Messages: there can be hundreds of them, one
     * per task, and the place to read a task's comments is the task. Its notifications still say
     * so (`task.commented` lands on the Tasks tab, where it has since Phase 2).
     */
    public function appearsInInbox(): bool
    {
        return $this !== self::Task;
    }

    /**
     * May an ordinary member of the audience POST here, or only read?
     *
     * False for `announcement` alone: it is a broadcast, and `ConversationPolicy::post` asks for
     * `announcements.send` on top of the audience test. Everywhere else, everybody in the room
     * may speak in it — a conversation some of whose readers cannot reply would need a second
     * concept ("observers") that nothing in the spec asks for.
     */
    public function everybodyMayPost(): bool
    {
        return $this !== self::Announcement;
    }

    /**
     * The heading the inbox groups this type under: the plan's *Team · Projects · Direct*, plus
     * Announcements.
     */
    public function group(): string
    {
        return match ($this) {
            self::Team => 'Team',
            self::Announcement => 'Announcements',
            self::Project => 'Projects',
            self::Dm => 'Direct',
            self::Task => 'Tasks',
        };
    }
}
