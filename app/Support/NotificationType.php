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

            // Something changed about who owns the work, or it finished.
            self::TaskAssigned, self::TaskReassigned, self::TaskCompleted, self::TaskDeleted => NotificationPriority::Normal,

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
            self::TaskStatusChanged => $grouped
                ? sprintf('%d status changes on "%s"', $count, $title)
                : sprintf('"%s" moved to %s', $title, (string) ($context['to_label'] ?? 'another status')),
            self::TaskCommented => $grouped
                ? sprintf('%d new comments in "%s"', $count, $title)
                : sprintf('New comment in "%s"', $title),
            self::TaskSubmittedForReview => sprintf('"%s" is waiting for your review', $title),
            self::TaskCompleted => sprintf('"%s" was completed', $title),
            self::TaskDeleted => sprintf('"%s" was deleted', $title),
            self::TaskOverdue => sprintf('"%s" is overdue', $title),
            self::ProjectCancelled => sprintf(
                '"%s" was cancelled — %d open %s to close or reassign',
                $title,
                (int) ($context['open_task_count'] ?? 0),
                (int) ($context['open_task_count'] ?? 0) === 1 ? 'task' : 'tasks',
            ),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
