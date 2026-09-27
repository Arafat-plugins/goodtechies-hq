<?php

namespace App\Console\Commands;

use App\Events\MeetingReminderDue;
use App\Models\Meeting;
use App\Services\NotificationService;
use App\Support\NotificationType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Every minute: one reminder per meeting that starts within the next fifteen (master prompt
 * Part D §12, *"reminder 15 min before"*), to everybody in the room **including the organiser**.
 *
 * ## A command, not a job — and the repo had already decided this
 *
 * `routes/console.php` is this application's whole schedule, and every recurring thing in it is
 * an `hq:*` command: `hq:generate-recurring-tasks`, `hq:flag-overdue`, `hq:notify-due-tomorrow`,
 * `hq:timer-watchdog`, `hq:mark-absent`. A queued job would need a scheduled command to
 * dispatch it anyway, so the choice is not *command vs job* but *command, or command plus job*.
 * The extra hop buys something only when the work is long or must survive a worker restart
 * mid-run; this is one indexed query over a fifteen-minute window and a handful of events, on a
 * single VPS. So: a command, `withoutOverlapping()`, like its four siblings.
 *
 * ## How "exactly once, however often the scheduler runs" is guaranteed
 *
 * **The notifications table is the memory.** A meeting that has been reminded has a
 * `meeting.reminder` row under its group key; one that has not, has not.
 * `NotificationService::alreadySentFor()` asks that for the whole batch in one query, and this
 * command fires `MeetingReminderDue` only for the rest.
 *
 * Three mechanisms were available and this is the third:
 *
 *   1. **A `meetings.reminder_sent_at` column.** Rejected: it is a second record of a fact the
 *      notifications table already holds, and the two can disagree — a reminder that was
 *      written and then a transaction that rolled back, a row deleted by a retention job, a
 *      restore from a backup taken between the two writes. It is also the third stored copy of
 *      a derived fact this slice has refused (see `MeetingStatus` on `completed`).
 *   2. **A cache lock or a `Cache::add()` key.** Rejected: Redis is a cache here, not a system
 *      of record. `FLUSHALL`, an eviction under memory pressure, or a Redis restart would each
 *      re-send every reminder in the window — and the window is fifteen minutes wide, so "it
 *      would only double up occasionally" is not true; it would double up every minute until
 *      the meeting started.
 *   3. **The notifications table.** It is durable, it is already the thing that would have to
 *      be consistent with any of the other two, and it is what `hq:flag-overdue` and
 *      `hq:notify-due-tomorrow` both already use for the same question. Nothing new is invented
 *      and there is nothing to keep in step.
 *
 * It is not only the cheapest answer, it is the most robust one: running this at 09:00 and
 * again at 09:00:30, running it for the first time after an hour of downtime, and running it
 * after a restore all send the same one reminder per meeting.
 *
 * **The one edge it accepts on purpose.** A meeting that is reminded and then moved a week out
 * is not reminded again, because the memory is "this meeting has had its reminder", not "this
 * meeting has had a reminder for this start time". Part D asks for a reminder, singular, and
 * moving a meeting already sends `meeting.updated` to the same people with the new time in the
 * sentence — so nobody is uninformed, and nobody gets two rows saying the same thing.
 *
 * ## What it does NOT do
 *
 * Nothing here writes to `meetings`. No flag, no timestamp, no status. The window is a scope
 * (`Meeting::scopeReminderDue()`) evaluated fresh every run, so it is right at every minute of
 * every day — the same discipline that keeps the overdue bucket a query.
 */
#[Signature('hq:remind-meetings {--as-of= : The moment to measure against (default: now)}')]
#[Description('Remind participants and the organizer about meetings starting within 15 minutes')]
class SendMeetingReminders extends Command
{
    public function handle(NotificationService $notifications): int
    {
        $asOf = $this->asOf();

        // Cancelled meetings are excluded by the scope, and so are ones that have already
        // started: a reminder arriving with the meeting is noise, and one arriving after it is
        // worse than none.
        $due = Meeting::query()
            ->reminderDue($asOf)
            ->orderBy('start_at')
            ->orderBy('id')
            ->get();

        if ($due->isEmpty()) {
            $this->info(sprintf('No meetings start within %d minutes of %s.', Meeting::REMINDER_LEAD_MINUTES, $asOf->toDateTimeString()));

            return self::SUCCESS;
        }

        // The whole of "exactly once" — one query for the batch, against the rows this
        // command's own previous runs wrote. See the class docblock.
        $alreadyReminded = $notifications->alreadySentFor(NotificationType::MeetingReminder, $due);

        $sent = 0;

        foreach ($due as $meeting) {
            if (in_array((int) $meeting->getKey(), $alreadyReminded, true)) {
                continue;
            }

            event(new MeetingReminderDue($meeting, $asOf));

            $sent++;
        }

        $this->info(sprintf(
            '%d meeting%s starting within %d minutes of %s: %d reminded, %d already reminded.',
            $due->count(),
            $due->count() === 1 ? '' : 's',
            Meeting::REMINDER_LEAD_MINUTES,
            $asOf->toDateTimeString(),
            $sent,
            $due->count() - $sent,
        ));

        return self::SUCCESS;
    }

    /**
     * The moment to measure against. A parameter rather than a call to `now()` inside the
     * query, for the reason `hq:flag-overdue`'s `--as-of` is one: it is what makes the fifteen
     * minutes testable at a fixed instant. An unparseable value falls back to now rather than
     * throwing — a cron entry with a typo in it should still send the reminders.
     */
    private function asOf(): Carbon
    {
        $value = trim((string) ($this->option('as-of') ?? ''));

        if ($value === '') {
            return Carbon::now();
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            $this->warn(sprintf('Could not read --as-of=%s; measuring against now.', $value));

            return Carbon::now();
        }
    }
}
