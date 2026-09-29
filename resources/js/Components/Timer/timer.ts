import { router, usePage } from '@inertiajs/vue3';
import { computed, readonly, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { backoff, fetchWithTimeout, reportNetworkFailure } from '@/lib/net';
import { isSessionLive, onSessionResume, reportResponse } from '@/lib/session';
import { toast } from '@/lib/toast';
import type { BufferedSession, StartIntent } from '@/lib/timerState';
import { bufferHeartbeat, readSession, readStartIntent, writeSession, writeStartIntent } from '@/lib/timerState';

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
    /**
     * Where this entry stands with an approver — `TimeEntry::approvalKey()`.
     *
     * `counted` covers both an auto entry the system signed off at stop and a manual one an
     * Admin approved; the difference is who, not whether it counts. `rejected` means somebody
     * looked and said no: the hours are still on the row and still shown, they simply are not
     * in any total. Never derived here from `counts` and `awaits_approval` — the server's word
     * is the word (decision 2-37).
     */
    approval: 'open' | 'pending' | 'counted' | 'rejected';
    approval_label: string | null;
    /**
     * Why this entry is waiting, as sentences: added by hand, edited after the fact, or flagged
     * by the timer with the flag's own words. Empty for an entry that is not waiting.
     */
    waiting_because: { key: string; label: string; detail: string | null }[];
    reason: string | null;
    edited_at: string | null;
    edited_by?: string | null;
    /** A refusal is not a deletion: the row stays, and this is why it does not count. */
    rejected_at: string | null;
    rejected_by?: string | null;
    rejection_reason: string | null;
    approved_at: string | null;
    /** Null with `approved_at` set means the system signed off an auto entry at stop. */
    approved_by?: string | null;
    /** Only on the Admin queue, where the rows are other people's. */
    employee?: { id: number; name: string };
    permissions: { can_update: boolean; can_decide?: boolean };
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

/* ------------------------------------------------------------------- the words */

/** Reliability slice 4: shown on the timer while a Stop waits for the connection. */
export const OFFLINE_STOP_TEXT = 'Stopped. This will be saved when your connection is back.';

/** Said once, when that Stop has reached the server. */
export const OFFLINE_STOP_SAVED_TEXT = 'Your stopped timer has been saved.';

/* ------------------------------------------------------------------- the store */

const state = ref<TimerState | null>(null);
/** The Start picker's options, refreshed by `current` alone. Not state; a list. */
const tasks = ref<TimeableTask[]>([]);
const online = ref(true);
/** Ticks once a second, and is the only thing that makes the live counter recompute. */
const tick = ref(0);
/**
 * A Stop the server has not heard yet: the buffer carries its `stoppedAt` and the replay will
 * deliver it. While this is true the counter is stopped here and Start waits.
 */
const stopPending = ref(false);

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

async function postJson(
    url: string,
    body: Record<string, unknown> = {},
    /** Filled with the reply's status, for a caller that must tell a refusal from an outage. */
    meta?: { status: number },
): Promise<TimerState | null> {
    const response = await fetchWithTimeout(url, {
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

    if (meta !== undefined) {
        meta.status = response.status;
    }

    // 403 means this person may not time at all, 419 that the session went, 409 that the batch
    // was refused. None of them is something a background loop should shout about — but the
    // reply to a 409 still carries the server's state, and taking it is the whole point.
    if (response.status === 409) {
        return (await response.json()) as TimerState;
    }

    // 401 / 419 (or a surface 403): the session module says so, once, for every reader. The
    // caller sees `null` and — because `isSessionLive()` is now false — buffers instead of
    // calling itself offline.
    if (await reportResponse(response)) {
        return null;
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

    // The start this browser was retrying has landed: the intent is spent.
    const intent = readStartIntent();

    if (intent !== null && running !== null && running.client_uuid === intent.clientUuid) {
        writeStartIntent(null);
    }

    // A Stop made offline is owed to the server. This answer predates it (the replay has not
    // run yet), so it must not overwrite the buffer that carries the stop — that would lose it —
    // and the entry the server still calls running is shown as what it is here: stopped. The
    // replay is the next thing sent, and its answer is adopted whole like any other.
    const buffered = readSession();

    if (buffered !== null && buffered.stoppedAt !== null && ownedHere(buffered)) {
        stopPending.value = true;
        replayOwed = true;

        if (running !== null && running.client_uuid === buffered.clientUuid) {
            state.value = { ...payload, running: null };
        }

        return;
    }

    if (running === null || running.started_at === null || running.task === null) {
        writeSession(null);

        return;
    }

    writeSession({
        userId: currentUserId,
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
/**
 * Set while heartbeats are being buffered because the session ended (not because the network
 * did). `online` stays true then — this is not an offline — so the replay condition needs its
 * own flag to know the buffer is owed to the server once the person signs back in.
 */
let replayOwed = false;

/** `auth.user.id` of the page this store runs on — the owner written into the buffer. */
let currentUserId: number | null = null;

/** A buffer that names another owner is not this person's to replay. Unowned (legacy) ones are. */
function ownedHere(buffered: BufferedSession): boolean {
    return buffered.userId === null || buffered.userId === undefined || buffered.userId === currentUserId;
}

/**
 * Reliability slice 2a: the heartbeat's backoff. The beat itself keeps its interval — the tracked
 * time is recorded locally on every tick, sent or not — but after a failed send the next SEND
 * waits twice as long, up to five minutes, instead of knocking on a dead server every minute.
 * The ticks in between buffer exactly as an offline tick does, and the next send replays them.
 * The browser coming back online sends at once (the `online` listener below, and the gate opens
 * on the same event).
 */
let beatGate: ReturnType<typeof backoff> | null = null;

async function beat(): Promise<void> {
    const at = isoNow();

    // Signed out: keep the tracked time (the beat goes into the local buffer exactly as an
    // offline beat would) but send nothing. The replay waits for the session to come back.
    if (!isSessionLive()) {
        // Owed only when there is a session to buffer into; nothing running, nothing owed.
        replayOwed = replayOwed || readSession() !== null;
        bufferHeartbeat(at);

        return;
    }

    beatGate ??= backoff(Math.max(15, state.value?.heartbeat_seconds ?? 60) * 1000);

    // Backed off (or the browser is offline): record the tick, send nothing.
    if (!beatGate.ready()) {
        online.value = false;
        bufferHeartbeat(at);

        return;
    }

    const buffered = readSession();
    const sentAt = Date.now();

    // A pending Stop whose buffer is gone was delivered by another tab (or the buffer was
    // cleared): nothing is owed any more.
    if (stopPending.value && (buffered === null || buffered.stoppedAt === null || !ownedHere(buffered))) {
        stopPending.value = false;
    }

    try {
        if ((!online.value || replayOwed || stopPending.value) && buffered !== null && ownedHere(buffered) && (buffered.heartbeats.length > 0 || buffered.stoppedAt !== null)) {
            const reply = { status: 0 };
            const replayed = await postJson(timerRoutes.replay, {
                task_id: buffered.taskId,
                client_uuid: buffered.clientUuid,
                started_at: buffered.startedAt,
                heartbeats: buffered.heartbeats,
                paused_seconds: buffered.pausedSeconds,
                stopped_at: buffered.stoppedAt,
            }, reply);

            if (replayed !== null) {
                // A 409 carries the server's state and a `message`: the batch was refused, so a
                // stop in it was not saved and must not be announced as saved.
                const accepted = (replayed as TimerState & { message?: string }).message === undefined;
                const carriedStop = buffered.stoppedAt !== null;

                if (carriedStop) {
                    // Delivered (or refused for good): the stop is no longer owed. Cleared before
                    // `adopt`, which would otherwise keep it as still pending.
                    writeSession(null);
                    stopPending.value = false;
                }

                adopt(replayed);
                online.value = true;
                replayOwed = false;
                beatGate.succeed();

                if (carriedStop && accepted) {
                    toast.success(OFFLINE_STOP_SAVED_TEXT);
                }

                return;
            }

            if (!isSessionLive()) {
                replayOwed = true;
                bufferHeartbeat(at);

                return;
            }

            // A Stop is owed. While it is, the LIVE heartbeat is never sent: it would move the open
            // entry's `last_heartbeat_at` forward and keep alive a session the person has ended,
            // past both the watchdog and the evidence cap. Only the replay goes out.
            if (buffered.stoppedAt !== null) {
                if (reply.status >= 400 && reply.status < 500 && reply.status !== 429) {
                    // Refused for good (a 404 because the task is no longer theirs, a 422): the
                    // same batch will be refused every time. Drop it and take the server's word.
                    // The open entry is then shown as it is, stoppable online, and otherwise the
                    // watchdog ends it at its last accepted heartbeat — as before this slice.
                    writeSession(null);
                    stopPending.value = false;
                    replayOwed = false;
                    online.value = true;
                    beatGate.succeed();
                    void refresh();

                    return;
                }

                // A 5xx or a 429: still owed, retried on the backoff.
                online.value = false;
                beatGate.fail(sentAt);

                return;
            }
        }

        // Belt and braces: nothing reaches the live heartbeat while a Stop is owed.
        if (stopPending.value) {
            return;
        }

        const payload = await postJson(timerRoutes.heartbeat);

        if (payload === null) {
            if (!isSessionLive()) {
                replayOwed = true;
                bufferHeartbeat(at);

                return;
            }

            online.value = false;
            beatGate.fail(sentAt);
            bufferHeartbeat(at);

            return;
        }

        adopt(payload);
        online.value = true;
        beatGate.succeed();
    } catch {
        online.value = false;
        beatGate.fail(sentAt);
        bufferHeartbeat(at);
    }
}

async function refresh(): Promise<void> {
    if (!isSessionLive()) {
        return;
    }

    try {
        const response = await fetchWithTimeout(timerRoutes.current, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (await reportResponse(response)) {
            return;
        }

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

        // Reachable, and a Stop is still owed (a reload after an offline Stop): send it now
        // rather than at the next tick. Only from here — never from `adopt`, which `beat` itself
        // calls.
        if (stopPending.value) {
            void beat();
        }
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
        // Signed back in (in another tab): one beat, which replays whatever was buffered while
        // the session was gone. Intervals are untouched.
        // Only when there is something to replay or a session to keep alive — somebody who was
        // not timing has nothing to send.
        onSessionResume(() => {
            if (replayOwed || readSession() !== null) {
                void beat();
            }
        });
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
    /** A Stop made while goodERP could not be reached, waiting to be delivered. */
    stopPending: Readonly<Ref<boolean>>;
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

/**
 * Can a write be sent right now? Signed in, the browser online, and the heartbeat not inside a
 * backed-off window (slice 2a) — the same three facts the beat checks before it sends.
 */
function canSendNow(): boolean {
    if (!isSessionLive()) {
        return false;
    }

    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
        return false;
    }

    return beatGate === null || beatGate.ready();
}

/**
 * Reliability slice 4: Start, idempotent across retries.
 *
 * The `client_uuid` belongs to the intent (`lib/timerState.ts` → `StartIntent`), so pressing
 * Start again after a failed attempt sends the SAME uuid, and `TimerService::start()` answers a
 * uuid it has already seen with the entry it made — one row, however many attempts. The intent
 * is spent once the server has answered (a page came back, success or a refusal in words) or
 * once a running entry with that uuid is adopted; a Start on another task is a new intent.
 *
 * A network failure is still told by slice 2's global toast; nothing is queued, because a start
 * the server never recorded is not a session.
 */
function start(taskId: number): void {
    if (busy.value || stopPending.value) {
        return;
    }

    let intent: StartIntent | null = readStartIntent();

    if (intent === null || intent.taskId !== taskId || (intent.userId !== null && intent.userId !== currentUserId)) {
        intent = { clientUuid: newUuid(), taskId, userId: currentUserId, createdAt: Date.now() };
        writeStartIntent(intent);
    }

    busy.value = true;

    let answered = false;
    const uuid = intent.clientUuid;

    router.post(
        timerRoutes.start,
        { task_id: taskId, client_uuid: uuid },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                answered = true;
            },
            onError: () => {
                answered = true;
            },
            onFinish: () => {
                busy.value = false;

                void refresh().then(() => {
                    // The server answered, or it is running something else: either way this
                    // intent is spent, and the next press mints a new uuid. (A running entry
                    // WITH this uuid has already cleared it in `adopt`.) A start that met no
                    // answer and whose follow-up read failed too keeps it for the retry.
                    const running = state.value?.running ?? null;

                    if (answered || (running !== null && running.client_uuid !== uuid)) {
                        const current = readStartIntent();

                        if (current !== null && current.clientUuid === uuid) {
                            writeStartIntent(null);
                        }
                    }
                });
            },
        },
    );
}

/**
 * Hold a Stop the server could not be told about: the buffer gets its `stoppedAt` (this
 * browser's clock, a claim), the counter stops here, and the replay delivers it when goodERP is
 * reachable again. The server's evidence rule decides where the entry really ends — the claim is
 * capped at the latest heartbeat, so nothing here can add time.
 *
 * The one heartbeat added is `last_heartbeat_at` from the entry as the server last sent it: a
 * ping the server itself already accepted. Without it a Stop pressed just after a delivered ping
 * (whose buffer is empty) would replay with the start as its only evidence and end the entry at
 * its first second.
 */
function stopOffline(entry: TimeEntry): void {
    if (entry.task === null || entry.started_at === null) {
        return;
    }

    const buffered = readSession();
    const base: BufferedSession =
        buffered !== null && buffered.clientUuid === entry.client_uuid
            ? buffered
            : {
                  userId: currentUserId,
                  clientUuid: entry.client_uuid,
                  taskId: entry.task.id,
                  startedAt: entry.started_at,
                  heartbeats: [],
                  pausedSeconds: entry.paused_seconds,
                  stoppedAt: null,
              };

    const heartbeats = entry.last_heartbeat_at === null ? base.heartbeats : [...base.heartbeats, entry.last_heartbeat_at];

    writeSession({ ...base, userId: currentUserId, heartbeats, stoppedAt: base.stoppedAt ?? isoNow() });

    stopPending.value = true;
    replayOwed = true;

    if (state.value !== null) {
        state.value = { ...state.value, running: null };
    }
}

/**
 * Reliability slice 4: Stop, which is never lost.
 *
 * Sent when it can be. When it cannot — offline, signed out, the heartbeat backed off — or when
 * the send meets no answer or a 5xx, it is held (`stopOffline`) and shown inline on the timer
 * instead of toasted; a 401 / 419 is held too, and slice 1's session dialog still speaks.
 */
function stop(): void {
    const entry = state.value?.running ?? null;

    if (busy.value || entry === null) {
        return;
    }

    if (!canSendNow()) {
        stopOffline(entry);

        return;
    }

    busy.value = true;

    let held = false;

    router.post(
        timerRoutes.stop,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onNetworkError: () => {
                reportNetworkFailure();
                held = true;
                stopOffline(entry);

                return false;
            },
            onHttpException: (response: { status: number }) => {
                if (response.status >= 500) {
                    reportNetworkFailure();
                    held = true;
                    stopOffline(entry);

                    return false;
                }

                if (response.status === 401 || response.status === 419) {
                    held = true;
                    stopOffline(entry);
                }

                return undefined;
            },
            onFinish: () => {
                busy.value = false;

                if (!held) {
                    void refresh();
                }
            },
        },
    );
}

let store: TimerStore | null = null;

export function useTimer(): TimerStore {
    currentUserId = usePage().props.auth.user?.id ?? currentUserId;

    if (store === null) {
        // A Stop held before a reload is still owed, and still shown as stopped.
        const buffered = readSession();

        if (buffered !== null && buffered.stoppedAt !== null && ownedHere(buffered)) {
            stopPending.value = true;
            replayOwed = true;
        }
    }

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
        stopPending: readonly(stopPending) as Readonly<Ref<boolean>>,
        adopt,
        refresh,
        start,
        pause: () => verb(timerRoutes.pause),
        resume: () => verb(timerRoutes.resume),
        stop,
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
