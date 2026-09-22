import { router } from '@inertiajs/vue3';
import type { FormDataConvertible } from '@inertiajs/core';
import type { StatusKey } from '@/Components/StatusBadge.vue';
import type { Task, TaskPerson } from '@/Components/Tasks/TaskList.vue';
import { flashSeq, lastFlash } from '@/lib/flashChannel';

/**
 * The detail payload, the endpoints that write it, and the one place a task mutation is
 * announced.
 *
 * Everything here is shared by the page mount (`Pages/{Admin,Employee}/Tasks/Show.vue`) and
 * the drawer mount (the Tasks List). The two differ only in where `TaskDetailBody` hangs and
 * in how a fresh payload is obtained; every rule below is the same on both.
 */

/** Which surface's routes to write to. It is never what decides what a user may do. */
export type TaskSurface = 'admin' | 'employee';

/**
 * One move this requester may make from where the task is now.
 *
 * Resolved by `TaskResource::availableTransitions()` — the status machine's legal moves
 * already filtered through `TaskPolicy::transition`, which is the same check the endpoint
 * runs. The screen renders this list and never derives one: a second copy of
 * `TaskStatus::TRANSITIONS` in Vue is a copy that drifts.
 */
export interface TaskTransition {
    value: string;
    label: string;
    tone: StatusKey;
}

export interface TaskChecklistItem {
    id: number;
    title: string;
    is_done: boolean;
    completed_by: TaskPerson | null;
    completed_at: string | null;
}

export interface TaskLink {
    id: number;
    url: string;
    label: string;
}

/** A dependency is named and located, never serialised whole (see `TaskResource::taskStubs`). */
export interface TaskStub {
    id: number;
    title: string;
    status: string | null;
    status_label: string | null;
    status_tone: StatusKey | null;
}

/**
 * The completion that happened first, kept on the row through every reopening.
 *
 * Present whether or not the task is completed *now* — that is the whole point of the
 * columns, and why the screen prints it from its own panel rather than from the live
 * completion fields, which a reopen clears.
 */
export interface TaskFirstCompletion {
    at: string;
    by: TaskPerson | null;
    work_summary: string | null;
}

/** A task as the detail endpoints send it: the list payload plus the panels' relations. */
export interface TaskDetail extends Task {
    /** Who wrote the summary on the task. Completion checks this against the primary. */
    work_summary_by: TaskPerson | null;
    work_summary_at: string | null;
    first_completion: TaskFirstCompletion | null;
    subtasks_done_count: number;
    checklist: TaskChecklistItem[];
    links: TaskLink[];
    dependencies: TaskStub[];
    dependents: TaskStub[];
    available_transitions: TaskTransition[];
}

export interface TaskActivityEntry {
    description: string;
    actor: string | null;
    at: string | null;
}

export interface TaskSibling {
    id: number;
    title: string;
}

/** The status values the screen has to name by hand, because a rule hangs off each one. */
export const STATUS_IN_REVIEW = 'in_review';
export const STATUS_COMPLETED = 'completed';
export const STATUS_CANCELLED = 'cancelled';
export const STATUS_IN_PROGRESS = 'in_progress';
export const STATUS_CHANGES_REQUESTED = 'changes_requested';

/** Every task endpoint for one surface, spelled once. */
export function taskRoutes(surface: TaskSurface, id: number) {
    const base = `/${surface}/tasks/${id}`;

    return {
        show: base,
        update: base,
        destroy: base,
        /** The ONE door a status goes through. `update` does not accept `status`. */
        status: `${base}/status`,
        /**
         * Moving a card **inside** its own column. A drag between columns is a status move
         * and carries its `after_id` to `status` instead, so the move and the placing happen
         * in one transaction rather than two requests that can half-fail.
         */
        reorder: `${base}/reorder`,
        archive: `${base}/archive`,
        unarchive: `${base}/unarchive`,
        assignees: `${base}/assignees`,
        handoff: `${base}/handoff`,
        checklist: `${base}/checklist`,
        checklistItem: (item: number) => `${base}/checklist/${item}`,
        links: `${base}/links`,
        link: (link: number) => `${base}/links/${link}`,
        dependencies: `${base}/dependencies`,
        dependency: (dependency: number) => `${base}/dependencies/${dependency}`,
        index: `/${surface}/tasks`,
    };
}

export interface TaskMutationOptions {
    /** Runs only when the server accepted it — a flashed `error` is a refusal, not a success. */
    onAccepted?: () => void;
    onFinish?: () => void;
    /** Drawer mode re-reads the detail here; the page mount already has fresh props. */
    onSettled?: () => void;
}

type Method = 'post' | 'put' | 'delete';

/**
 * Post a task write and hand whatever the server said to the toaster.
 *
 * `preserveState` keeps the drawer (and any open sub-dialog) mounted across the redirect;
 * `preserveScroll` keeps a long detail page where the reader left it.
 */
export function mutateTask(
    method: Method,
    url: string,
    data: Record<string, FormDataConvertible> = {},
    options: TaskMutationOptions = {},
): void {
    // `useFlashAsToast()` consumes the flash synchronously as the page lands, so this is
    // what the server said about THIS write, and nothing else has to re-read it.
    const mark = flashSeq.value;

    router.visit(url, {
        method,
        data,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            // A flashed error is a refusal by the task's own state, not a failed request:
            // `TaskStateException` comes back 200 with a message, so a 2xx is not enough.
            const refused = flashSeq.value !== mark && lastFlash.error !== null;

            if (!refused) {
                options.onAccepted?.();
            }

            options.onSettled?.();
        },
        onError: () => {
            // Validation errors are rendered against their fields; this is the catch-all for
            // a write that never reached them.
            options.onSettled?.();
        },
        onFinish: () => options.onFinish?.(),
    });
}

/**
 * Move focus into a shadcn-vue field.
 *
 * `<Input>` and `<Textarea>` are single-root wrapper components, so a template ref on one is
 * the component instance and not the element — `ref.value.focus()` is silently a no-op, which
 * is how a dialog ends up opening with focus on its first tab. This reaches the element the
 * instance rendered.
 */
export function focusField(target: { $el?: unknown } | null | undefined): void {
    const element = target?.$el;

    if (element instanceof HTMLElement) {
        element.focus();
    }
}

/* ------------------------------------------------------------------ formatting */

const DATE = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' });
const DATE_TIME = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short' });

export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : DATE.format(date);
}

export function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : DATE_TIME.format(date);
}

/** An estimate is stored in minutes and read in hours and minutes. */
export function formatMinutes(minutes: number | null): string {
    if (minutes === null || !Number.isFinite(minutes) || minutes <= 0) {
        return '—';
    }

    const hours = Math.floor(minutes / 60);
    const rest = Math.round(minutes % 60);

    if (hours === 0) {
        return `${rest}m`;
    }

    return rest === 0 ? `${hours}h` : `${hours}h ${rest}m`;
}

export function initials(name: string | null | undefined): string {
    return (name ?? '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase() ?? '')
        .join('');
}
