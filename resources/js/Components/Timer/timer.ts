import { router } from '@inertiajs/vue3';
import { computed, readonly, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { bufferHeartbeat, readSession, writeSession } from '@/lib/timerState';

/**
 * The remote timer's client half: the payload's types, the endpoints spelled once, and the one
 * store every timer control on the page shares.
 *
 * ## `localStorage` is a cache of intent, the server is the truth
 *
 * Everything displayed here comes from a `TimerState` the server sent. The buffer in
 * `lib/timerState.ts` exists only so a closed laptop does not lose a session: on reconnect it
 * is replayed to `POST /employee/time/replay`, and whatever comes back **replaces** what this
 * store believed. There is no merge step and no branch where the client's version wins.
 *
 * The clearest case is the one this is built for: the watchdog stops a session at 13:04 while
 * the laptop is shut; the tab wakes at six and pings; the reply says `running: null`; the
 * counter stops at whatever the server recorded. The browser finds out through the request it
 * was making anyway.
 *
 * ## One store, however many controls
 *
 * The persistent bar, the task-detail widget and the Time page all call `useTimer()` and get
 * the same module-level refs. Two heartbeat loops pinging the same session would be two
 * requests a minute and two ideas of whether it is running.
 *
 * ## Nothing here decides what anybody may do
 *
 * `canTrack` is `auth.user.canTrackTime`, which is `TimeEntryPolicy::track` resolved on the
 * server. It is never re-derived from a role or a tracking mode in this file, and every
 * endpoint checks again regardless of what is on screen.
 */

/* ------------------------------------------------------------------- the payload */

export interface TimerNamedRef {
    id: number;
    name: string;
}

/** One entry, exactly as `TimeEntryResource` sends it. */
export interface TimeEntry {
    id: number;
    client_uuid: string;
    task: TimerNamedRef | null;
    project: TimerNamedRef | null;
    work_date: string | null;
    started_at: string | null;
    ended_at: string | null;
    paused_at: string | null;
    last_heartbeat_at: string | null;
    paused_seconds: number;
    duration_seconds: number | null;
    elapsed_seconds: number;
    state: 'running' | 'paused' | 'stopped';
    /** The state in words. No state on this screen is carried by colour alone. */
    state_label: string;
    entry_type: 'auto' | 'manual';
    entry_type_label: string;
    is_flagged: boolean;
    /** Why it was flagged, as a sentence. A flag with no reason is a flag nobody investigates. */
    flag_reason: string | null;
    counts: boolean;
    awaits_approval: boolean;
    approval_label: string | null;
    reason: string | null;
    edited_at: string | null;
    permissions: { can_update: boolean };
}

/** The whole answer to "what is my timer doing", from `BuildsTimerState`. */
export interface TimerState {
    server_time: string;
    running: TimeEntry | null;
    today: {
        date: string;
        counted_seconds: number;
        pending_seconds: number;
        target_seconds: number | null;
    };
    heartbeat_seconds: number;
    heartbeat_timeout_minutes: number;
    manual_time_requires_approval: boolean;
    /**
     * The picker's options, sent by `GET /employee/time/current` only — never by the
     * once-a-minute heartbeat, which has no business carrying a list. They are held in their
     * own ref for the same reason: they are not part of "what is my timer doing".
     */
    tasks?: TimeableTask[];
}

/** A task the timer may be pointed at — the controller's list, scoped by `Task::visibleTo()`. */
export interface TimeableTask {
    id: number;
    title: string;
    project: string | null;
}

export interface TimeDay {
    date: string;
    label: string;
    counted_seconds: number;
    pending_seconds: number;
    flagged_count: number;
    entries: TimeEntry[];
}

/* ------------------------------------------------------------------ the endpoints */

export const timerRoutes = {
    index: '/employee/time',
    current: '/employee/time/current',
    start: '/employee/time/start',
    pause: '/employee/time/pause',
    resume: '/employee/time/resume',
    stop: '/employee/time/stop',
    heartbeat: '/employee/time/heartbeat',
    replay: '/employee/time/replay',
    entries: '/employee/time/entries',
    entry: (id: number): string => `/employee/time/entries/${id}`,
} as const;

/* ------------------------------------------------------------------ formatting */

/**
 * "4h 13m" — how a total reads everywhere but the live counter.
 *
 * Zero is "0m" and not a dash: a day with nothing on it has a number, and a dash would read
 * as "not recorded" rather than "nothing yet".
 */
export function formatDuration(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined || !Number.isFinite(seconds) || seconds <= 0) {
        return '0m';
    }

    const total = Math.floor(seconds);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);

    if (hours === 0) {
        return `${minutes}m`;
    }

    return minutes === 0 ? `${hours}h` : `${hours}h ${minutes}m`;
}

/** "4:13:07" — the live counter only, where the seconds are the point. */
export function formatClock(seconds: number): string {
    const total = Math.max(0, Math.floor(seconds));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const rest = total % 60;
    const pad = (value: number): string => String(value).padStart(2, '0');

    return `${hours}:${pad(minutes)}:${pad(rest)}`;
}

/** The same number spoken rather than shown, for the screen-reader announcements. */
export function spokenDuration(seconds: number): string {
    const total = Math.max(0, Math.floor(seconds));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);

    const parts: string[] = [];

    if (hours > 0) {
        parts.push(`${hours} hour${hours === 1 ? '' : 's'}`);
    }

    parts.push(`${minutes} minute${minutes === 1 ? '' : 's'}`);

    return parts.join(' ');
}

export function formatTimeOfDay(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime())
        ? '—'
        : new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit' }).format(date);
}

/* ------------------------------------------------------------------- the store */

const state = ref<TimerState | null>(null);
/** The Start picker's options, refreshed by `current` alone. Not state; a list. */
const tasks = ref<TimeableTask[]>([]);
const online = ref(true);
/** Ticks once a second, and is the only thing that makes the live counter recompute. */
const tick = ref(0);

/** The server's clock minus this browser's, so a skewed laptop cannot invent minutes. */
let clockOffsetMs = 0;
/** The `server_time` the current `elapsed_seconds` was measured at. */
let basisMs = 0;

let tickHandle: ReturnType<typeof setInterval> | null = null;
let pingHandle: ReturnType<typeof setInterval> | null = null;
let listening = false;

function serverNow(): number {
    return Date.now() + clockOffsetMs;
}

function isoNow(): string {
    return new Date(serverNow()).toISOString();
}

/**
 * Laravel accepts the CSRF token as `X-XSRF-TOKEN`, decrypted from the cookie Inertia's own
 * requests use. `fetch` does not send it by itself, so the two background calls read it here.
 */
function csrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function postJson(url: string, body: Record<string, unknown> = {}): Promise<TimerState | null> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(body),
    });

    // 403 means this person may not time at all, 419 that the session went, 409 that the batch
    // was refused. None of them is something a background loop should shout about — but the
    // reply to a 409 still carries the server's state, and taking it is the whole point.
    if (response.status === 409) {
        return (await response.json()) as TimerState;
    }

    if (!response.ok) {
        return null;
    }

    return (await response.json()) as TimerState;
}

/**
 * Take the server's answer, whole.
 *
 * This is the only writer of `state`, and it also rewrites the buffer: a session the server
 * says is running is re-recorded with no undelivered pings (they have just been delivered), and
 * a session it says is over is erased. That is what stops a replayed batch being replayed for
 * ever after it has been accepted.
 */
function adopt(payload: TimerState | null): void {
    if (payload === null) {
        return;
    }

    state.value = payload;

    const serverTime = Date.parse(payload.server_time);

    if (!Number.isNaN(serverTime)) {
        clockOffsetMs = serverTime - Date.now();
        basisMs = serverTime;
    }

    const running = payload.running;

    if (running === null || running.started_at === null || running.task === null) {
        writeSession(null);

        return;
    }

    writeSession({
        clientUuid: running.client_uuid,
        taskId: running.task.id,
        startedAt: running.started_at,
        heartbeats: [],
        pausedSeconds: running.paused_seconds,
        stoppedAt: null,
    });
}

/**
 * One beat: replay anything buffered, then ping.
 *
 * A failed call is not an error state to show — it is the offline case the buffer exists for.
 * The ping's own timestamp goes into the buffer so that when the connection comes back the
 * server can see how long the session really lasted.
 */
async function beat(): Promise<void> {
    const at = isoNow();
    const buffered = readSession();

    try {
        if (!online.value && buffered !== null && (buffered.heartbeats.length > 0 || buffered.stoppedAt !== null)) {
            const replayed = await postJson(timerRoutes.replay, {
                task_id: buffered.taskId,
                client_uuid: buffered.clientUuid,
                started_at: buffered.startedAt,
                heartbeats: buffered.heartbeats,
                paused_seconds: buffered.pausedSeconds,
                stopped_at: buffered.stoppedAt,
            });

            if (replayed !== null) {
                adopt(replayed);
                online.value = true;

                return;
            }
        }

        const payload = await postJson(timerRoutes.heartbeat);

        if (payload === null) {
            online.value = false;
            bufferHeartbeat(at);

            return;
        }

        adopt(payload);
        online.value = true;
    } catch {
        online.value = false;
        bufferHeartbeat(at);
    }
}

async function refresh(): Promise<void> {
    try {
        const response = await fetch(timerRoutes.current, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) {
            online.value = false;

            return;
        }

        const payload = (await response.json()) as TimerState;

        adopt(payload);

        if (Array.isArray(payload.tasks)) {
            tasks.value = payload.tasks;
        }

        online.value = true;
    } catch {
        online.value = false;
    }
}

function startLoops(): void {
    if (tickHandle === null) {
        tickHandle = setInterval(() => {
            tick.value += 1;
        }, 1000);
    }

    if (pingHandle === null) {
        pingHandle = setInterval(() => {
            void beat();
        }, Math.max(15, state.value?.heartbeat_seconds ?? 60) * 1000);
    }

    if (!listening && typeof window !== 'undefined') {
        listening = true;

        // The browser telling us it is back is worth a beat straight away: waiting up to a
        // minute for the next tick is up to a minute of a session the server thinks is dead.
        window.addEventListener('online', () => void beat());
        // A laptop reopened. Visibility is the signal that fires when a tab comes back from a
        // sleep the interval slept through.
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                void beat();
            }
        });
    }
}

export interface TimerStore {
    state: Readonly<Ref<TimerState | null>>;
    /** What the Start picker may offer — `Task::visibleTo()`'s answer, from the server. */
    tasks: Readonly<Ref<TimeableTask[]>>;
    running: ComputedRef<TimeEntry | null>;
    /** Running, paused, offline or idle — a word, because no state here is a colour. */
    status: ComputedRef<'running' | 'paused' | 'offline' | 'idle'>;
    online: Readonly<Ref<boolean>>;
    /** Seconds on the open session as of this second. Zero when nothing is going. */
    elapsedSeconds: ComputedRef<number>;
    /** Banked today plus the open session — the number the target is read against. */
    todaySeconds: ComputedRef<number>;
    targetSeconds: ComputedRef<number | null>;
    pendingSeconds: ComputedRef<number>;
    busy: Readonly<Ref<boolean>>;
    adopt: (payload: TimerState | null) => void;
    refresh: () => Promise<void>;
    start: (taskId: number) => void;
    pause: () => void;
    resume: () => void;
    stop: () => void;
}

const busy = ref(false);

/**
 * Post one of the four verbs.
 *
 * Inertia rather than `fetch`, so the write behaves like every other write in this codebase:
 * the flash comes back through the toaster, and `preserveState`/`preserveScroll` keep the page
 * the person is standing on exactly where it was. The store then re-reads `current`, because
 * the bar lives in the layout and gets no props of its own.
 */
function verb(url: string, data: Record<string, string | number> = {}): void {
    if (busy.value) {
        return;
    }

    busy.value = true;

    router.post(url, data, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            busy.value = false;
            void refresh();
        },
    });
}

let store: TimerStore | null = null;

export function useTimer(): TimerStore {
    if (store !== null) {
        startLoops();

        return store;
    }

    const running = computed<TimeEntry | null>(() => state.value?.running ?? null);

    const elapsedSeconds = computed<number>(() => {
        // Reading `tick` is what re-runs this every second. Without it the counter would be
        // computed once and sit there.
        void tick.value;

        const entry = running.value;

        if (entry === null) {
            return 0;
        }

        // A paused session is frozen at the moment it was paused — that is what pausing means,
        // and the server has already worked out where that is.
        if (entry.state === 'paused') {
            return entry.elapsed_seconds;
        }

        return entry.elapsed_seconds + Math.max(0, (serverNow() - basisMs) / 1000);
    });

    store = {
        state: readonly(state) as Readonly<Ref<TimerState | null>>,
        tasks: readonly(tasks) as Readonly<Ref<TimeableTask[]>>,
        running,
        status: computed(() => {
            if (!online.value) {
                return 'offline';
            }

            const entry = running.value;

            if (entry === null) {
                return 'idle';
            }

            return entry.state === 'paused' ? 'paused' : 'running';
        }),
        online: readonly(online) as Readonly<Ref<boolean>>,
        elapsedSeconds,
        todaySeconds: computed(() => (state.value?.today.counted_seconds ?? 0) + Math.floor(elapsedSeconds.value)),
        targetSeconds: computed(() => state.value?.today.target_seconds ?? null),
        pendingSeconds: computed(() => state.value?.today.pending_seconds ?? 0),
        busy: readonly(busy) as Readonly<Ref<boolean>>,
        adopt,
        refresh,
        start: (taskId: number) => verb(timerRoutes.start, { task_id: taskId, client_uuid: newUuid() }),
        pause: () => verb(timerRoutes.pause),
        resume: () => verb(timerRoutes.resume),
        stop: () => verb(timerRoutes.stop),
    };

    startLoops();

    return store;
}

/**
 * The idempotency key, generated here so that a start which never gets its reply can be sent
 * again and land on the row it already made. `crypto.randomUUID` is not available on an
 * insecure origin, hence the fallback.
 */
export function newUuid(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
        const random = (Math.random() * 16) | 0;
        const value = char === 'x' ? random : (random & 0x3) | 0x8;

        return value.toString(16);
    });
}
