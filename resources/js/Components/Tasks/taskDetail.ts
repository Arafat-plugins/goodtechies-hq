import { router } from '@inertiajs/vue3';
import type { Errors, FormDataConvertible } from '@inertiajs/core';
import type {
    ThreadAttachment,
    ThreadMessage,
    ThreadPayload,
} from '@/Components/Messages/messages';
import { MESSAGE_MAX_BODY } from '@/Components/Messages/messages';
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

/**
 * Where a generated task came from — Phase 3's *"Generated from: <template> · period <Month
 * YYYY>"*.
 *
 * Null on a task somebody made by hand, which is most of them, and absent from a LIST payload
 * entirely: the key sits behind the same `task_detail` attribute `available_transitions` does,
 * so a board of two hundred cards does not do two hundred relation reads to print nothing.
 *
 * `period_label` is derived by the server from the stored period KEY, never from the template's
 * current rule — so a template edited from monthly to weekly does not relabel every task it has
 * ever made, and a task outlives its template's changes. `can_manage` is
 * `RecurringTaskPolicy::view`, resolved per record: it is what decides whether the line is a
 * link to the project's Recurring tab or just a sentence, and it is never a role read here.
 */
export interface TaskGeneratedFrom {
    template_id: number | null;
    template: string | null;
    project_id: number | null;
    period: string | null;
    period_label: string | null;
    can_manage: boolean;
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
    generated_from: TaskGeneratedFrom | null;
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

/* ------------------------------------------------------------------ discussion */

/**
 * The task discussion's types are the messaging types, aliased rather than restated.
 *
 * Phase 6 made a thread one shape for every conversation — the task discussion, a project
 * channel, the team channel and a DM all arrive from `BuildsDiscussionPayload` identically —
 * so the definitions live in `Components/Messages/messages.ts` and these three names stay
 * pointed at them. Two descriptions of one payload is how one screen grows a field the other
 * does not.
 */
/**
 * The task discussion's types are the messaging types, aliased rather than restated.
 *
 * Phase 6 made a thread ONE shape for every conversation — a task discussion, a project
 * channel, the team channel and a DM all arrive from `BuildsDiscussionPayload` identically — so
 * the definitions live in `Components/Messages/messages.ts` and these three names stay pointed
 * at them. Two descriptions of one payload is how one screen grows a field the other does not,
 * which is exactly what happened to the tag pickers.
 */
export type TaskMessageAttachment = ThreadAttachment;
export type TaskMessage = ThreadMessage;
export type TaskDiscussion = ThreadPayload;

/**
 * `StoreMessageRequest::MAX_BODY`, restated so the composer can count down to it.
 *
 * A counter, not a second rule: the field is not capped at this and the submit is not blocked
 * by it. The server owns the refusal and says it in its own words, the same way the file
 * limits in `Files/files.ts` are a courtesy in front of `FileService`.
 */
export const TASK_MESSAGE_MAX_BODY = MESSAGE_MAX_BODY;

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
        /**
         * `GET` answers the thread as JSON **and marks it read** — that GET is the panel saying
         * it has displayed the discussion, which is exactly the event `last_read_at` records.
         * `POST` takes `body` and/or `file` and comes back `back()` with a flash like every
         * other write here.
         */
        discussion: `${base}/discussion`,
        index: `/${surface}/tasks`,
    };
}

export interface TaskMutationOptions {
    /** Runs only when the server accepted it — a flashed `error` is a refusal, not a success. */
    onAccepted?: () => void;
    onFinish?: () => void;
    /** Drawer mode re-reads the detail here; the page mount already has fresh props. */
    onSettled?: () => void;
    /** Multipart, for the one write on this screen that can carry a file. */
    forceFormData?: boolean;
    /**
     * The field errors, for a write whose refusal belongs under its field rather than in the
     * toaster — "Write something, or attach a file." is about the composer, not about the task.
     * The sentence handed over is the server's own; a caller that rewrites it is inventing a
     * rule the server does not have.
     */
    onInvalid?: (errors: Errors) => void;
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
        forceFormData: options.forceFormData,
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
        onError: (errors) => {
            // Validation errors are rendered against their fields — `onInvalid` is how a caller
            // that has fields to render them against gets hold of them.
            options.onInvalid?.(errors);
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
