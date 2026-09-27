<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateNotificationDefaultRequest;
use App\Models\NotificationPreference;
use App\Services\AuditLogger;
use App\Support\AuditEvent;
use App\Support\NotificationChannel;
use App\Support\NotificationTab;
use App\Support\NotificationType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Notifications (master prompt Part E, Phase 12: *"Admin → Notifications defaults
 * (`notification_preferences`: per event type, in-app on/off; other channels listed but
 * disabled until spec post-MVP Phase 2)"*).
 *
 * ## The grid is derived, top to bottom
 *
 * The rows are `NotificationType::cases()`, grouped by each type's own `tab()`, in
 * `NotificationTab` order. The columns are `NotificationChannel::cases()`. Whether a cell can
 * be switched at all is `in_array($channel, $type->channels(), true)` — the type's own
 * statement of which channels are real. Nothing in this file lists a type, a tab or a channel
 * by hand, so a `NotificationType` added in a later phase appears on this screen, in its tab,
 * with the right cells live, without an edit here.
 *
 * The one thing that is written out is the human label per type, and it has a fallback rather
 * than an exhaustive `match`: a case this list has not heard of loses its sentence, not the
 * screen. `NotificationType` already carries the facts the ENGINE needs about a kind — its tab,
 * its priority, its channels, the permission it requires, the sentence it prints in the bell.
 * A noun phrase for a settings row is the one thing only this screen wants, and the bell's
 * `summary()` cannot supply it: that method composes an event sentence out of a payload, and
 * there is no payload here.
 *
 * ## Absent means default
 *
 * `notification_preferences` holds exceptions only and nothing seeds it, so a freshly installed
 * database has an empty table and every switch on this screen reads On. See the model and the
 * migration for why the alternative — a row per type × channel — goes stale the moment a case
 * is added.
 *
 * ## Every change is audited
 *
 * Part C §4 requires *configuration changes* in `audit_logs`, and a notification default is one:
 * it decides, agency-wide, who is told about what. The row records the pair and the old value,
 * where old is **`null` when there was no row** — which is the difference between "somebody
 * turned this back on" and "this has always been on" and is not recoverable afterwards.
 */
class NotificationDefaultsController extends Controller
{
    /**
     * What each notification type is called on this screen.
     *
     * Phrased as the thing that happened, because that is what somebody is deciding to be told
     * about. A type that is not here falls back to a humanised form of its value.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'task.assigned' => 'A task is assigned to you',
        'task.reassigned' => 'A task is reassigned',
        'task.status_changed' => 'A task changes status',
        'task.commented' => 'Somebody comments on a task',
        'task.submitted_for_review' => 'A task is sent for your review',
        'task.completed' => 'A task is completed',
        'task.deleted' => 'A task is deleted',
        'task.overdue' => 'A task becomes overdue',
        'task.due_tomorrow' => 'A task is due tomorrow',
        'project.cancelled' => 'A project is cancelled',
        'leave.requested' => 'Somebody asks for leave',
        'leave.approved' => 'Your leave is approved',
        'leave.rejected' => 'Your leave is turned down',
        'leave.correction_requested' => 'Your leave needs a correction',
        'message.received' => 'A new message arrives',
        'message.mentioned' => 'Somebody names you in a message',
        'announcement.posted' => 'An announcement is posted',
        'meeting.scheduled' => 'A meeting is scheduled',
        'meeting.updated' => 'A meeting is moved or changed',
        'meeting.cancelled' => 'A meeting is cancelled',
        'meeting.reminder' => 'A meeting starts in fifteen minutes',
    ];

    public function index(): Response
    {
        $overrides = NotificationPreference::overrides();

        return Inertia::render('Admin/NotificationDefaults', [
            'channels' => $this->channels(),
            'groups' => $this->groups($overrides),
        ]);
    }

    /**
     * Turn one type's one channel on or off.
     *
     * `updateOrCreate` on the unique pair, so two Admins on the same row cannot make two rows.
     * A value that is already what was asked for writes nothing and logs nothing — the same
     * early return `SettingsService::set()` makes, and for the same reason: an audit log full
     * of rows recording that nothing changed is an audit log nobody reads.
     *
     * "Already what was asked for" is measured against the **effective** answer, not the stored
     * one, and that is what keeps this table exceptions-only: switching a pair *on* when there is
     * no row is asking for the default, so it writes no row. Otherwise the first click anybody
     * made on any switch would leave a row behind saying `enabled = true`, the table would fill
     * with rows that change nothing, and the audit log would carry an entry per no-op.
     */
    public function update(UpdateNotificationDefaultRequest $request, AuditLogger $audit): RedirectResponse
    {
        $type = $request->type();
        $channel = $request->channel();
        $enabled = $request->enabled();

        $preference = NotificationPreference::query()
            ->where('type', $type->value)
            ->where('channel', $channel->value)
            ->first();

        // `null` is not `false`: it means nobody has ever set this pair, and the audit row is
        // the only place that distinction survives.
        $old = $preference === null ? null : (bool) $preference->enabled;

        // Absent means default, and the default for a channel the type names is on — so an
        // untouched pair is already on and asking for it again is not a change.
        if (($old ?? true) === $enabled) {
            return back();
        }

        $preference = NotificationPreference::query()->updateOrCreate(
            ['type' => $type->value, 'channel' => $channel->value],
            ['enabled' => $enabled],
        );

        $audit->recordFor(
            AuditEvent::ConfigurationChanged,
            'notification_preference',
            $preference->id,
            ['type' => $type->value, 'channel' => $channel->value, 'enabled' => $old],
            ['type' => $type->value, 'channel' => $channel->value, 'enabled' => $enabled],
            $request->user(),
        );

        return back()->with('success', sprintf(
            '%s — %s notifications are %s.',
            self::label($type),
            $this->channelLabel($channel),
            $enabled ? 'on' : 'off',
        ));
    }

    /**
     * The three channels, and for each one whether anything sends on it at all.
     *
     * `available` is asked of the types rather than declared: a channel is real when at least
     * one type names it in `channels()`. Today that is `in_app` and only `in_app`, which is how
     * the screen knows to draw the other two as *off, no sender* instead of as a switch that
     * would lie.
     *
     * @return list<array<string, mixed>>
     */
    private function channels(): array
    {
        $channels = [];

        foreach (NotificationChannel::cases() as $channel) {
            $available = false;

            foreach (NotificationType::cases() as $type) {
                if (in_array($channel, $type->channels(), true)) {
                    $available = true;

                    break;
                }
            }

            $channels[] = [
                'value' => $channel->value,
                'label' => $this->channelLabel($channel),
                'available' => $available,
                'note' => $available
                    ? $this->channelNote($channel)
                    : 'Nothing sends on this channel yet, so it is off for every kind and cannot be switched on. It is listed because the engine already carries it, and a later phase turns it on by building the sender.',
            ];
        }

        return $channels;
    }

    /**
     * Every type, grouped by the Center tab it lands on, with its switch per channel.
     *
     * @param  array<string, bool>  $overrides
     * @return list<array<string, mixed>>
     */
    private function groups(array $overrides): array
    {
        $groups = [];

        foreach (NotificationTab::cases() as $tab) {
            if ($tab === NotificationTab::All || ! $tab->isBuilt()) {
                continue;
            }

            $types = [];

            foreach ($tab->types() as $type) {
                $cells = [];

                foreach (NotificationChannel::cases() as $channel) {
                    $cells[] = [
                        'channel' => $channel->value,
                        'switchable' => in_array($channel, $type->channels(), true),
                        'enabled' => NotificationPreference::enabledIn($overrides, $type, $channel),
                    ];
                }

                $types[] = [
                    'value' => $type->value,
                    'label' => self::label($type),
                    'channels' => $cells,
                ];
            }

            $groups[] = [
                'key' => $tab->value,
                'label' => $tab->label(),
                'types' => $types,
            ];
        }

        return $groups;
    }

    private static function label(NotificationType $type): string
    {
        return self::LABELS[$type->value] ?? Str::ucfirst(Str::headline(str_replace('.', ' ', $type->value)));
    }

    private function channelLabel(NotificationChannel $channel): string
    {
        return match ($channel) {
            NotificationChannel::InApp => 'In-app',
            NotificationChannel::WebPush => 'Browser push',
            NotificationChannel::Mail => 'Email',
        };
    }

    private function channelNote(NotificationChannel $channel): string
    {
        return match ($channel) {
            NotificationChannel::InApp => 'The bell in the top bar and the Notification Center. Turning a kind off here stops the row being written at all — nobody gets it, and it does not appear later.',
            NotificationChannel::WebPush => 'A notification pushed by the browser while the app is closed.',
            NotificationChannel::Mail => 'The email digest.',
        };
    }
}
