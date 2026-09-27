import { usePage } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { computed, readonly, ref, watch } from 'vue';
import { SHELL_POLL_MS, conversationChannel, useLiveRefresh } from '@/Components/Realtime/live';
import { liveReload } from '@/Components/Realtime/reload';
import type { AnnouncementBanner, ConversationSummary } from '@/Components/Messages/messages';

/**
 * The shell's own live state: the announcement banner, app-wide, and the Messages nav row's
 * unread indicator.
 *
 * Both are POLISH-BACKLOG §A.3 lines — *"Messages — anywhere in the app: the Messages nav row's
 * unread indicator"* and *"Announcement banner: a new announcement appears without a reload"* —
 * and the banner is decision 6-18's follow-up, which priced a global banner at "a prop in
 * `HandleInertiaRequests`" and left it there.
 *
 * ## Why the state lives in this module and not in the page props
 *
 * The server sends `shell` as an `Inertia::optional()` prop: it is **absent from an ordinary page
 * render** and resolved only on a partial reload that names it. That is not a detail, it is what
 * made a global banner affordable — resolving it costs 5 statements for an Admin and 12 for an
 * employee (`ConversationService::inboxFor()` asks `ProjectPolicy::view` per project channel),
 * and a prop resolved on every response is a prop every response pays for, on a catalogue page
 * that otherwise runs 3. `tests/Feature/Performance` holds the ceilings that say so.
 *
 * The consequence is that `page.props.shell` is `undefined` on every ordinary navigation. So the
 * values are held here, at module scope — they survive an Inertia page swap the way the bell's
 * count does — and `adopt()` takes whatever a response happens to carry. A banner that blinked
 * out on every navigation and came back a request later would be worse than no banner.
 *
 * ## Two transports, and the announcement genuinely is live
 *
 * - The **unread count** is poll-only, on both builds, at `SHELL_POLL_MS`. There is no per-user
 *   inbox channel to subscribe to — the same reason the Messages rail polls (§A.3) — and
 *   inventing one would mean a channel whose audience is "this person's whole inbox", which is
 *   not a thing any policy answers.
 * - The **announcement** arrives on a socket build the moment it is posted, because an
 *   announcement is a message in a conversation that already has `conversation.{id}`, already
 *   has a policy-backed auth callback, and already broadcasts `ConversationActivity`. Everybody
 *   who may see the banner is authorised on that channel, so there is nothing to add on the
 *   server at all. The id comes from the payload itself, which is why the channel is a getter:
 *   it is `null` until the first read answers, and the subscription starts when it appears.
 *
 * ## The one place this is told rather than asked
 *
 * Opening a thread is what marks it read, so the count is wrong the instant somebody reads
 * something — and the response that marked it read does not carry `shell`. Rather than spend a
 * second request per navigation on it, the Messages page pushes its own sum in (`adoptInbox()`).
 * It is the same arithmetic over the same policy-checked list, taken from the screen that is
 * looking at it and refreshing it every fifteen seconds anyway, so the badge and the page cannot
 * disagree — and every other screen in the application is covered by the poll.
 */

/* ------------------------------------------------------------------ the payload */

/** `HandleInertiaRequests::sharedShell()`. */
export interface ShellLive {
    /** Unread messages across this reader's whole inbox, summed on the server. */
    unreadMessages: number;
    /** The newest announcement they may see, or `null` — including when there is none at all. */
    announcement: AnnouncementBanner | null;
    /** The announcements channel's conversation id, for the subscription. `null` with no banner. */
    announcementChannelId: number | null;
}

/* ------------------------------------------------------------------ module state */

const unreadMessages = ref(0);
const announcement = ref<AnnouncementBanner | null>(null);
const channelId = ref<number | null>(null);

/** Has a response ever carried `shell`? Until it has, the badge shows nothing rather than zero. */
const loaded = ref(false);

/** Take whatever a response carried. Called from the watcher below and from nowhere else. */
function adopt(payload: ShellLive): void {
    unreadMessages.value = payload.unreadMessages;
    announcement.value = payload.announcement;
    channelId.value = payload.announcementChannelId;
    loaded.value = true;
}

/**
 * The Messages page's own numbers, from the list it is already holding.
 *
 * `unread_count` per row is the server's, summed here over exactly the rows the server sent —
 * the same sum `Pages/Shared/Messages.vue` prints under its own heading. The announcement row's
 * count decides whether the banner still reads "unread": a reader who has just opened the
 * announcements channel has read it, and the banner going quiet is the whole of how it is
 * dismissed (there is no dismiss button — decision 6-18's shape).
 */
export function adoptInbox(rows: readonly ConversationSummary[]): void {
    if (!loaded.value) {
        // Nothing has been read from the server yet, so there is no banner to correct and a
        // count written now would be overwritten by the first read a moment later anyway.
        return;
    }

    unreadMessages.value = rows.reduce((total, row) => total + row.unread_count, 0);

    const banner = announcement.value;

    if (banner === null) {
        return;
    }

    const row = rows.find((candidate) => candidate.id === banner.conversation_id);

    if (row !== undefined) {
        announcement.value = { ...banner, is_unread: row.unread_count > 0 };
    }
}

/* ------------------------------------------------------------------ the composable */

/**
 * Mount the shell's live state. Every layout calls it once; the values are module-scoped, so a
 * navigation that replaces the layout does not blank them.
 *
 * Two `useLiveRefresh` calls rather than one, which is the shape the Messages rail already uses:
 * the first is the interval that has to run on a socket build too (there is no inbox channel),
 * the second is the announcements subscription and starts no timer of its own. Both funnel into
 * `liveReload`, which merges them — and merges them with whatever the current PAGE is asking for
 * — into one request.
 */
export function useShellLive(): {
    unreadMessages: Readonly<Ref<number>>;
    announcement: Readonly<Ref<AnnouncementBanner | null>>;
    loaded: Readonly<Ref<boolean>>;
} {
    const page = usePage();

    watch(
        () => page.props.shell,
        (payload) => {
            if (payload !== undefined && payload !== null) {
                adopt(payload);
            }
        },
        { immediate: true },
    );

    function refresh(): void {
        liveReload(['shell']);
    }

    // The opening read. `useLiveRefresh` deliberately never makes one — every other caller has
    // just been rendered from the server — but this prop is not IN that render, so somebody has
    // to ask the first time. Once per full page load: `loaded` is module state, so an Inertia
    // navigation that remounts the layout does not ask again.
    if (!loaded.value) {
        refresh();
    }

    useLiveRefresh(null, refresh, { intervalMs: SHELL_POLL_MS });
    useLiveRefresh(() => conversationChannel(channelId.value), refresh, {
        poll: false,
        safetyMs: null,
    });

    return {
        unreadMessages: readonly(unreadMessages),
        announcement: readonly(announcement),
        loaded: readonly(loaded),
    };
}

/**
 * The Messages nav row's badge, for the sidebar. `null` means "say nothing" — before the first
 * read has answered, and when everything is read.
 *
 * Capped at `9+` at the same width as the notification bell's badge and for the same reason:
 * anything wider stretches the pill past the icon. The cap is in the DISPLAY only — the sentence
 * a screen reader gets carries the exact figure, so nothing about this count is rounded anywhere
 * that matters.
 */
export const messagesBadge = computed<{ text: string; label: string } | null>(() => {
    if (!loaded.value || unreadMessages.value < 1) {
        return null;
    }

    const count = unreadMessages.value;

    return {
        text: count > 9 ? '9+' : String(count),
        label: count === 1 ? 'Messages, 1 unread' : `Messages, ${count} unread`,
    };
});
