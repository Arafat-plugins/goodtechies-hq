/**
 * What the browser remembers about a timer session it could not deliver.
 *
 * ## `localStorage` is a cache of intent, never the truth
 *
 * The truth is `time_entries` on the server. This exists for one case: a laptop that was
 * closed, or a connection that dropped, while a session was going. It holds the `client_uuid`
 * the widget generated before the session started, the pings it could not send, and the stop
 * it never managed to post — and on reconnect it replays all of that to
 * `POST /employee/time/replay` and then takes whatever the server says back.
 *
 * The server wins every conflict. Nothing here is ever displayed as a total, nothing here is
 * ever added to one, and the buffer is cleared the moment the server has acknowledged it.
 *
 * It lives in `lib/` with `tableState.ts`, `sidebarState.ts` and `theme.ts` because that is
 * where every `localStorage` key in this application lives (DESIGN.md §5.9) — a key written
 * from a component is a key nobody can find, migrate or clear. Every call is wrapped: Safari's
 * private mode throws on write, a blocked-cookies setting throws on read, and neither is a
 * reason for the timer to stop working. A viewer who cannot store simply loses the buffer, and
 * the watchdog then ends their session at its last delivered heartbeat — which is exactly what
 * the watchdog is for.
 */

/** `hq.timer.session` — the one buffered session. There is only ever one; the index says so. */
const SESSION_KEY = 'hq.timer.session';

/** `hq.timer.bar` — `'0'` when the viewer has hidden the persistent bar. Personal, not shared. */
const BAR_KEY = 'hq.timer.bar';

/**
 * A session as the browser has it. Every field is the CLIENT's account, and the server treats
 * it as a claim to be checked against `heartbeats` rather than as a record.
 */
export interface BufferedSession {
    clientUuid: string;
    taskId: number;
    /** ISO, from the client's clock. The server clamps anything in the future. */
    startedAt: string;
    /** Pings that never reached the server. The only part of this the server treats as evidence. */
    heartbeats: string[];
    pausedSeconds: number;
    /** Set when the session ended while offline. A claim, capped at the last heartbeat. */
    stoppedAt: string | null;
}

/**
 * A fortnight of once-a-minute pings would be 20 000 entries, and the server only ever reads
 * the latest one. Keep the tail: the newest ping is the one that decides where the entry ends.
 */
const MAX_BUFFERED_HEARTBEATS = 2000;

function read(key: string): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

function write(key: string, value: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(key, value);
    } catch {
        /* Private mode: the buffer just does not survive. The watchdog covers the rest. */
    }
}

function remove(key: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.removeItem(key);
    } catch {
        /* Nothing to do, and nothing that matters. */
    }
}

/**
 * The buffered session, or null.
 *
 * Anything that does not parse into the shape above is discarded rather than repaired: a
 * half-written record from a tab that was killed mid-write is not evidence of anything, and
 * feeding it to the replay endpoint would be feeding it a guess.
 */
export function readSession(): BufferedSession | null {
    const raw = read(SESSION_KEY);

    if (raw === null) {
        return null;
    }

    try {
        const parsed: unknown = JSON.parse(raw);

        if (typeof parsed !== 'object' || parsed === null) {
            return null;
        }

        const session = parsed as Partial<BufferedSession>;

        if (
            typeof session.clientUuid !== 'string' ||
            typeof session.taskId !== 'number' ||
            typeof session.startedAt !== 'string'
        ) {
            return null;
        }

        return {
            clientUuid: session.clientUuid,
            taskId: session.taskId,
            startedAt: session.startedAt,
            heartbeats: Array.isArray(session.heartbeats)
                ? session.heartbeats.filter((beat): beat is string => typeof beat === 'string')
                : [],
            pausedSeconds: typeof session.pausedSeconds === 'number' ? session.pausedSeconds : 0,
            stoppedAt: typeof session.stoppedAt === 'string' ? session.stoppedAt : null,
        };
    } catch {
        return null;
    }
}

export function writeSession(session: BufferedSession | null): void {
    if (session === null) {
        remove(SESSION_KEY);

        return;
    }

    write(
        SESSION_KEY,
        JSON.stringify({
            ...session,
            heartbeats: session.heartbeats.slice(-MAX_BUFFERED_HEARTBEATS),
        }),
    );
}

/** Add an undelivered ping to the buffer, if there is a session to add it to. */
export function bufferHeartbeat(at: string): void {
    const session = readSession();

    if (session === null) {
        return;
    }

    writeSession({ ...session, heartbeats: [...session.heartbeats, at] });
}

export function readBarVisible(): boolean {
    return read(BAR_KEY) !== '0';
}

export function writeBarVisible(visible: boolean): void {
    write(BAR_KEY, visible ? '1' : '0');
}
