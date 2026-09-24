import { router, usePage } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp, Minus } from '@lucide/vue';
import type { Component, Ref } from 'vue';
import { computed, onScopeDispose, ref, watch } from 'vue';
import { listenPrivate, realtimeConnection, realtimeMode, realtimeReconnects } from '@/echo';

/**
 * The notification payloads, the endpoints that serve them, and the one poll behind the bell.
 *
 * Everything here is shared by the two mounts of this feature: the bell in the top bar and the
 * Notification Center at `/notifications`. Both draw the same row, so both read the same types,
 * and neither composes a sentence of its own — see `summary` below.
 */

/* ------------------------------------------------------------------ the payload */

/** `App\Support\NotificationTab`. `all` is the absence of a filter, not a tab of its own. */
export type NotificationTabKey =
    | 'all'
    | 'tasks'
    | 'messages'
    | 'meetings'
    | 'leave'
    | 'payroll'
    | 'system';

/** `App\Support\NotificationPriority`. */
export type NotificationPriorityKey = 'high' | 'normal' | 'low';

export interface NotificationActor {
    id: number;
    name: string;
}

/**
 * One row, exactly as `NotificationResource` sends it.
 *
 * There is deliberately no `payload` and no `group_key`: the storage format never leaves the
 * server, so nothing here can come to depend on a shape nobody promised it.
 */
export interface NotificationRow {
    id: number;
    type: string | null;
    tab: NotificationTabKey | null;
    priority: NotificationPriorityKey | null;
    /**
     * The line to print. **Written by the server**, in its singular or its grouped form — "New
     * comment in …" or "12 new comments in …". The grouped wording is the visible half of the
     * dedup rule, composed once by `NotificationType::summary()` where the count lives. A screen
     * that rebuilt it from `count` and a template would be a second place for that rule to be
     * stated, and the two would drift the first time a type was added.
     */
    summary: string;
    title: string;
    actor: NotificationActor | null;
    /** How many events this row stands for. Already spoken by `summary`; never printed twice. */
    count: number;
    is_read: boolean;
    read_at: string | null;
    /** When the group started, and when it last grew. They differ only for a grouped row. */
    created_at: string | null;
    updated_at: string | null;
    /**
     * The object's URL **on this reader's own surface**, resolved per request. A row whose link
     * is null has nowhere to go — the Accountant's would be, and so would one about an object a
     * surface has no screen for — and is not clickable.
     */
    link: string | null;
}

export interface NotificationTabSummary {
    key: NotificationTabKey;
    label: string;
    /**
     * Can this tab hold anything in the phase that is built? `NotificationTab::isBuilt()`. False
     * is not "empty" and not "hidden": it is what lets Meetings say which phase it is waiting
     * for, instead of showing a blank list that reads as a bug.
     */
    is_built: boolean;
    unread_count: number;
}

/** The bell's endpoint: the badge and the newest few, in one request. */
export interface NotificationRecent {
    unread_count: number;
    notifications: NotificationRow[];
}

/* ------------------------------------------------------------------ endpoints */

/** Every notification endpoint, spelled once (`routes/shared.php`). */
export const notificationRoutes = {
    center: '/notifications',
    recent: '/notifications/recent',
    read: (id: number): string => `/notifications/${id}/read`,
    readAll: '/notifications/read-all',
} as const;

/**
 * The Center, showing one tab.
 *
 * The tab is a query parameter because it is shareable state (DESIGN.md §5.10): a link to the
 * Payroll tab is a link somebody else can open. `all` drops the parameter rather than spelling
 * the default out.
 */
export function centerHref(tab: NotificationTabKey = 'all'): string {
    return tab === 'all' ? notificationRoutes.center : `${notificationRoutes.center}?tab=${tab}`;
}

/**
 * Which phase fills a tab that cannot hold anything yet.
 *
 * The server says *whether* a tab is built (`is_built`) and does not say when it will be —
 * `NotificationTab` has no phase number in it, and giving it one would be a second calendar
 * living in the domain. Phases are declared on the front end in this app, in
 * `navigation/{admin,employee,accountant}.ts`, and these four agree with the nav rows of the
 * same names (Leave 5, Messages 6, Meetings 7, Payroll 9) and with
 * `NotificationType`'s own note that those tabs get their types in Phase 6, 7, 5 and 9.
 */
export const TAB_PHASE: Partial<Record<NotificationTabKey, number>> = {
    // `messages` was here until Phase 6 filled it. A tab is "built" when the server says so
    // (`NotificationTab::isBuilt()`, derived from the catalogue); this list only supplies the
    // phase NUMBER for the ones that are not, so removing the entry is the whole change.
    meetings: 7,
    leave: 5,
    payroll: 9,
};

/* ------------------------------------------------------------------ priority */

/**
 * How a priority is drawn: an arrow whose direction is the rank, and the word beside it.
 *
 * Never a colour. This app has one accent and eight status hues and no ninth (DESIGN.md §5.3),
 * and a tint that meant "high" would be a ninth — but the reason it is a word *as well as* a
 * shape is §5.6, which this repo has already had to fix once for overdue-in-red: with the label
 * removed, a mark that differs only by hue is not readable by a screen reader and not reliably
 * readable under deuteranopia either. `TaskBoardCard` draws task priority the same way, so the
 * two vocabularies are one.
 */
const PRIORITY_MARK: Record<NotificationPriorityKey, { label: string; icon: Component; loud: boolean }> = {
    high: { label: 'High', icon: ChevronUp, loud: true },
    normal: { label: 'Normal', icon: Minus, loud: false },
    low: { label: 'Low', icon: ChevronDown, loud: false },
};

export function priorityMark(priority: NotificationPriorityKey | null) {
    return priority === null ? null : PRIORITY_MARK[priority];
}

/* ------------------------------------------------------------------ formatting */

const UNITS: [unit: Intl.RelativeTimeFormatUnit, seconds: number][] = [
    ['year', 31_536_000],
    ['month', 2_592_000],
    ['week', 604_800],
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
];

/** "5 minutes ago", "yesterday", "now" — in the browser's language. */
export function relativeTime(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return iso;
    }

    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

    for (const [unit, size] of UNITS) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.round(seconds / size), unit);
        }
    }

    return format.format(0, 'second');
}

const EXACT = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short' });

/** The `title` behind the relative time, so "2 hours ago" can be resolved to a clock. */
export function exactTime(iso: string | null): string | undefined {
    if (!iso) {
        return undefined;
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? undefined : EXACT.format(date);
}

/* ------------------------------------------------------------------ the poll, and the socket */

/**
 * The bell's two transports: a subscription when there is one, and the 15-second poll when
 * there is not.
 *
 * ## Phase 6 did not delete the poll, and deleting it would have been the bug
 *
 * Decision 2-39 built the poll "until Phase 6". Phase 6 is here, and the poll stays — because
 * it is now two things rather than one:
 *
 *   - **the whole bell on a polling build.** `VITE_REALTIME=polling` is a supported mode, not
 *     dead code: Part B says a 10–15 s poll is acceptable in the MVP if Reverb is troublesome,
 *     and a VPS may well run that way for a week. See `resources/js/echo.ts`.
 *   - **the fallback while the socket is down on a socket build.** A websocket drops — a
 *     laptop sleeps, a phone changes network, Nginx is reloaded during a deploy — and a bell
 *     that silently stopped updating would look exactly like a bell with nothing to say. So
 *     while `realtimeConnection` is anything but `connected`, the interval is running and the
 *     popover says which of the two is happening.
 *
 * When the socket is up, the poll is **off**, not merely redundant: `bellTransport` is the one
 * place that decides, and the badge is set straight from the broadcast payload, which is the
 * same array `/notifications/recent` returns (`NotificationService::feed()`, asserted identical
 * in tests/Feature/Realtime/PollingFallbackTest.php).
 *
 * **A reconnect re-reads.** A socket delivers changes, and a change that happened while it was
 * down was delivered to nobody — so coming back is a reason to ask, not a reason to relax.
 *
 * The rules decision 2-39 established are unchanged and still not tuning:
 *
 * - **One interval, ever.** The state is module-scoped and reference-counted (the same shape
 *   `lib/flashChannel.ts` uses for its claims), so two mounted bells — or a bell that is
 *   remounted by a layout change — cannot leave a second interval running behind them.
 * - **Nobody looking, no request.** A hidden tab is not a tab somebody is glancing at. The
 *   interval is cleared on `visibilitychange` and re-armed, with one immediate read, when the
 *   page comes back. A laptop lid closed on a Friday costs nothing until Monday.
 * - **No request after the last unmount.** The listener goes with the interval.
 * - **One request in flight.** A slow answer does not stack up behind the next tick.
 *
 * The count is the server's. Nothing here decrements it: a write re-reads (`refreshBell()`)
 * rather than guessing, because the guess is wrong the moment the same person has the app open
 * on their phone. That is also why the broadcast carries the whole feed rather than one new
 * row — see `App\Events\NotificationFeedChanged`.
 */
const POLL_MS = 15_000;

/**
 * How long the live region stays quiet between announcements, in milliseconds.
 *
 * A burst of arrivals is one piece of news to somebody using a screen reader, not five. The
 * badge and the popover carry the detail; this is the interruption budget, and it is deliberately
 * longer than the poll so that the quietest transport cannot be the noisiest reader experience.
 */
const ANNOUNCE_QUIET_MS = 30_000;

const unreadCount = ref(0);
const recent = ref<NotificationRow[]>([]);
/**
 * `denied` is the Accountant, and it is how the bell learns rather than how it is told.
 *
 * Whether a person has a mailbox at all is `NotificationPolicy`'s answer, and the backend
 * refuses to reach it by naming a role — every Phase 2 type requires `tasks.view`, and the
 * Accountant is refused by holding no such key. A `surface === 'accountant'` test in here would
 * be exactly the sentence the server would not write. So the bell asks once, is refused once,
 * and removes itself (DESIGN.md §5.12: the bell degrades to nothing, not to something greyed
 * out). The state is module-scoped, so it asks once per full page load and never again.
 */
export type BellStatus = 'idle' | 'loading' | 'ready' | 'failed' | 'denied';

const status = ref<BellStatus>('idle');

let timer: ReturnType<typeof setInterval> | null = null;
let listening = false;
let inFlight = false;
let watchers = 0;
/** Undoes the channel subscription. Set while at least one bell is mounted on a socket build. */
let unsubscribe: (() => void) | null = null;

/**
 * What the bell is currently doing, which is a different question from what it was BUILT to do.
 *
 * `live` only when the socket is actually connected. `reconnecting` is a socket build whose
 * socket is down — the poll is covering for it and the reader is told so, because the
 * alternative is a bell that has quietly stopped being right. `polling` is a polling build,
 * working exactly as intended and with nothing to apologise for.
 */
export type BellTransport = 'live' | 'reconnecting' | 'polling';

export const bellTransport = computed<BellTransport>(() => {
    if (realtimeMode !== 'reverb') {
        return 'polling';
    }

    return realtimeConnection.value === 'connected' ? 'live' : 'reconnecting';
});

/**
 * The sentence a screen reader hears, and the last count it heard.
 *
 * Set at most once every `ANNOUNCE_QUIET_MS`, and only when the number went UP: a count
 * falling because the same person marked things read on their phone is not news, and a burst
 * of five comments inside a minute is one piece of news rather than five. The region it is
 * rendered into is `aria-live="polite"`, so even this waits for a pause in whatever the reader
 * is doing — a notification must never interrupt someone mid-sentence, and it must never move
 * anything under their cursor.
 */
const announcement = ref('');

let lastAnnouncedCount = 0;
let lastAnnouncedAt = 0;

function announce(count: number): void {
    const now = Date.now();

    if (count <= lastAnnouncedCount) {
        // Remember the fall, so a later rise back to the same number is still news.
        lastAnnouncedCount = count;

        return;
    }

    if (now - lastAnnouncedAt < ANNOUNCE_QUIET_MS) {
        return;
    }

    lastAnnouncedCount = count;
    lastAnnouncedAt = now;
    announcement.value = count === 1 ? '1 unread notification' : `${count} unread notifications`;
}

function pageVisible(): boolean {
    return typeof document === 'undefined' || document.visibilityState === 'visible';
}

/**
 * Take a feed payload, whichever transport carried it.
 *
 * The two transports deliver the SAME array — `NotificationService::feed()` builds it for both
 * — so there is one place that reads it and no branch on where it came from. A second reader
 * for the socket's half would have been the drift the server was shaped to prevent.
 */
function apply(payload: NotificationRecent): void {
    unreadCount.value = payload.unread_count;
    recent.value = payload.notifications;
    status.value = 'ready';

    announce(payload.unread_count);
}

async function read(): Promise<void> {
    // One in flight. A tick that arrives while the last answer is still coming is a tick the
    // server does not need to hear about.
    if (inFlight || status.value === 'denied') {
        return;
    }

    inFlight = true;

    if (status.value === 'idle') {
        status.value = 'loading';
    }

    try {
        const response = await fetch(notificationRoutes.recent, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (response.status === 403) {
            status.value = 'denied';
            stopPolling();
            unsubscribe?.();
            unsubscribe = null;

            return;
        }

        if (!response.ok) {
            // 401 or 419 means the session went; a poll is not the place to say so, and the
            // next thing this person clicks will land them on the login page by itself.
            status.value = 'failed';

            return;
        }

        apply((await response.json()) as NotificationRecent);
    } catch {
        // Offline, or a request cancelled by a navigation. The badge keeps the last number it
        // was told rather than dropping to zero, which would be a lie in the quietest possible
        // direction.
        status.value = status.value === 'loading' ? 'failed' : status.value;
    } finally {
        inFlight = false;
    }
}

function startPolling(): void {
    // Not while the socket is up. This is the one place that decides, so a bell cannot end up
    // both subscribed and polling — which would not be wrong, only wasteful and invisible.
    if (timer !== null || !pageVisible() || status.value === 'denied' || bellTransport.value === 'live') {
        return;
    }

    timer = setInterval(() => void read(), POLL_MS);
}

function stopPolling(): void {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}

function onVisibilityChange(): void {
    if (!pageVisible()) {
        stopPolling();

        return;
    }

    // Back in front: answer with what is true now, not in fifteen seconds.
    void read();
    startPolling();
}

/**
 * Mount the bell. Returns its state, read-only — the count is the server's.
 *
 * The first caller starts the transport; the last one to unmount stops it and takes the
 * visibility listener and the channel subscription with it. The state is module-scoped and
 * reference-counted, so two mounted bells — or a bell remounted by a layout change — cannot
 * leave a second interval or a second subscription running behind them.
 *
 * **The first read is always an HTTP read, on both transports.** A socket delivers changes,
 * and the bell arrives needing the current state; there is nothing to subscribe one's way to.
 */
export function useNotificationBell(): {
    unreadCount: Readonly<Ref<number>>;
    recent: Readonly<Ref<NotificationRow[]>>;
    status: Readonly<Ref<BellStatus>>;
    transport: Readonly<Ref<BellTransport>>;
    announcement: Readonly<Ref<string>>;
} {
    watchers += 1;

    // The channel is this person's own: `private-notifications.{user}`, whose auth callback
    // asks NotificationPolicy::viewAny and "is this you" (app/Broadcasting/NotificationChannel).
    // The id comes from the shared Inertia props, which is where every other screen gets it;
    // nothing here derives a permission from it, and a subscription somebody is not entitled to
    // is refused with 403 at `/broadcasting/auth` rather than being prevented here.
    const userId = usePage().props.auth.user?.id ?? null;

    if (watchers === 1) {
        if (typeof document !== 'undefined' && !listening) {
            document.addEventListener('visibilitychange', onVisibilityChange);
            listening = true;
        }

        if (userId !== null && realtimeMode === 'reverb') {
            unsubscribe = listenPrivate(`notifications.${userId}`, {
                'feed.changed': (payload: never) => apply(payload as NotificationRecent),
            });
        }

        if (pageVisible()) {
            void read();
            startPolling();
        }
    }

    // The socket coming up puts the poll away; the socket going down brings it back, in the
    // same tick the popover starts saying so. Neither is a reload and neither moves anything
    // on the screen.
    const stopTransportWatch = watch(bellTransport, (transport) => {
        if (transport === 'live') {
            stopPolling();

            return;
        }

        startPolling();
    });

    // A reconnect means time passed with nobody listening. Ask.
    const stopReconnectWatch = watch(realtimeReconnects, () => void read());

    onScopeDispose(() => {
        stopTransportWatch();
        stopReconnectWatch();

        watchers -= 1;

        if (watchers > 0) {
            return;
        }

        stopPolling();

        unsubscribe?.();
        unsubscribe = null;

        if (listening && typeof document !== 'undefined') {
            document.removeEventListener('visibilitychange', onVisibilityChange);
            listening = false;
        }
    });

    return { unreadCount, recent, status, transport: bellTransport, announcement };
}

/** Re-read the bell now. Every write calls this; nothing adjusts the count by hand. */
export function refreshBell(): void {
    void read();
}

/* ------------------------------------------------------------------ the writes */

/**
 * Mark one read, or all of them.
 *
 * **Neither is announced.** Both endpoints answer `back()` carrying no flash at all — the
 * controller says so out loud ("Silent about how many, because the bell's own number is the
 * answer") — so there is nothing for `FlashMessage` to draw and nothing for the toaster to
 * speak. Adding a `toast('Marked read')` here would be inventing a message the server did not
 * send, on the one screen in the application whose entire subject is being told things: the
 * notification about the notification. The feedback is the thing the reader was already looking
 * at — the badge and the row — going quiet, and that is immediate because `back()` re-renders
 * the Center from the server and `refreshBell()` re-reads the count.
 *
 * `preserveScroll` keeps a long list where the reader left it; `preserveState` keeps the
 * popover open, so marking one row read does not close the panel the other nine are in.
 */
export function markRead(id: number, after?: () => void): void {
    router.post(
        notificationRoutes.read(id),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                refreshBell();
                after?.();
            },
        },
    );
}

export function markAllRead(): void {
    router.post(
        notificationRoutes.readAll,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => refreshBell(),
        },
    );
}

/**
 * Open what a row is about, and count it as read on the way.
 *
 * A modified or middle click is left to the browser: it opens the object in a tab the reader is
 * not looking at, so they have not read anything yet and the row stays unread. An ordinary
 * click is the reader going there, which is the event `is_read` records — so the write is sent
 * first and the visit follows it. `link` is the server's deep link, resolved against this
 * reader's own surface; nothing here builds a URL from a type and an id.
 */
export function openNotification(row: NotificationRow, event: MouseEvent): void {
    if (row.link === null) {
        return;
    }

    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
        return;
    }

    event.preventDefault();

    const link = row.link;

    if (row.is_read) {
        router.visit(link);

        return;
    }

    markRead(row.id, () => router.visit(link));
}
