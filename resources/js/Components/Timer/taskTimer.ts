import { router } from '@inertiajs/vue3';
import { readonly, ref } from 'vue';
import type { Ref } from 'vue';
import { fetchWithTimeout } from '@/lib/net';
import { isSessionLive, reportResponse } from '@/lib/session';
import { newUuid, useTimer } from '@/Components/Timer/timer';

/**
 * The task timer for everyone who works tasks — flow F3, decision 12-73. The ▶ / ⏸ / ⏹ on a
 * board card and in the drawer.
 *
 * ## Two clocks, one button
 *
 * The server decides which clock a person's day is on, never this file:
 *
 *   - **Remote-timer employees** (`auth.user.canTrackTime`) already have the persistent timer
 *     (`timer.ts`), with its heartbeat, its offline buffer and its replay. ▶ here posts the
 *     shared start, which switches THAT timer onto the task, and then asks the store to re-read
 *     — so the bar and the card agree, and the remote store stays the one heartbeat loop.
 *   - **Office employees and Admins** have no such store. Their task timer runs inside the
 *     clock-in, and the one safeguard it needs from the browser is the heartbeat (Part D §7:
 *     no ping for five minutes and the watchdog stops it). `TaskTimerPulse.vue`, mounted once
 *     per shell, runs that loop; this module holds whether it should.
 *
 * ## The clock-in question is the server's
 *
 * ▶ is posted as-is. An office employee who is not clocked in gets a `clock_in` validation error
 * back (`StartTaskTimerRequest`), and only then does the screen ask "Clock in and start?" and
 * post again with `clock_in: true`. The browser never guesses whether somebody is clocked in.
 *
 * ## Nothing here decides what anybody may do
 *
 * Whether a card draws ▶ at all is `task.permissions.can_track_time` (`TimeEntryPolicy::
 * trackTask`), and whether it shows who else is timing is the presence of `running_timers`,
 * which the server sends to watchers only.
 */

/** The reader's own open timer on a task, from `TaskResource.my_timer`. */
export interface MyTaskTimer {
    state: 'running' | 'paused';
    started_at: string | null;
    /** As of the moment the server built the payload. */
    elapsed_seconds: number;
}

/** Somebody else timing a card — sent to watchers only (`TaskResource.running_timers`). */
export interface RunningTaskTimer {
    employee_id: number;
    name: string;
    initials: string;
    started_at: string | null;
    state: 'running' | 'paused';
    elapsed_seconds: number;
}

/**
 * One open task timer on the "Working now" panel — `TaskTimerService::workingNow()`, sent to
 * watchers only (the prop is absent for anybody else).
 */
export interface WorkingNowRow {
    id: number;
    employee: { id: number; name: string; initials: string };
    task: { id: number; title: string; href: string };
    project: { id: number; name: string } | null;
    started_at: string | null;
    paused: boolean;
    /** As of the moment the server built the payload. */
    elapsed_seconds: number;
}

/**
 * Which `task.changed` frames move the panel: a timer started, paused, resumed or stopped
 * (kind `timer`), or a task deleted with its entries. Every other kind is a card edit.
 */
export function workingNowPing(ping: { kind?: string }): boolean {
    return ping.kind === undefined || ping.kind === 'timer' || ping.kind === 'deleted';
}

export const taskTimerRoutes = {
    start: (taskId: number): string => `/tasks/${taskId}/timer`,
    pause: '/task-timer/pause',
    resume: '/task-timer/resume',
    stop: '/task-timer/stop',
    heartbeat: '/task-timer/heartbeat',
    /** The last tab's `pagehide` beacon (`TaskTimerPulse`). */
    leaving: '/task-timer/leaving',
} as const;

/** The confirm's words, verbatim from the brief. */
export const CLOCK_IN_CONFIRM_TEXT = 'Clock in and start?';

/* ------------------------------------------------------------------- the store */

const busy = ref(false);

/**
 * Which ▶ is asking "Clock in and start?" right now: the task and the component instance that
 * asked. Only that instance draws the dialog, so two hundred cards do not mount two hundred.
 */
const prompt = ref<{ taskId: number; owner: symbol } | null>(null);

/** Set when this tab knows an office/Admin timer is open; `TaskTimerPulse` beats while true. */
const pulseWanted = ref(false);

export interface TaskTimerActions {
    busy: Readonly<Ref<boolean>>;
    prompt: Readonly<Ref<{ taskId: number; owner: symbol } | null>>;
    pulseWanted: Readonly<Ref<boolean>>;
    start: (taskId: number, owner: symbol, options?: { clockIn?: boolean; onSettled?: () => void }) => void;
    pause: (onSettled?: () => void) => void;
    resume: (onSettled?: () => void) => void;
    stop: (onSettled?: () => void) => void;
    dismissPrompt: () => void;
    setPulseWanted: (wanted: boolean) => void;
}

/** A remote employee's persistent timer re-reads after any task-timer write, so the bar agrees. */
function syncRemoteStore(canTrackTime: boolean): void {
    if (canTrackTime) {
        void useTimer().refresh();
    }
}

function post(url: string, data: Record<string, unknown>, handlers: { onError?: (errors: Record<string, string>) => void; onSuccess?: () => void; onSettled?: () => void; canTrackTime: boolean }): void {
    if (busy.value) {
        return;
    }

    busy.value = true;

    router.post(url, data as Record<string, string | number | boolean>, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => handlers.onSuccess?.(),
        onError: (errors) => handlers.onError?.(errors as Record<string, string>),
        onFinish: () => {
            busy.value = false;
            syncRemoteStore(handlers.canTrackTime);
            handlers.onSettled?.();
        },
    });
}

export function useTaskTimer(canTrackTime: boolean): TaskTimerActions {
    return {
        busy: readonly(busy) as Readonly<Ref<boolean>>,
        prompt: readonly(prompt) as Readonly<Ref<{ taskId: number; owner: symbol } | null>>,
        pulseWanted: readonly(pulseWanted) as Readonly<Ref<boolean>>,
        start(taskId, owner, options = {}) {
            post(
                taskTimerRoutes.start(taskId),
                { client_uuid: newUuid(), ...(options.clockIn ? { clock_in: true } : {}) },
                {
                    canTrackTime,
                    onSuccess: () => {
                        prompt.value = null;
                        pulseWanted.value = !canTrackTime;
                    },
                    onError: (errors) => {
                        // The one refusal the screen answers with a question rather than a flash.
                        if (errors.clock_in !== undefined && !options.clockIn) {
                            prompt.value = { taskId, owner };
                        }
                    },
                    onSettled: options.onSettled,
                },
            );
        },
        pause: (onSettled) => post(taskTimerRoutes.pause, {}, { canTrackTime, onSettled }),
        resume: (onSettled) => post(taskTimerRoutes.resume, {}, { canTrackTime, onSettled }),
        stop: (onSettled) =>
            post(taskTimerRoutes.stop, {}, {
                canTrackTime,
                onSuccess: () => {
                    pulseWanted.value = false;
                },
                onSettled,
            }),
        dismissPrompt() {
            prompt.value = null;
        },
        setPulseWanted(wanted) {
            pulseWanted.value = wanted;
        },
    };
}

/* ------------------------------------------------------------------ the heartbeat */

interface HeartbeatReply {
    running: { task_id: number; state: string } | null;
    heartbeat_seconds: number;
}

function csrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * One "still here" for an office/Admin task timer. Returns whether a timer is still open, or
 * null when the answer could not be had (offline, signed out) — the caller keeps beating then,
 * because the watchdog, not this tab, is what ends a session nobody is at.
 */
export async function beatTaskTimer(): Promise<boolean | null> {
    if (!isSessionLive()) {
        return null;
    }

    try {
        const response = await fetchWithTimeout(taskTimerRoutes.heartbeat, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: '{}',
        });

        if (await reportResponse(response)) {
            return null;
        }

        if (!response.ok) {
            // 403: this person has no task timer at all. Nothing to keep alive.
            return response.status === 403 ? false : null;
        }

        const reply = (await response.json()) as HeartbeatReply;

        return reply.running !== null;
    } catch {
        return null;
    }
}

/* ------------------------------------------------------------------ formatting */

/**
 * Seconds on the reader's own timer as of `nowMs`, from the payload's `elapsed_seconds` and the
 * moment it was received. A paused timer is frozen where the server paused it.
 */
export function ownElapsed(timer: MyTaskTimer, receivedAtMs: number, nowMs: number): number {
    if (timer.state === 'paused') {
        return timer.elapsed_seconds;
    }

    return timer.elapsed_seconds + Math.max(0, Math.floor((nowMs - receivedAtMs) / 1000));
}
