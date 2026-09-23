<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Support\NotificationChannel;
use App\Support\NotificationTab;
use App\Support\NotificationType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The notification engine: the one way a notification comes into existence, and the only thing
 * that writes `notifications`.
 *
 * The master prompt's §11 pipeline, in order, and this class is each arrow of it:
 *
 *     event → recipients → priority → dedup/group → stored row → realtime push
 *
 * `event` is NotificationDispatcher's half — it turns one of the seven task events into a call
 * to notify() with a type, an object and a candidate list. Everything after that is here.
 * `realtime push` is Phase 6 and is not built; the bell polls, which is why the read methods
 * below are shaped to be cheap rather than clever.
 *
 * ## Recipients are filtered by PERMISSION, never by role name
 *
 * eligible() drops anybody who does not hold `NotificationType::requires()`. There is no role
 * named anywhere in this file, and the Accountant receives nothing because they hold no
 * `tasks.*` key — the same reason every ability in TaskPolicy falls at its first line for them.
 * A later role that holds `tasks.view` starts receiving task notifications with no edit here.
 *
 * That is the type-shaped half of the rule. The object-shaped half — "and they can see THIS
 * task" — is a gate check in NotificationDispatcher, where the object is known. Both are
 * applied. The split is the same one ManagesDiscussion draws between a list-shaped query and a
 * per-object policy call, for the same reason: a service called from a job or a console command
 * only ever meets the second.
 *
 * ## Grouping happens HERE, at dispatch, not at display
 *
 * §11: "repeated events on the same object within `settings.notification_group_window_minutes`
 * collapse into one row with an updated count". Twelve comments inside the window are ONE row
 * carrying `count: 12` — written that way, so a screen that never groups anything still shows
 * "12 new comments in …", and so the count survives being read by a report or an export. The
 * window is read from settings on every call through SettingsService (which caches it for the
 * request); it is never a constant here and never a literal 2.
 *
 * ## In-app only
 *
 * deliver() writes a row and does nothing else. No mail, no Web Push, no broadcast — the
 * `channels` list on the type is carried through so that Phase 12 has somewhere to hang a
 * second delivery, and today it resolves to exactly one channel and one row.
 */
class NotificationService
{
    /** The settings key §11 names. Read through SettingsService; never inlined as a number. */
    public const WINDOW_SETTING = 'notification_group_window_minutes';

    public function __construct(private readonly SettingsService $settings) {}

    /*
    |--------------------------------------------------------------------------
    | Writing
    |--------------------------------------------------------------------------
    */

    /**
     * Notify these people about this object. The only entry point.
     *
     * Returns the rows that were written or grown, which is how the caller (and a test) sees
     * both halves of the dedup rule: twelve calls inside the window return the same row twelve
     * times, with `count` one higher each time.
     *
     * The actor is dropped from the recipient list. Being told about something you have just
     * done is noise, and the bell in an agency of fifteen has to stay worth looking at.
     *
     * @param  iterable<int, User>  $recipients  candidates; eligible() decides who actually gets one
     * @param  array{title?: string, context?: array<string, mixed>}  $payload
     * @return Collection<int, Notification>
     */
    public function notify(
        NotificationType $type,
        Model $target,
        iterable $recipients,
        array $payload = [],
        ?User $actor = null,
    ): Collection {
        // Before anybody is told, the actor's own mail about this object is closed where this
        // act answers it — and BEFORE the empty-recipient return below, because the commonest
        // resolving act on this team has no recipients at all. Shahadat is the reviewer, the
        // creator and the actor, so his approval writes zero rows; if resolving hung off a row
        // being written, the one flow 2-48 was raised about would be the one flow it missed.
        $this->resolveFor($type, $target, $actor);

        $recipients = $this->eligible($type, $recipients, $actor);

        if ($recipients->isEmpty()) {
            return new Collection;
        }

        $groupKey = $this->groupKey($type, $target);
        $body = $this->payload($target, $payload, $actor);
        $since = $this->windowStart();

        return $recipients->map(
            fn (User $user): Notification => $this->deliver($type, $user, $groupKey, $body, $since),
        );
    }

    /**
     * Close the ACTOR's own rows that this act answers (decision 2-48).
     *
     * ## The rule
     *
     * A notification asks for attention, and attention has been paid when the person acts on
     * the thing. `NotificationType::resolvedBy()` says which acts count for which type —
     * a review request is answered by a verdict, and nothing answers a comment. So: when an act
     * of type T happens to object O by person P, every row of P's about O whose type names T as
     * resolving is marked resolved.
     *
     * ## Three things it deliberately is not
     *
     *   1. **Not read.** `is_read` and `read_at` are untouched. Decision 2-33 is written on top
     *      of `is_read` — a row you have looked at does not absorb the next event — and mixing
     *      the two would close the group as well as quieten it, so the resubmission would start
     *      a fresh row of one and say, for the second time, exactly what it said the first.
     *      Resolving takes the row out of the badge and out of both lists; the group stays open
     *      and `deliver()` re-opens it when it grows.
     *   2. **Not everybody's.** `forUser($actor)` and nothing wider. Two Admins can both be
     *      reviewers of the same task; one of them ruling on it has told the other nothing, and
     *      silently emptying their bell would lose the only sign they had that they were asked.
     *   3. **Not a second door.** It is here because this service is the only thing that writes
     *      `notifications`, and it is called from notify() rather than from the dispatcher so
     *      that adding a resolving act is a line in the enum and nothing else.
     *
     * One UPDATE over a set of group keys — the same `type:Class:id` strings groupKey() builds,
     * so "about this object" means here what it means everywhere else in this file.
     */
    private function resolveFor(NotificationType $type, Model $target, ?User $actor): void
    {
        // A date-driven send (overdue, due tomorrow) has no actor: nobody acted, so nothing is
        // answered.
        if ($actor === null) {
            return;
        }

        $resolves = $type->resolves();

        if ($resolves === []) {
            return;
        }

        $keys = array_map(
            fn (NotificationType $resolved): string => $this->groupKey($resolved, $target),
            $resolves,
        );

        $now = now();

        Notification::query()
            ->forUser($actor)
            ->unread()
            ->whereIn('group_key', $keys)
            ->update(['resolved_at' => $now, 'updated_at' => $now]);
    }

    /**
     * Mark one notification read. Idempotent — a second call is a no-op, not a second timestamp.
     *
     * Takes the owner as well as the row and asserts they match. The controller has already
     * scoped its lookup to the signed-in user, so this can only fire for a caller that is not
     * HTTP; belt and braces on the one rule this table has.
     */
    public function markRead(User $user, Notification $notification): Notification
    {
        if ((int) $notification->user_id !== (int) $user->getKey()) {
            return $notification;
        }

        if ($notification->is_read) {
            return $notification;
        }

        $notification->forceFill(['is_read' => true, 'read_at' => now()])->save();

        return $notification;
    }

    /**
     * Mark everything this person has unread as read, and say how many that was.
     *
     * One UPDATE rather than a row at a time: "mark all read" on a bell that has been ignored
     * for a week is the one moment this table is written in bulk. `updated_at` is set by hand
     * because a mass update does not go through the model.
     */
    public function markAllRead(User $user): int
    {
        $now = now();

        return Notification::query()
            ->forUser($user)
            ->unread()
            ->update(['is_read' => true, 'read_at' => $now, 'updated_at' => $now]);
    }

    /**
     * Which of these objects has ALREADY had a notification of this type, ever.
     *
     * This is how "once per task, not per day" is answered without a column: the notifications
     * table is its own memory, so `hq:flag-overdue` re-run twice on the same morning — or run
     * for the first time after three days down — still sends one notification per task. See
     * FlagOverdueTasks.
     *
     * One query for the whole batch, by group key, because the alternative is a query per
     * overdue task every morning.
     *
     * @param  iterable<int, Model>  $targets
     * @return list<int> the ids of the targets that already have one
     */
    public function alreadySentFor(NotificationType $type, iterable $targets): array
    {
        $idsByKey = [];

        foreach ($targets as $target) {
            $idsByKey[$this->groupKey($type, $target)] = (int) $target->getKey();
        }

        if ($idsByKey === []) {
            return [];
        }

        $sent = Notification::query()
            ->where('type', $type->value)
            ->whereIn('group_key', array_keys($idsByKey))
            ->distinct()
            ->pluck('group_key')
            ->all();

        return array_values(array_unique(array_map(
            fn (string $key): int => $idsByKey[$key],
            $sent,
        )));
    }

    /*
    |--------------------------------------------------------------------------
    | Reading — the bell and the Centre
    |--------------------------------------------------------------------------
    */

    /**
     * The bell's badge.
     *
     * One count, no join, answered entirely from the partial index over unread rows
     * (`notifications_unread`, leading with user_id) — which is the index the dedup lookup
     * needs anyway. The bell polls this every 15 seconds for every signed-in user until Phase 6
     * replaces the poll with Reverb, so it is deliberately the cheapest query in the codebase.
     */
    public function unreadCount(User $user): int
    {
        return Notification::query()->forUser($user)->unread()->count();
    }

    /**
     * The bell's dropdown: the newest few, read or not.
     *
     * Read ones included on purpose — a bell that empties itself the moment you glance at it
     * gives you no way back to what you just dismissed.
     *
     * Resolved ones are not, and the two are not the same thing. Glancing at a row is not
     * dealing with it, so a read row stays; a row whose subject you have ruled on is finished,
     * and leaving it in the bell is exactly the complaint 2-48 records.
     *
     * @return EloquentCollection<int, Notification>
     */
    public function recent(User $user, int $limit = Notification::BELL_LIMIT): EloquentCollection
    {
        return Notification::query()
            ->forUser($user)
            ->stillOpen()
            ->newestFirst()
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * The Notification Center's list, one tab at a time.
     *
     * Same set as the bell, one page at a time: read rows stay, resolved rows are gone. The
     * Center is the long form of the bell, not an archive — what became of a task is on the
     * task, where the activity trail records who ruled on it and why.
     *
     * @return LengthAwarePaginator<int, Notification>
     */
    public function page(User $user, NotificationTab $tab, int $perPage = Notification::PAGE_SIZE): LengthAwarePaginator
    {
        return Notification::query()
            ->forUser($user)
            ->stillOpen()
            ->onTab($tab)
            ->newestFirst()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * How many unread rows sit on each tab, so the Center can badge them.
     *
     * One grouped query rather than one per tab: seven counts off the same partial index.
     *
     * @return array<string, int>
     */
    public function unreadByTab(User $user): array
    {
        $byType = Notification::query()
            ->forUser($user)
            ->unread()
            ->groupBy('type')
            ->selectRaw('type, count(*) as total')
            ->pluck('total', 'type')
            ->all();

        $counts = [];

        foreach (NotificationTab::cases() as $tab) {
            $counts[$tab->value] = array_sum(array_map(
                fn (NotificationType $type): int => (int) ($byType[$type->value] ?? 0),
                $tab->types(),
            ));
        }

        return $counts;
    }

    /*
    |--------------------------------------------------------------------------
    | The rules behind the writes
    |--------------------------------------------------------------------------
    */

    /**
     * Write the row, or grow the one this event belongs with.
     *
     * The lookup and the write are one transaction with the candidate row locked, because two
     * comments posted in the same second must not each decide there is nothing to group with
     * and produce two rows of one. The row is re-read under the lock for the same reason.
     */
    private function deliver(
        NotificationType $type,
        User $user,
        string $groupKey,
        array $payload,
        Carbon $since,
    ): Notification {
        // In-app only, and said out loud rather than assumed. Phase 12 turns a channel on by
        // adding it to NotificationType::channels() and giving it a branch here; until then the
        // list has exactly one entry and this is the whole of delivery.
        $channels = $type->channels();

        return DB::transaction(function () use ($type, $user, $groupKey, $payload, $since, $channels): Notification {
            $existing = in_array(NotificationChannel::InApp, $channels, true)
                ? Notification::query()
                    ->forUser($user)
                    ->groupable($groupKey, $since)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($existing !== null) {
                $existing->forceFill([
                    'count' => (int) $existing->count + 1,
                    // Latest wins. Every event in a group is about the same object, so the
                    // title and the target are identical; what differs is who acted and what
                    // they did, and "12 new comments, the last one from Tapu" is the honest
                    // reading of a group. `created_at` is left alone — it is when the group
                    // started, and `updated_at` is when it last grew.
                    'payload' => $payload,
                    // The group is asking again, so it is open again. A reviewer who ruled on
                    // this task resolved their row; the assignee has now sent it back, and the
                    // row returns to the bell and the badge carrying count 2 — which is what
                    // makes it read *"…is waiting for your review again"* (decision 2-46)
                    // instead of a fresh row of one repeating the first request.
                    'resolved_at' => null,
                ])->save();

                return $existing;
            }

            return Notification::query()->forceCreate([
                'user_id' => $user->getKey(),
                'type' => $type->value,
                'payload' => $payload,
                'group_key' => $groupKey,
                'count' => 1,
                'is_read' => false,
                'read_at' => null,
            ]);
        });
    }

    /**
     * Who, of the people the dispatcher named, actually gets this.
     *
     * Three rules, in order: not the person who caused it, still active, and holding the
     * permission the TYPE requires. No role is named — see the class docblock.
     *
     * @param  iterable<int, User>  $recipients
     * @return Collection<int, User>
     */
    private function eligible(NotificationType $type, iterable $recipients, ?User $actor): Collection
    {
        return (new Collection($recipients))
            ->filter(fn (mixed $user): bool => $user instanceof User && $user->getKey() !== null)
            ->unique(fn (User $user): int => (int) $user->getKey())
            ->reject(fn (User $user): bool => $actor !== null && (int) $user->getKey() === (int) $actor->getKey())
            ->filter(fn (User $user): bool => $user->isActive() && $user->hasPermission($type->requires()))
            ->values();
    }

    /**
     * The group key: the type and the object, and nothing else.
     *
     * Computed HERE and only here, so "the same object within the window" means one thing
     * everywhere. The recipient is deliberately absent — every lookup is already
     * `where user_id = ? and group_key = ?`, and repeating the user inside the string would be
     * the same fact stored twice, in two formats, able to disagree.
     *
     * The object part is the morph class and the key, the same pair `audit_logs.target_type` /
     * `target_id` uses, so a key read out of the database names a real row.
     */
    private function groupKey(NotificationType $type, Model $target): string
    {
        return sprintf('%s:%s:%d', $type->value, $target->getMorphClass(), (int) $target->getKey());
    }

    /**
     * The stored payload: enough for the bell to draw a line without touching the object, and
     * never more than that.
     *
     * A payload is written once and read by exactly one person, so it must not carry anything
     * that person's access could later change their right to see. The title of a task they are
     * assigned to is safe; a price is not, and nothing here reads one.
     *
     * @param  array{title?: string, context?: array<string, mixed>}  $payload
     * @return array<string, mixed>
     */
    private function payload(Model $target, array $payload, ?User $actor): array
    {
        return [
            'title' => (string) ($payload['title'] ?? ''),
            // The object, so the Center can deep-link to it. Resolved to a URL at read time by
            // NotificationResource, against the READER's surface — a stored link would be the
            // wrong one for anybody whose role changed after it was written.
            'target' => [
                'type' => $target->getMorphClass(),
                'id' => (int) $target->getKey(),
            ],
            'actor' => $actor === null ? null : [
                'id' => (int) $actor->getKey(),
                'name' => (string) $actor->name,
            ],
            'context' => $payload['context'] ?? [],
        ];
    }

    /**
     * The oldest a row may be and still absorb this event.
     *
     * `settings.notification_group_window_minutes` (§11, default 2), read through
     * SettingsService every time — an admin who widens the window widens it for the next event,
     * with no cache to clear and no constant to change. A window of zero or less turns grouping
     * off rather than grouping everything, which is what an admin who types 0 means.
     */
    private function windowStart(): Carbon
    {
        $minutes = (int) $this->settings->get(self::WINDOW_SETTING);

        return $minutes > 0
            ? now()->subMinutes($minutes)
            : now()->addSecond();
    }
}
