import { usePage } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { computed, readonly, ref, watch } from 'vue';
import { SHELL_POLL_MS, conversationChannel, useLiveRefresh } from '@/Components/Realtime/live';
import { liveReload } from '@/Components/Realtime/reload';
import type { AnnouncementBanner, ConversationSummary } from '@/Components/Messages/messages';
import { bindChimeUser, noteUnread, rebaseUnread } from '@/lib/sound';

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
 * The server sends `shell` as an `Inertia::optional()` prop on every Inertia request: it is
 * **absent from an ordinary navigation** and resolved only on a partial reload that names it. The
 * one exception is the full document load (slow-loading slice 6): the first paint carries it, so
 * the shell does not have to spend a second request — a partial reload that re-runs the whole
 * current controller — asking for it the moment the page mounts. That is not a detail, it is what
 * made a global banner affordable — resolving it costs 5 statements for an Admin and 12 for an
 * employee (`ConversationService::inboxFor()` asks `ProjectPolicy::view` per project channel),
 * and a prop resolved on every response is a prop every response pays for, on a catalogue page
 * that otherwise runs 3. `tests/Feature/Performance` holds the ceilings that say so.
 *
 * The consequence is that `page.props.shell` is `undefined` on every navigation after the first. So the
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
 *
 * ## The conversation on screen is not unread (messaging polish)
 *
 * The server sends the same counts per conversation (`unreadByConversation`). `MessageThread`
 * says which conversation is ON SCREEN — the tab visible and the window focused — through
 * `setViewingConversation()`, and every count drawn from this module leaves that one out: the
 * top bar's Messages icon, the sidebar pill, the rail pill (`isViewingConversation()`) and the
 * Messages page description. A reply landing in the thread somebody is reading never lights
 * anything up; the same thread in a background tab counts again.
 *
 * The thread also reports each read of its own (`adoptConversationUnread()`), so the moment it
 * has marked itself read the number here agrees, rather than a poll later.
 *
 * The chime (`lib/sound.ts`, reliability slice 5) is fed the SAME shown count, so a reply in the
 * thread you are reading does not chime, and a change of which conversation is on screen moves
 * the chime's baseline silently (`rebaseUnread`) — it is not an arrival. The bell no longer
 * counts message notifications (`Notification::scopeForBell()`), so this count is the only one
 * a DM raises, and one DM chimes once.
 */

/* ------------------------------------------------------------------ the payload */

/** `HandleInertiaRequests::sharedShell()`. */
export interface ShellLive {
    /** Unread messages across this reader's whole inbox, summed on the server. */
    unreadMessages: number;
    /** The same, per conversation id — only the ones above zero. */
    unreadByConversation: Record<string, number>;
    /** The newest announcement they may see, or `null` — including when there is none at all. */
    announcement: AnnouncementBanner | null;
    /** The announcements channel's conversation id, for the subscription. `null` with no banner. */
    announcementChannelId: number | null;
}

/* ------------------------------------------------------------------ module state */

/** The server's total, and its per-conversation breakdown. */
const unreadMessages = ref(0);
const unreadByConversation = ref<Record<string, number>>({});
/** The conversation on screen right now (`MessageThread`), or `null`. */
const viewing = ref<number | null>(null);
const announcement = ref<AnnouncementBanner | null>(null);
const channelId = ref<number | null>(null);

/** Has a response ever carried `shell`? Until it has, the badge shows nothing rather than zero. */
const loaded = ref(false);

/** What every count on screen shows: the total, less the conversation that is on screen. */
const shownUnread = computed(() => {
    const own = viewing.value === null ? 0 : (unreadByConversation.value[String(viewing.value)] ?? 0);

    return Math.max(0, unreadMessages.value - own);
});

/** Take whatever a response carried. Called from the watcher below and from nowhere else. */
function adopt(payload: ShellLive): void {
    unreadMessages.value = payload.unreadMessages;
    // An object on the wire; `?? {}` only for a payload from before the key existed.
    unreadByConversation.value = { ...(payload.unreadByConversation ?? {}) };
    announcement.value = payload.announcement;
    channelId.value = payload.announcementChannelId;
    loaded.value = true;

    // Reliability slice 5: a rise since the last successful read may chime (`lib/sound.ts`).
    noteUnread('messages', shownUnread.value);
}

/**
 * Messaging polish: which conversation is on screen (tab visible AND window focused), or `null`.
 * Called by `MessageThread` on focus, blur, visibility and a change of thread.
 */
export function setViewingConversation(id: number | null): void {
    if (viewing.value === id) {
        return;
    }

    viewing.value = id;

    // Looking away from a thread with something unread in it makes the count go UP without
    // anything having arrived. Move the chime's baseline instead of letting the next read chime.
    if (loaded.value) {
        rebaseUnread('messages', shownUnread.value);
    }
}

/** Is this conversation on screen right now? Reactive: read it in a template or a computed. */
export function isViewingConversation(id: number | null | undefined): boolean {
    return id != null && viewing.value === id;
}

/**
 * The thread's own read of itself: how many are unread in it now, from the payload it just got
 * (after any mark) or after a `through` mark. Keeps the total and the per-conversation count in
 * step, so leaving a thread that was just read does not bring back a count a poll behind.
 */
export function adoptConversationUnread(id: number, count: number): void {
    if (!loaded.value) {
        return;
    }

    const key = String(id);
    const before = unreadByConversation.value[key] ?? 0;
    const after = Math.max(0, count);

    if (before === after) {
        return;
    }

    const next = { ...unreadByConversation.value };

    if (after > 0) {
        next[key] = after;
    } else {
        delete next[key];
    }

    unreadByConversation.value = next;
    unreadMessages.value = Math.max(0, unreadMessages.value - before + after);
    noteUnread('messages', shownUnread.value);
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
    unreadByConversation.value = Object.fromEntries(
        rows.filter((row) => row.unread_count > 0).map((row) => [String(row.id), row.unread_count]),
    );
    noteUnread('messages', shownUnread.value);

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
    /** What the shell shows: the total less the conversation on screen. */
    unreadMessages: Readonly<Ref<number>>;
    announcement: Readonly<Ref<AnnouncementBanner | null>>;
    loaded: Readonly<Ref<boolean>>;
} {
    const page = usePage();

    bindChimeUser(page.props.auth.user?.id ?? null);

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

    // The opening read, only when the first paint did not carry the prop. The server resolves
    // `shell` into a full document load (slice 6), and the immediate watcher above has already
    // adopted it by this line, so on an ordinary page load `loaded` is true and nothing is sent:
    // that saved request was a partial reload re-running the whole controller (64 statements on
    // the Admin dashboard). The fallback stays for a document that went out without it — one
    // served before the enrolment gate was passed, say. Once per full page load either way:
    // `loaded` is module state, so a navigation that remounts the layout does not ask again.
    if (!loaded.value) {
        refresh();
    }

    useLiveRefresh(null, refresh, { intervalMs: SHELL_POLL_MS });
    useLiveRefresh(() => conversationChannel(channelId.value), refresh, {
        poll: false,
        safetyMs: null,
    });

    return {
        unreadMessages: shownUnread,
        announcement: readonly(announcement),
        loaded: readonly(loaded),
    };
}

/**
 * The Messages badge, for the sidebar row and the top bar's Messages icon. `null` means "say
 * nothing" — before the first read has answered, and when everything (less the conversation on
 * screen) is read.
 *
 * Capped at `9+` at the same width as the notification bell's badge and for the same reason:
 * anything wider stretches the pill past the icon. The cap is in the DISPLAY only — the sentence
 * a screen reader gets carries the exact figure, so nothing about this count is rounded anywhere
 * that matters.
 */
export const messagesBadge = computed<{ text: string; label: string; count: number } | null>(() => {
    if (!loaded.value || shownUnread.value < 1) {
        return null;
    }

    const count = shownUnread.value;

    return {
        count,
        text: count > 9 ? '9+' : String(count),
        label: count === 1 ? 'Messages, 1 unread' : `Messages, ${count} unread`,
    };
});
