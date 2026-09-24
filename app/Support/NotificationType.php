<?php

namespace App\Support;

use Illuminate\Support\Carbon;

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

    /* Leave tab (Phase 5) ---------------------------------------------------------------- */

    /**
     * Somebody applied for leave, or answered a correction request by resubmitting. It goes to
     * the people who can rule on it — `requires()` is `leave.approve`, so the Accountant, who
     * may apply but not approve, never receives one about a colleague.
     */
    case LeaveRequested = 'leave.requested';

    /**
     * The three answers, all of them addressed to the person who asked. `requires()` is
     * `leave.apply`, which every role holds — which is why the ACCOUNTANT has a mailbox from
     * Phase 5 onwards, having had none in Phases 2–4 (see `requires()`).
     */
    case LeaveApproved = 'leave.approved';
    case LeaveRejected = 'leave.rejected';
    case LeaveCorrectionRequested = 'leave.correction_requested';

    /* Messages tab (Phase 6) ------------------------------------------------------------- */

    /**
     * A message in a DM — a thread whose entire audience is you and one other person.
     *
     * **There is deliberately no type for a message in the team or a project channel.** On a
     * team of five to fifteen replacing a Telegram group, a bell row per channel message is a
     * bell nobody reads by Tuesday, and §11's whole purpose is that the bell stays worth
     * looking at. A channel's unread count lives on the channel, in the Messages list, where
     * the reader chose to look; a DM is addressed to one person and has nowhere else to appear.
     * If you want somebody's attention in a channel, you name them — which is the next case,
     * and which is what makes @mention mean something.
     */
    case MessageReceived = 'message.received';

    /**
     * Somebody named you in a message, in any conversation you can see.
     *
     * The one messaging event that is a request rather than news: a mention says *this line is
     * for you*. It is why its priority is High while a DM's is Normal — see priority().
     */
    case MessageMentioned = 'message.mentioned';

    /** A holder of `announcements.send` broadcast something to everybody. */
    case AnnouncementPosted = 'announcement.posted';

    public function tab(): NotificationTab
    {
        return match ($this) {
            self::ProjectCancelled => NotificationTab::System,
            self::LeaveRequested, self::LeaveApproved, self::LeaveRejected,
            self::LeaveCorrectionRequested => NotificationTab::Leave,
            self::MessageReceived, self::MessageMentioned,
            self::AnnouncementPosted => NotificationTab::Messages,
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

            // Somebody cannot plan their week until this is answered, and the days they asked
            // for may be tomorrow. A correction request is the same shape pointed the other
            // way: the request has stopped moving and only the employee can start it again.
            self::LeaveRequested, self::LeaveCorrectionRequested => NotificationPriority::High,

            // Being named is the one messaging event somebody is waiting on an answer to. It is
            // the same shape as a review request pointed at a sentence instead of a task, and it
            // is what a reader is buying when they type somebody's name instead of just talking.
            self::MessageMentioned => NotificationPriority::High,

            // Addressed to you, and nothing is blocked until you answer it — the same rank a
            // leave decision gets. An announcement is news for everybody at once; its loudness
            // is the banner on the Messages page, not the bell.
            self::MessageReceived, self::AnnouncementPosted => NotificationPriority::Normal,

            // The answer to a question they asked. It matters, and nothing is blocked on
            // reading it — the decision has already been made and the calendar already shows it.
            self::LeaveApproved, self::LeaveRejected => NotificationPriority::Normal,

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

            // The three verbs Part D §9 gives an approver, and all three answer "may I have
            // these days". An Admin who approves, rejects or sends a request back has dealt
            // with it, so their own row goes quiet — and only theirs: two Admins can both be
            // looking at the queue, and one ruling has told the other nothing (decision 2-53).
            self::LeaveRequested => [
                self::LeaveApproved,
                self::LeaveRejected,
                self::LeaveCorrectionRequested,
            ],

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
     *
     * ## Phase 5, and the thing it changes on purpose
     *
     * A leave decision goes to the person whose leave it is, so it requires `leave.apply` —
     * which **every** role holds, the ACCOUNTANT included (Part C §1). Until now every type in
     * the catalogue required a `tasks.*` key, so `NotificationPolicy::viewAny()` refused the
     * Accountant outright and they had no mailbox at all: no bell, and a 403 on
     * `/notifications` (decision 2-42's follow-up, which this closes by itself).
     *
     * From Phase 5 they have one, and it contains exactly the leave rows addressed to them and
     * nothing else — the task types still require `tasks.view`, which they still do not hold.
     * That is the catalogue working as designed rather than an exception being carved: nobody
     * named the Accountant to take the mailbox away and nobody names them to give it back.
     * The permission-matrix rows for `/notifications` move from 403 to 200 for that role.
     *
     * A request going TO an approver requires `leave.approve` for the same reason, from the
     * other side: an employee must not be told that a colleague has asked for next week off.
     */
    public function requires(): Permission
    {
        return match ($this) {
            self::LeaveRequested => Permission::LeaveApprove,
            self::LeaveApproved, self::LeaveRejected, self::LeaveCorrectionRequested => Permission::LeaveApply,

            // ## Phase 6, and what it means for the Accountant
            //
            // `messages.use` — the key Phase 6 adds, held by every role but ACCOUNTANT. So the
            // Accountant, who from Phase 5 has a mailbox (decision 5-14), can never receive a
            // message row in it, and nobody had to name them to arrange that.
            //
            // It is also the last of four gates that make "what would a mention of an Accountant
            // mean?" a question with no reachable answer: the mention picker only offers people
            // who can see the conversation, MessageService refuses to write a mention row for
            // anybody who cannot, ConversationPolicy refuses the Accountant every non-task
            // conversation and TaskPolicy refuses them every task one — and if a row were forged
            // past all three, this line drops the notification. Four gates for one rule is not
            // belt and braces; each of them is the natural place for its own question, and the
            // rule is that none of them says "Accountant".
            self::MessageReceived, self::MessageMentioned,
            self::AnnouncementPosted => Permission::MessagesUse,

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

            // Leave (Phase 5). `title` is the applicant's name for the approver's row and the
            // type's name for the applicant's own, because those are the two different things
            // the two readers need first — see NotificationDispatcher.
            //
            // Every one of them prints the DATES. A leave notification without them makes the
            // reader open the request to find out the one thing they wanted to know, and a
            // bell that has to be clicked to be useful is a bell nobody reads.
            self::LeaveRequested => $grouped
                // A resubmission groups into the approver's existing unread row, so without a
                // plural branch the second filing would say exactly what the first said and the
                // only sign anything had changed would be `count`, which the screen does not
                // print. The same fix decision 2-46 made for a resubmitted review.
                ? sprintf('%s has updated a leave request (%s)', $title, self::window($context))
                : sprintf('%s asked for %s leave (%s)', $title, self::leaveType($context), self::window($context)),
            self::LeaveApproved => sprintf(
                'Your %s leave is approved (%s)',
                self::leaveType($context),
                self::window($context),
            ),
            self::LeaveRejected => self::withReason(
                sprintf('Your %s leave was turned down (%s)', self::leaveType($context), self::window($context)),
                $context,
            ),
            self::LeaveCorrectionRequested => self::withReason(
                sprintf('Your %s leave needs a correction (%s)', self::leaveType($context), self::window($context)),
                $context,
            ),

            // Messages (Phase 6). `title` is what the conversation is CALLED to this reader —
            // for a DM that is the other person's name, which is why the singular does not
            // repeat the actor. The grouped branches are the visible half of the dedup rule
            // again: twelve lines from one person in two minutes are one row saying twelve.
            self::MessageReceived => $grouped
                ? sprintf('%d new messages from %s', $count, $title)
                : sprintf('New message from %s', $title),

            // The mention says WHO named you, because that is the first thing you want and it
            // is what tells a mention apart from the comment notification it sits beside. The
            // grouped form drops the name for the reason the grouped status line drops the
            // reason: a row standing for four mentions cannot honestly carry one person's name.
            self::MessageMentioned => $grouped
                ? sprintf('%d mentions of you in %s', $count, $title)
                : sprintf('%s mentioned you in %s', self::actor($payload), $title),

            // Nothing of the announcement's text is in the payload, on purpose: a payload is
            // written once and read later, and the announcement itself is one click away in a
            // conversation every recipient can already open.
            self::AnnouncementPosted => $grouped
                ? sprintf('%d new announcements', $count)
                : sprintf('New announcement from %s', self::actor($payload)),
        };
    }

    /**
     * The dates a leave notification is about, as one phrase: `3 Oct` or `3–5 Oct`.
     *
     * Composed on the server with the rest of the sentence, for the reason the grouped wording
     * is: a screen that assembled it from two ISO strings would be a second place for "what a
     * leave window reads like" to be decided, and the two would drift the first time somebody
     * wanted the year in it.
     *
     * @param  array<string, mixed>  $context
     */
    private static function window(array $context): string
    {
        $from = (string) ($context['start_date'] ?? '');
        $to = (string) ($context['end_date'] ?? '');

        if ($from === '') {
            return 'dates unknown';
        }

        $start = Carbon::parse($from);

        if ($to === '' || $start->isSameDay(Carbon::parse($to))) {
            return $start->isoFormat('D MMM');
        }

        $end = Carbon::parse($to);

        return $start->month === $end->month
            ? sprintf('%d–%s', $start->day, $end->isoFormat('D MMM'))
            : sprintf('%s – %s', $start->isoFormat('D MMM'), $end->isoFormat('D MMM'));
    }

    /**
     * Who did it, as the payload recorded them — or a placeholder, never an empty gap in a
     * sentence. Every payload NotificationService writes carries an `actor` block or an
     * explicit null (a date-driven send has nobody).
     *
     * @param  array<string, mixed>  $payload
     */
    private static function actor(array $payload): string
    {
        $actor = is_array($payload['actor'] ?? null) ? $payload['actor'] : [];
        $name = trim((string) ($actor['name'] ?? ''));

        return $name === '' ? 'Somebody' : $name;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function leaveType(array $context): string
    {
        $type = trim((string) ($context['leave_type'] ?? ''));

        return $type === '' ? 'the' : $type;
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
