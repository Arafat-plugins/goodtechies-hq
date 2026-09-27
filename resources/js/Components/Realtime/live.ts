import { Timer, WifiOff, Zap } from '@lucide/vue';
import type { Component, ComputedRef, MaybeRefOrGetter } from 'vue';
import { computed, onScopeDispose, ref, toValue, watch } from 'vue';
import { listenPrivate, realtimeConnection, realtimeMode, realtimeReconnects } from '@/echo';

/**
 * How ANY screen keeps itself current — on a socket build AND on a polling one.
 *
 * This is the client half of POLISH-BACKLOG §A. It was written for messaging in September and
 * lived in `Components/Messages/` while messaging was the only thing using it; Phase 12's
 * polish pass wired the other seven screens in §A.3 through it, which is what moved it here.
 * Nothing in it was ever messaging-specific except the two channel-name helpers and the two
 * intervals at the bottom, which stay because a channel name is exactly the kind of literal
 * that must be spelled in one place.
 *
 * It exists because two separate things were true at once: nothing in `resources/js` ever
 * subscribed to `conversation.{id}` (so `MessageThread.refresh()`, written precisely so a
 * transport could call it, was called by nobody), and the machine the client actually runs this
 * on is a **polling** build — `VITE_REALTIME` is unset there and the launcher starts no Reverb.
 * A screen that only works on a socket is a screen that does not work on their desk, so the
 * poll is not a fallback here: it is the path that runs.
 *
 * It is one composable rather than one implementation per screen, and it follows
 * `Components/Notifications/notifications.ts` — the only thing in this application that was
 * already wired end to end — rather than inventing a second pattern beside it.
 *
 * ## The frame is a doorbell, not a payload
 *
 * Every event this file listens for carries ids and nothing else: `conversation.message` is
 * `{conversation_id, message_id}` and `status.changed` is `{task_id}`. Nothing here reads either
 * and nothing renders out of a frame — a ping means "ask again", the screen re-reads its
 * endpoint or partially reloads its props, and what it paints is therefore what the policy built
 * for this reader. That is decision 6-8, and it is why `MessageThread.refresh()` re-reads
 * instead of accepting a message it was handed.
 *
 * Because no handler reads a payload, the set of event names is a CONSTANT here rather than a
 * per-caller option: a screen subscribing to a channel wants to know that something on it
 * happened, and an extra name that never fires on that channel costs nothing. The alternative —
 * one event list per call site — is a list somebody forgets to extend the day a channel gains
 * an event, and the symptom is a screen that silently stops updating.
 *
 * ## Tab visibility is a correctness rule here, not an optimisation
 *
 * `GET /messages/{conversation}` is also what **marks the thread read**. A thread that kept
 * refreshing behind a hidden tab would quietly mark messages read that nobody has looked at,
 * and the reader's unread count would drop to zero for messages they never saw. So every
 * refresh this file can cause — the interval's, the socket's, and the one a reconnect asks for
 * — is gated on `document.visibilityState === 'visible'`, and coming back to the tab is worth
 * exactly one immediate read.
 *
 * ## A gesture is never clobbered
 *
 * `canRefresh` is asked immediately before every automatic refresh, and a refresh it refuses is
 * **remembered rather than dropped** — `resume()` delivers it. The board is why: a refresh that
 * landed mid-drag would replace the columns under the card somebody is holding, and a refresh
 * merely skipped would lose a colleague's move until the next tick, or for ever on a socket
 * build where the tick is the ping. That is the Gantt's `draft` rule (decision 10-12) applied to
 * a transport: the gesture owns the screen until it ends, and then the truth lands.
 */

/* ------------------------------------------------------------------ the contract */

/**
 * Every doorbell this application rings, as the server spells it in `broadcastAs()`. One list,
 * in one place, so the two halves of this slice cannot drift by a character.
 *
 * `conversation.message` is `App\Events\ConversationActivity`; `status.changed` is the one name
 * all three status events share (`App\Events\Concerns\BroadcastsTaskStatus`). `feed.changed`
 * is deliberately absent: the bell owns it, it is the one frame in the application that carries
 * a payload on purpose (decision 6-8), and it has its own reader in `notifications.ts`.
 */
export const LIVE_EVENTS = ['conversation.message', 'status.changed'] as const;

/**
 * A frame, whichever door it came through. Every field is optional because nothing reads any of
 * them — the type exists so a future reader can see there is nothing in here worth painting.
 */
export interface LivePing {
    conversation_id?: number;
    message_id?: number;
    task_id?: number;
}

/** Kept for the Messages screens that already import it by this name. */
export type ConversationPing = LivePing;

/** The private channel a conversation broadcasts on, exactly as `routes/channels.php` spells it. */
export function conversationChannel(conversationId: number | null | undefined): string | null {
    return typeof conversationId === 'number' && conversationId > 0
        ? `conversation.${conversationId}`
        : null;
}

/** The private channel one task broadcasts on — `app/Broadcasting/TaskChannel.php`. */
export function taskChannel(taskId: number | null | undefined): string | null {
    return typeof taskId === 'number' && taskId > 0 ? `task.${taskId}` : null;
}

/** The open thread's interval when it is polling. */
export const THREAD_POLL_MS = 10_000;

/** The rail's interval. It is poll-only: see `useLiveRefresh` on why there is no inbox channel. */
export const RAIL_POLL_MS = 15_000;

/**
 * The intervals of the screens Phase 12's polish pass wired, all poll-only unless the comment
 * beside them says otherwise. Each is the answer to one question — *how stale may this be before
 * somebody is working from the wrong thing* — and not a tuning knob:
 *
 * - **the shell** (the Messages nav indicator and the announcement banner) — 30 s. It is the
 *   chrome of every screen in the application, so it is the one interval that is running
 *   whatever somebody is looking at, and a partial reload of it costs the current page's
 *   controller (see `reload.ts`). An announcement additionally arrives on a socket build the
 *   moment it is posted, because the announcements channel already exists.
 * - **the task board** — 20 s. Two people dragging one board is the case the client named, and
 *   twenty seconds is the longest a card should sit in the wrong column. Poll-only on both
 *   builds: see `TaskBoard.vue` on why there is no board channel.
 * - **task detail** — 15 s on a polling build; instant on a socket build, where `task.{id}` is
 *   subscribed.
 * - **attendance and time** — 30 s. A clock-in from another device is the case, and nobody
 *   clocks in twice in thirty seconds.
 * - **the dashboards** — 60 s. Counters and "needs your attention", which nobody reads as a
 *   clock, against the heaviest controller in the application (67 statements on the Admin's).
 */
export const SHELL_POLL_MS = 30_000;
export const BOARD_POLL_MS = 20_000;
export const TASK_DETAIL_POLL_MS = 15_000;
export const ATTENDANCE_POLL_MS = 30_000;
export const DASHBOARD_POLL_MS = 60_000;

/**
 * The slow read that runs even while the socket says it is connected.
 *
 * A connected socket is not the same as a working delivery chain. A broadcast here is a QUEUED
 * job — `ConversationActivity` is `ShouldBroadcast`, deliberately, so a stopped Reverb costs a
 * retried job rather than a 500 on a message that was already written — so the frame only
 * reaches the browser if a queue worker is running to send it. On the VPS supervisor keeps one
 * up. On a developer's machine, and on the client's, **nothing does**: the launcher starts one,
 * and somebody who closes that window has a socket that connects, subscribes, reports itself
 * live and then never says anything again.
 *
 * Without this the failure is silent and total — worse than the polling build it replaced,
 * because at least that one was honest. With it the same failure is a forty-five second thread,
 * which is slow and correct. It costs four requests a minute on a screen somebody is looking at
 * and nothing at all behind a hidden tab.
 */
export const LIVE_SAFETY_MS = 45_000;

/* ------------------------------------------------------------------ one subscription per channel */

interface ChannelEntry {
    handlers: Set<(ping: LivePing) => void>;
    stop: () => void;
}

const channels = new Map<string, ChannelEntry>();

/**
 * Join a conversation channel, reference-counted.
 *
 * Two things on one screen legitimately listen to the same conversation — the open thread, and
 * the rail that wants to re-sort its rows when that thread moves — and `echo.ts` unsubscribes
 * with `connection.leave(channel)`, which is channel-wide. Two raw `listenPrivate` calls for
 * one channel would therefore mean the first teardown silently deafens the second listener.
 * So the subscription is shared and only the last one out turns it off, which is the same
 * reference-counting shape the bell uses for its module-scoped poll.
 */
function joinChannel(channel: string, handler: (ping: LivePing) => void): () => void {
    let entry = channels.get(channel);

    if (entry === undefined) {
        const handlers = new Set<(ping: LivePing) => void>();

        const deliver = (payload: never): void => {
            // A copy, so a handler that disposes itself mid-delivery cannot break the
            // iteration for the ones after it.
            for (const fn of [...handlers]) {
                fn(payload as unknown as LivePing);
            }
        };

        entry = {
            handlers,
            stop: listenPrivate(
                channel,
                Object.fromEntries(LIVE_EVENTS.map((event) => [event, deliver])),
            ),
        };

        channels.set(channel, entry);
    }

    entry.handlers.add(handler);

    let left = false;

    return () => {
        if (left) {
            return;
        }

        left = true;

        const current = channels.get(channel);

        if (current === undefined) {
            return;
        }

        current.handlers.delete(handler);

        if (current.handlers.size === 0) {
            current.stop();
            channels.delete(channel);
        }
    };
}

/* ------------------------------------------------------------------ what a screen is doing */

/**
 * What a screen is currently doing, which is a different question from what it was BUILT to do.
 *
 * `live` only when the socket is genuinely connected. `reconnecting` is a socket build whose
 * socket is down — the interval is covering for it and the reader is told so. `polling` is a
 * polling build, working exactly as intended and with nothing to apologise for. The bell says
 * the same three things with the same three words (`BellTransport`), on purpose.
 */
export type LiveTransport = 'live' | 'reconnecting' | 'polling';

/**
 * Read the transport without subscribing to anything. A screen that only wants to SAY what is
 * happening — the conversation header's little indicator — uses this and starts no timers.
 */
export function useLiveStatus(channel: MaybeRefOrGetter<string | null>): ComputedRef<LiveTransport> {
    return computed(() => {
        if (realtimeMode !== 'reverb' || toValue(channel) === null) {
            return 'polling';
        }

        return realtimeConnection.value === 'connected' ? 'live' : 'reconnecting';
    });
}

/** One word for the indicator. Never "Live" on a polling build. */
export function liveTransportWord(transport: LiveTransport, intervalMs: number): string {
    if (transport === 'live') {
        return 'Live';
    }

    return transport === 'reconnecting'
        ? 'Reconnecting'
        : `Every ${Math.round(intervalMs / 1000)}s`;
}

/**
 * The whole sentence, which is what a screen reader gets and what the tooltip shows.
 *
 * `subject` is what this screen is waiting for, in the plural, so the one indicator can sit on a
 * chat thread and on the Board without either of them inventing its own wording. It defaults to
 * the messaging phrasing because that is what every existing caller means.
 */
export function liveTransportLabel(
    transport: LiveTransport,
    intervalMs: number,
    subject = 'new messages',
): string {
    const seconds = Math.round(intervalMs / 1000);

    if (transport === 'live') {
        return `Live: ${subject} appear as they happen.`;
    }

    return transport === 'reconnecting'
        ? `Reconnecting: checking for ${subject} every ${seconds} seconds until the connection is back.`
        : `Checking for ${subject} every ${seconds} seconds.`;
}

/**
 * Is a modal, a menu or a popover open anywhere on the page right now?
 *
 * The generic half of rule 5 — *nothing re-renders under the user's hands*. A screen that holds
 * its own gesture state guards on THAT (the Board knows what is being dragged); this is for the
 * overlays every screen gets from reka-ui and nobody tracks: the work-summary dialog a drag
 * opened, a card's ⋯ menu, a filter popover, the tag picker. A partial reload cannot steal focus
 * — `router.reload()` preserves component state and never remounts the tree — but it CAN change
 * the items under an open menu, or the row a dialog was opened about, and both of those read as
 * the screen moving while somebody is deciding.
 *
 * It is read off reka's own `data-state`, which is on every one of its content elements, rather
 * than off a flag each screen would have to remember to set. A refusal is remembered by
 * `useLiveRefresh` and delivered by the next tick after the overlay closes, so nothing is lost —
 * see `canRefresh`.
 */
export function overlayOpen(): boolean {
    if (typeof document === 'undefined') {
        return false;
    }

    return (
        document.querySelector(
            '[data-state="open"][role="dialog"], [data-state="open"][role="alertdialog"], [data-state="open"][role="menu"], [data-state="open"][role="listbox"]',
        ) !== null
    );
}

/**
 * The mark beside the words. It is never the only carrier of the fact — the word is always
 * there for a screen reader and from `sm` it is on screen too (DESIGN.md §5.6).
 */
export function liveTransportIcon(transport: LiveTransport): Component {
    if (transport === 'live') {
        return Zap;
    }

    return transport === 'reconnecting' ? WifiOff : Timer;
}

/* ------------------------------------------------------------------ the composable */

export interface LiveRefreshOptions {
    /** How often to poll when there is no socket to lean on. */
    intervalMs?: number;
    /**
     * The slow read that runs while the socket is connected, in case nothing is delivering on
     * it — see `LIVE_SAFETY_MS`. `null` turns it off, which is only right where something else
     * already covers the same ground.
     */
    safetyMs?: number | null;
    /**
     * `false` subscribes and never starts a timer. It is for a second listener on a screen
     * whose list is already covered by another poll — the rail, which polls on its own account
     * and only wants the open thread's ping as well. Without it, one screen would run two
     * timers for one list.
     */
    poll?: boolean;
    /**
     * Asked immediately before every automatic refresh. `false` means "not now": the thread
     * uses it to stay out of the way of a post that is in flight (the post re-reads by itself
     * when it lands) and of a conversation the server has already said is not available; the
     * board uses it to stay out of the way of a drag.
     *
     * A refusal is **remembered**, not dropped — `resume()` delivers the refresh that was
     * refused. A caller whose refusal is momentary (a gesture) calls `resume()` when it ends; a
     * caller whose refusal means "this screen re-reads by itself anyway" can ignore it, and the
     * pending flag is cleared by the next successful refresh either way.
     */
    canRefresh?: () => boolean;
}

export interface LiveRefreshHandle {
    /** What this screen is doing right now. Safe to render. */
    transport: ComputedRef<LiveTransport>;
    /**
     * "I have just re-read by myself." Restarts the interval from now, so a post — which
     * re-reads as part of landing — is not followed a second later by a poll asking the same
     * question. On a live transport there is no timer and this does nothing.
     */
    markFresh: () => void;
    /**
     * "The thing that was in the way has finished." Delivers the refresh `canRefresh` refused,
     * if there was one, and does nothing at all otherwise — so a caller may call it on every
     * gesture end without thinking about whether anything was missed.
     */
    resume: () => void;
    /** Is a refresh waiting for `resume()`? Rendered by the board as "a card moved" while held. */
    pending: ComputedRef<boolean>;
}

/**
 * Keep something current: subscribe when there is a socket, poll when there is not.
 *
 * `channel` may be a ref or a getter; `null` is a legitimate mode meaning **poll only**, and it
 * is what the conversation rail uses, because there is no per-user inbox channel to subscribe
 * to. A ping never carries content — `onPing` is expected to go and re-read the endpoint.
 *
 * The four states, exactly:
 *
 * - **polling build** (`VITE_REALTIME` is not `reverb`, or the channel is `null`) — no socket
 *   is opened at all, `echo.ts` never imports its 90 KB of client, and the interval runs the
 *   whole time the tab is visible.
 * - **socket connected** — subscribed; **no timer at all**. `onPing` fires on
 *   `conversation.message` and on nothing else.
 * - **socket dropped** — `realtimeConnection` leaves `connected`, the interval starts in the
 *   same tick, and the screen says "Reconnecting" rather than quietly going stale. When the
 *   socket returns, the interval stops and `realtimeReconnects` buys **one immediate** refresh,
 *   because whatever happened while it was down was delivered to nobody.
 * - **tab hidden** — no interval and no socket-driven refresh; returning to the tab is worth
 *   exactly one immediate read. This is the read-marking rule, not a saving.
 *
 * Everything is undone on scope dispose: the interval, the `visibilitychange` listener and the
 * subscription. A page navigated away from does not keep polling.
 */
export function useLiveRefresh(
    channel: MaybeRefOrGetter<string | null>,
    onPing: () => void,
    options: LiveRefreshOptions = {},
): LiveRefreshHandle {
    const intervalMs = options.intervalMs ?? THREAD_POLL_MS;
    const polls = options.poll ?? true;
    const canRefresh = options.canRefresh ?? (() => true);

    const transport = useLiveStatus(channel);

    const safetyMs = options.safetyMs === undefined ? LIVE_SAFETY_MS : options.safetyMs;

    /** A refresh that was asked for while `canRefresh` said no. */
    const deferred = ref(false);

    let timer: ReturnType<typeof setInterval> | null = null;
    let safety: ReturnType<typeof setInterval> | null = null;
    let unsubscribe: (() => void) | null = null;
    let listening = false;

    function pageVisible(): boolean {
        return typeof document === 'undefined' || document.visibilityState === 'visible';
    }

    /**
     * One door for every reason to refresh — the interval, the socket, a reconnect, the tab
     * coming back — so the visibility rule and the caller's own guard are stated once and
     * cannot be forgotten by whichever path grows next.
     *
     * A hidden tab is not remembered, because coming back to the tab already buys an immediate
     * read. A `canRefresh` refusal IS remembered: see `resume()`.
     */
    function ping(): void {
        if (!pageVisible()) {
            return;
        }

        if (!canRefresh()) {
            deferred.value = true;

            return;
        }

        deferred.value = false;
        onPing();
    }

    function stopPolling(): void {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    }

    function startPolling(): void {
        // Not while the socket is up: this is the one place that decides, so a screen cannot
        // end up both subscribed and polling — which would not be wrong, only wasteful and
        // invisible.
        if (!polls || timer !== null || !pageVisible() || transport.value === 'live') {
            return;
        }

        timer = setInterval(ping, intervalMs);
    }

    function stopSafety(): void {
        if (safety !== null) {
            clearInterval(safety);
            safety = null;
        }
    }

    /**
     * Only while the socket claims to be up, and only then — a polling screen is already
     * reading far more often than this, so a second timer under it would be noise.
     */
    function startSafety(): void {
        if (safetyMs === null || safety !== null || !pageVisible() || transport.value !== 'live') {
            return;
        }

        safety = setInterval(ping, safetyMs);
    }

    function onVisibilityChange(): void {
        if (!pageVisible()) {
            stopPolling();
            stopSafety();

            return;
        }

        // Back in front: answer with what is true now, not in ten seconds.
        ping();
        startPolling();
        startSafety();
    }

    // The channel can change under a mount that is not keyed by it. Re-subscribing is the whole
    // reaction; the poll is decided by `transport`, which is computed from the same getter.
    watch(
        () => toValue(channel),
        (name) => {
            unsubscribe?.();
            unsubscribe = null;

            if (name !== null && realtimeMode === 'reverb') {
                unsubscribe = joinChannel(name, ping);
            }
        },
        { immediate: true },
    );

    // The socket coming up puts the poll away; the socket going down brings it back, in the
    // same tick the indicator starts saying so.
    watch(transport, (now) => {
        if (now === 'live') {
            stopPolling();
            startSafety();

            return;
        }

        stopSafety();
        startPolling();
    });

    // A reconnect means time passed with nobody listening. Ask.
    watch(realtimeReconnects, () => ping());

    if (typeof document !== 'undefined') {
        document.addEventListener('visibilitychange', onVisibilityChange);
        listening = true;
    }

    // No opening read: every caller of this has just rendered from the server or is about to
    // fetch on mount, and a second request in the first tick is one the server does not need.
    startPolling();
    startSafety();

    onScopeDispose(() => {
        stopPolling();
        stopSafety();

        unsubscribe?.();
        unsubscribe = null;

        if (listening && typeof document !== 'undefined') {
            document.removeEventListener('visibilitychange', onVisibilityChange);
            listening = false;
        }
    });

    return {
        transport,
        pending: computed(() => deferred.value),
        resume: () => {
            if (!deferred.value) {
                return;
            }

            // `ping()` clears the flag itself when it goes through, and sets it again when the
            // caller is still not ready — so a gesture that ends into another gesture keeps the
            // refresh waiting rather than losing it.
            ping();
        },
        markFresh: () => {
            // Whichever clock is running, restart it from now: a read that has just happened
            // must not be followed a second later by a scheduled one.
            if (timer !== null) {
                stopPolling();
                startPolling();
            }

            if (safety !== null) {
                stopSafety();
                startSafety();
            }
        },
    };
}
