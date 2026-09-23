<?php

namespace App\Support;

/**
 * Every kind of notification the engine can produce, and everything that is true of a KIND
 * rather than of one row: which Center tab it lands on, how loud it is, which channels it may
 * one day go out on, and the permission a person must hold before they may receive it at all.
 *
 * ## Why the permission lives here
 *
 * "An Accountant must never receive a task notification" is not a rule about Accountants. It is
 * a rule about task notifications: they go to people who may see tasks. `requires()` says that
 * once, NotificationService applies it to every recipient of every type, and the Accountant
 * falls out because they hold no `tasks.*` key — exactly the way they fall out of TaskPolicy,
 * which does not mention them either. A later role that does hold `tasks.view` starts receiving
 * task notifications with no change here, and that is the intended behaviour, not an oversight.
 *
 * The permission is the TYPE-shaped half of the rule. The object-shaped half — "and they must
 * be able to see THIS task" — is a gate check, and it lives in NotificationDispatcher where the
 * object is known. Both are applied; neither is sufficient alone.
 *
 * ## What is not here
 *
 * Phase 2 produces rows on the Tasks and System tabs only. Messages, Meetings, Leave and
 * Payroll get their types in Phase 6, 7, 5 and 9 — adding one is a case in this enum plus a
 * mapping in NotificationDispatcher, and nothing else.
 */
enum NotificationType: string
{
    /* Tasks tab -------------------------------------------------------------------------- */

    case TaskAssigned = 'task.assigned';
    case TaskReassigned = 'task.reassigned';
    case TaskStatusChanged = 'task.status_changed';
    case TaskCommented = 'task.commented';
    case TaskSubmittedForReview = 'task.submitted_for_review';
    case TaskCompleted = 'task.completed';
    case TaskDeleted = 'task.deleted';

    /**
     * Sent by `hq:flag-overdue`, once per task and never again — see FlagOverdueTasks. It is
     * not one of the seven task EVENTS: nothing a person does produces it, a date passing does.
     */
    case TaskOverdue = 'task.overdue';

    /**
     * Sent by `hq:notify-due-tomorrow`, once per task — the last of Part D §19's fixed automation
     * rules ("Task due tomorrow → notify assignee"). Like TaskOverdue it is not one of the task
     * EVENTS: nothing a person does produces it, a date approaching does.
     */
    case TaskDueTomorrow = 'task.due_tomorrow';

    /* System tab ------------------------------------------------------------------------- */

    /**
     * The Phase 1 side effect, made real (spec Part D §21: "Project cancelled → open tasks
     * prompted bulk-close/reassign"). System rather than Tasks: it is about a project and a
     * pile of tasks at once, and it asks for an administrative decision rather than work.
     */
    case ProjectCancelled = 'project.cancelled';

    public function tab(): NotificationTab
    {
        return match ($this) {
            self::ProjectCancelled => NotificationTab::System,
            default => NotificationTab::Tasks,
        };
    }

    /**
     * How loud this is. High means somebody else is blocked until this person acts.
     */
    public function priority(): NotificationPriority
    {
        return match ($this) {
            // Work is stopped until the reviewer answers, the overdue task is answered for, or
            // the cancelled project's tasks are dealt with.
            self::TaskSubmittedForReview, self::TaskOverdue, self::ProjectCancelled => NotificationPriority::High,

            // Something changed about who owns the work, or it finished. Due tomorrow sits here
            // too: it is a deadline the reader has to plan around today, which is more than a
            // status change and less than the thing being late already.
            self::TaskAssigned, self::TaskReassigned, self::TaskCompleted, self::TaskDeleted,
            self::TaskDueTomorrow => NotificationPriority::Normal,

            // Worth knowing between tasks; never worth interrupting for. These are also the two
            // that group hardest — a busy discussion is the case §11's dedup rule was written
            // for.
            self::TaskStatusChanged, self::TaskCommented => NotificationPriority::Low,
        };
    }

    /**
     * The channels this type may be delivered on.
     *
     * Every type answers `[InApp]` in Phase 2 and the engine delivers exactly the in-app one.
     * The list exists so Phase 12 switches a channel on by adding a case here — see
     * NotificationChannel. NotificationServiceTest asserts the list is still in-app only, so
     * turning one on is a deliberate, visible change rather than a silent one.
     *
     * @return list<NotificationChannel>
     */
    public function channels(): array
    {
        return [NotificationChannel::InApp];
    }

    /**
     * The acts that RESOLVE a notification of this type — the things a recipient can do that
     * mean the request for attention has been answered (decision 2-48).
     *
     * An act is named by the type the act itself produces, because that is the one vocabulary
     * this enum already has for "something happened to this object": approving a task is
     * `TaskCompleted`, requesting changes is `TaskStatusChanged`. NotificationService then has
     * to know nothing about tasks, reviews or statuses to apply the rule — it matches the type
     * it is writing against this list, for the same object, for the actor alone.
     *
     * **Most types answer `[]`, and that is the point.** A notification is a request for
     * attention and attention has been paid when the person acts on the thing — but only some
     * types have an act that means that. A reviewer asked to review rules on the task, and the
     * question is closed. Nothing resolves `TaskCommented`: reading a comment is not answering
     * it, replying to it is not answering it either, and a discussion that quietly cleared
     * itself when you spoke in it would hide the reply. So this is a fact about the KIND,
     * listed here beside priority(), tab(), requires() and channels(), rather than a blanket
     * rule in the engine that would have to grow an exception per type.
     *
     * @return list<self>
     */
    public function resolvedBy(): array
    {
        return match ($this) {
            // The two review verdicts, and the only two moves out of In review a reviewer can
            // make (TaskStatus::TRANSITIONS): approve, which fires TaskCompleted, and request
            // changes, which fires TaskStatusChanged. Either one answers "is this good?", which
            // is the whole content of the request.
            self::TaskSubmittedForReview => [self::TaskStatusChanged, self::TaskCompleted],

            // Everything else is news, not a question. There is nothing you can do that means
            // "assignment dealt with" or "comment dealt with" other than reading it, and
            // reading is already a state this table keeps.
            default => [],
        };
    }

    /**
     * The inverse of resolvedBy(), asked the way the engine asks it: an act of THIS type has
     * just happened — which types does it close?
     *
     * Derived rather than written out, so the fact is stated once. There are ten cases; this is
     * a loop over an enum, not a query.
     *
     * @return list<self>
     */
    public function resolves(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type): bool => in_array($this, $type->resolvedBy(), true),
        ));
    }

    /**
     * The permission a person must hold before they may receive this type at all.
     *
     * `tasks.view` for everything in Phase 2, including the cancelled-project prompt: that
     * prompt asks its reader to close or reassign the project's open tasks, and somebody who
     * cannot see a task cannot do either.
     */
    public function requires(): Permission
    {
        return match ($this) {
            default => Permission::TasksView,
        };
    }

    /**
     * The one-line summary the bell and the Center show, in the singular or grouped form.
     *
     * Written on the server, once, for the same reason a status tone is: the grouped wording
     * is the visible half of the dedup rule — §11's own example is "12 new comments in
     * [task]" — and a screen that composed it from `count` and a template would be a second
     * place for the rule to be stated differently.
     *
     * @param  array<string, mixed>  $payload
     */
    public function summary(array $payload, int $count = 1): string
    {
        $title = (string) ($payload['title'] ?? 'a record');
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $grouped = $count > 1;

        return match ($this) {
            self::TaskAssigned => $grouped
                ? sprintf('%d assignment changes on "%s"', $count, $title)
                : sprintf('You were assigned "%s"', $title),
            self::TaskReassigned => $grouped
                ? sprintf('%d assignment changes on "%s"', $count, $title)
                : sprintf('"%s" was reassigned', $title),
            // The reason the mover typed, when there is one and when this row still stands for
            // one event — decision 2-49. "…moved to Changes requested" tells an assignee
            // nothing about why, and the why was already in the payload's context block; it
            // simply never reached the sentence. The grouped branch drops it deliberately: a
            // row standing for five moves cannot carry five reasons, and printing the last
            // one would attach it to a sentence that is about all of them. Five moves in two
            // minutes is also the one case where the reason is least worth reading.
            self::TaskStatusChanged => $grouped
                ? sprintf('%d status changes on "%s"', $count, $title)
                : self::withReason(
                    sprintf('"%s" moved to %s', $title, (string) ($context['to_label'] ?? 'another status')),
                    $context,
                ),
            self::TaskCommented => $grouped
                ? sprintf('%d new comments in "%s"', $count, $title)
                : sprintf('New comment in "%s"', $title),
            // A resubmission groups into the reviewer's existing unread row, so without a
            // plural branch the second submission was invisible: the sentence still described
            // the first one, and the only sign anything had changed was `count`, which the
            // screen deliberately does not print. Found by walking the acceptance flow — the
            // reviewer requests changes, the assignee fixes and sends it back, and the bell
            // says exactly what it said an hour ago.
            self::TaskSubmittedForReview => $grouped
                ? sprintf('"%s" is waiting for your review again', $title)
                : sprintf('"%s" is waiting for your review', $title),
            self::TaskCompleted => sprintf('"%s" was completed', $title),
            self::TaskDeleted => sprintf('"%s" was deleted', $title),
            self::TaskOverdue => sprintf('"%s" is overdue', $title),
            self::TaskDueTomorrow => sprintf('"%s" is due tomorrow', $title),
            self::ProjectCancelled => sprintf(
                '"%s" was cancelled — %d open %s to close or reassign',
                $title,
                (int) ($context['open_task_count'] ?? 0),
                (int) ($context['open_task_count'] ?? 0) === 1 ? 'task' : 'tasks',
            ),
        };
    }

    /**
     * The longest a reason may be inside a summary.
     *
     * The field itself allows 500 characters (ChangeTaskStatusRequest), which is right for the
     * activity trail, where it has a paragraph to sit in. A notification summary is one line in
     * a 320 px popover and one line in a list row, both of which truncate with an ellipsis — so
     * a 500-character reason would not overflow the layout, it would simply push the part that
     * says what happened off the end of every row it appeared in. Cutting it here means the
     * sentence the reader needs — the task and the status — is never the part that is lost, and
     * the full text is one click away on the task where it was typed.
     */
    private const REASON_LIMIT = 120;

    /**
     * A summary with the actor's own words appended, when the event carried any.
     *
     * Free text a person typed, so two things happen to it before it joins a sentence:
     *
     *   - **whitespace is collapsed.** The reason box is a textarea and people press Enter in
     *     it. A newline in a one-line summary either breaks the row's height or is swallowed by
     *     `truncate` along with everything after it.
     *   - **it is cut to REASON_LIMIT.** See above.
     *
     * Nothing is escaped here: the summary is interpolated as text by Vue, never as HTML, and
     * escaping at composition time would put `&amp;` into an export and a `sr-only` table.
     *
     * @param  array<string, mixed>  $context
     */
    private static function withReason(string $sentence, array $context): string
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', (string) ($context['reason'] ?? '')));

        if ($reason === '') {
            return $sentence;
        }

        if (mb_strlen($reason) > self::REASON_LIMIT) {
            $reason = rtrim(mb_substr($reason, 0, self::REASON_LIMIT)).'…';
        }

        return $sentence.': '.$reason;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
