/**
 * The Admin → Notifications contract, as the server sends it.
 *
 * Everything here is derived server-side from `NotificationType`, `NotificationTab` and
 * `NotificationChannel` by `Admin/NotificationDefaultsController`. **No list below is written out
 * in TypeScript** — not the types, not the tabs, not the channels, and above all not which
 * channels are real. A notification type added in a later phase arrives on this screen, in its
 * own tab, with the right cells live, without an edit in this folder.
 */

/** One cell of the grid: this type, on this channel. */
export interface NotificationChannelCell {
    /** `App\Support\NotificationChannel` — `in_app`, `web_push` or `mail`. */
    channel: string;
    /**
     * Whether the type's own `channels()` list names this channel — which is to say, whether
     * anything is built that could send it. False is not "off by choice": there is no sender, so
     * the cell is drawn as off and is **not a control**. A switch that turned on a channel with
     * nothing behind it would be a promise the application cannot keep, and the server refuses
     * such a request as well (`UpdateNotificationDefaultRequest`).
     */
    switchable: boolean;
    /** The effective answer: the type's default, overridden by a stored preference. */
    enabled: boolean;
}

export interface NotificationTypeRow {
    /** `App\Support\NotificationType` — the dotted value, which is what the PUT sends back. */
    value: string;
    /** What the event is, in words: "A task is sent for your review". */
    label: string;
    /** One cell per channel, in `NotificationChannel` order. */
    channels: NotificationChannelCell[];
}

/** One Notification Center tab, with the types that land on it. */
export interface NotificationTypeGroup {
    key: string;
    label: string;
    types: NotificationTypeRow[];
}

/** One channel, described once for the whole page rather than on every row. */
export interface NotificationChannelMeta {
    value: string;
    label: string;
    /** True when at least one type names it — see `NotificationChannelCell.switchable`. */
    available: boolean;
    /** What the channel is, or why nothing sends on it yet. */
    note: string;
}

/** Channel labels, so a cell can name itself without the page passing the meta list down. */
export function channelLabels(channels: NotificationChannelMeta[]): Record<string, string> {
    const labels: Record<string, string> = {};

    for (const channel of channels) {
        labels[channel.value] = channel.label;
    }

    return labels;
}
