import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

/**
 * Brief 010: who is online, and when somebody was last seen (decision 12-79).
 *
 * ## Two sources, one answer
 *
 * - **A socket build** joins the Reverb presence channel `online` and keeps the ids of everybody
 *   in it in one module-level `Set`. While that membership is live, "online" is "in the set".
 * - **A polling build** (or a socket that is down, or a join the server refused) has no
 *   membership, so "online" falls back to the payloads: somebody whose `last_seen_at` is under
 *   two minutes old. The shell's heartbeat (`Realtime/shell.ts`) moves that time every minute
 *   while a tab of theirs is visible, so two minutes is one missed beat of slack.
 *
 * The membership is reference-counted and lives as long as somebody is drawing a dot
 * (`usePresence()` — the Messages page). Nothing here decides who may see a last-seen time: the
 * payloads that carry one were built by the server for this reader.
 *
 * `echo.ts` is imported lazily, inside `start()`, so the pure helpers below (`lastSeenText`,
 * `recentlySeen`) load under Node's test runner without Vite's `import.meta.env`.
 */

/** "Online" on a polling build: seen within this long. */
export const ONLINE_WINDOW_MS = 2 * 60 * 1000;

/** The ids in the `online` presence channel, while the membership is live. */
const onlineIds = reactive(new Set<number>());

/** Is the membership above live right now (joined, and the socket connected)? */
const membershipLive = ref(false);

/** A clock for the polling fallback and for "5 min ago", ticking while anybody is watching. */
const now = ref(Date.now());

let users = 0;
let stopJoin: (() => void) | null = null;
let stopWatch: (() => void) | null = null;
let ticker: ReturnType<typeof setInterval> | null = null;

function start(): void {
    now.value = Date.now();
    ticker = setInterval(() => {
        now.value = Date.now();
    }, 30_000);

    void import('@/echo').then(({ joinPresence, realtimeConnection, realtimeMode }) => {
        if (users === 0 || stopJoin !== null || realtimeMode !== 'reverb') {
            return;
        }

        stopWatch = watch(realtimeConnection, (state) => {
            // A drop means the membership is no longer known; `here` sets it live again after
            // Echo rejoins on reconnect.
            if (state !== 'connected') {
                membershipLive.value = false;
            }
        });

        stopJoin = joinPresence('online', {
            here: (members) => {
                onlineIds.clear();

                for (const member of members) {
                    onlineIds.add(Number(member.id));
                }

                membershipLive.value = true;
            },
            joining: (member) => {
                onlineIds.add(Number(member.id));
            },
            leaving: (member) => {
                onlineIds.delete(Number(member.id));
            },
        });
    });
}

function stop(): void {
    stopJoin?.();
    stopJoin = null;
    stopWatch?.();
    stopWatch = null;

    if (ticker !== null) {
        clearInterval(ticker);
        ticker = null;
    }

    membershipLive.value = false;
    onlineIds.clear();
}

/** Hold the `online` membership while the calling component is mounted. */
export function usePresence(): void {
    onMounted(() => {
        users += 1;

        if (users === 1) {
            start();
        }
    });

    onBeforeUnmount(() => {
        users = Math.max(0, users - 1);

        if (users === 0) {
            stop();
        }
    });
}

/** Seen within `ONLINE_WINDOW_MS` of `nowMs`. Pure. */
export function recentlySeen(iso: string | null | undefined, nowMs: number): boolean {
    if (!iso) {
        return false;
    }

    const at = Date.parse(iso);

    return !Number.isNaN(at) && nowMs - at < ONLINE_WINDOW_MS;
}

/** Is this person online? The presence channel when it is live, else the last-seen fallback. */
export function isOnline(userId: number | null | undefined, lastSeenAt: string | null | undefined): boolean {
    if (userId === null || userId === undefined) {
        return false;
    }

    if (membershipLive.value) {
        return onlineIds.has(userId);
    }

    return recentlySeen(lastSeenAt, now.value);
}

/**
 * Spelled by hand rather than by `Intl`: ICU versions disagree about en-GB's short September
 * ("Sep" / "Sept"), and the header should say the same thing on every machine.
 */
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] as const;

function clockOf(date: Date): string {
    return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

function dayOf(date: Date, withYear: boolean): string {
    const day = `${date.getDate()} ${MONTHS[date.getMonth()]}`;

    return withYear ? `${day} ${date.getFullYear()}` : day;
}

function startOfDay(date: Date): number {
    const copy = new Date(date.getTime());

    copy.setHours(0, 0, 0, 0);

    return copy.getTime();
}

/**
 * "last seen just now / 5 min ago / today at 14:02 / yesterday at 09:10 / 28 Sep", in the
 * browser's zone. Pure: `now` is passed in, so the test can pin it.
 */
export function lastSeenText(iso: string | null | undefined, now: Date): string {
    if (!iso) {
        return 'last seen a long time ago';
    }

    const at = new Date(iso);

    if (Number.isNaN(at.getTime())) {
        return 'last seen a long time ago';
    }

    const minutes = Math.floor((now.getTime() - at.getTime()) / 60_000);

    if (minutes < 1) {
        return 'last seen just now';
    }

    if (minutes < 60) {
        return `last seen ${minutes} min ago`;
    }

    const days = Math.round((startOfDay(now) - startOfDay(at)) / 86_400_000);

    if (days <= 0) {
        return `last seen today at ${clockOf(at)}`;
    }

    if (days === 1) {
        return `last seen yesterday at ${clockOf(at)}`;
    }

    return `last seen ${dayOf(at, at.getFullYear() !== now.getFullYear())}`;
}

/** The DM header's subtitle: "online", or when they were last seen. */
export function presenceText(userId: number | null | undefined, lastSeenAt: string | null | undefined): string {
    return isOnline(userId, lastSeenAt) ? 'online' : lastSeenText(lastSeenAt, new Date(now.value));
}
