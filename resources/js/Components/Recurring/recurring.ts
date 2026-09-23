import type { StatusKey } from '@/Components/StatusBadge.vue';

/**
 * The Recurring tab's payloads, its endpoints, and the one thing it is allowed to work out for
 * itself.
 *
 * ## No date arithmetic lives here, and none may
 *
 * Every date this screen shows — when a template next fires, which period that run belongs to,
 * when the instance it makes is due — is computed by `App\Support\RecurrenceRule` on the server
 * and arrives as a formatted string: on a saved template it rides on `RecurringTaskResource`,
 * and on a rule somebody is still typing it comes from `GET …/recurring/preview`. There is
 * deliberately no month arithmetic, no ISO-week helper and no "every N days" counter in this
 * folder. A second implementation of the period scheme would disagree with the engine, and the
 * first month it disagreed nobody would notice.
 *
 * What the editor DOES own is the opposite direction: turning three sets of controls into the
 * one `recurrence_rule` shape the server's Form Request accepts — see `ruleFields()`.
 */

/* ------------------------------------------------------------------ payloads */

export type RecurrenceFrequency = 'monthly' | 'weekly' | 'custom';

/** The stored rule parameters, canonical — what the server actually saved, clamps applied. */
export interface RecurrenceRuleFields {
    frequency: RecurrenceFrequency;
    day_of_month?: number;
    weekday?: number;
    interval_days?: number;
    anchor?: string | null;
    due_offset_days?: number;
}

/**
 * When a template fires next and what that run would produce.
 *
 * A block rather than a date, because a date on its own does not say which period it is for —
 * and the period is what the generated task gets labelled with, both in its title and on its
 * detail page.
 */
export interface RecurringNextRun {
    at: string;
    period: string;
    period_label: string | null;
    due_date: string;
}

export interface RecurringTaskStub {
    id: number;
    title: string;
}

/** One attempt, already spelled by `RecurringGenerationLogResource`. */
export interface RecurringLogEntry {
    id: number;
    period: string;
    period_label: string | null;
    outcome: string | null;
    outcome_label: string | null;
    /** A skip, or a generation that happened while the previous period was still open. */
    is_warning: boolean;
    message: string | null;
    /** The row as a sentence — the engine's own words wherever it wrote any. */
    sentence: string;
    task: RecurringTaskStub | null;
    previous_open_task: RecurringTaskStub | null;
    created_at: string | null;
}

export interface RecurringTemplate {
    id: number;
    project_id: number;
    title_template: string;
    checklist_template: string[];
    frequency: RecurrenceFrequency;
    frequency_label: string;
    recurrence_rule: RecurrenceRuleFields;
    /** The rule in words, spelled on the server. */
    recurrence_summary: string;
    default_assignee: { id: number; name: string | null } | null;
    active: boolean;
    next_run: RecurringNextRun;
    next_run_at_cached: string | null;
    /**
     * Why this template will not run, or null when it will. `RecurringTaskEngine::stopReason()`
     * — the same method the 00:05 run calls, so the sentence here is the sentence the log will
     * carry. A stop is derived and never stored, which is exactly why the screen has to ask.
     */
    stop_reason: string | null;
    last_run: RecurringLogEntry | null;
    created_at: string | null;
    permissions: { can_update: boolean; can_generate: boolean };
}

export interface RecurringIndexResponse {
    templates: RecurringTemplate[];
}

export interface RecurringLogResponse {
    entries: RecurringLogEntry[];
}

/** The preview of a rule that has not been saved. Every field is RecurrenceRule's answer. */
export interface RecurrencePreview {
    at: string;
    period: string;
    period_label: string | null;
    period_start: string;
    period_end: string;
    due_date: string;
    summary: string;
}

/* ------------------------------------------------------------------ endpoints */

export interface RecurringRoutes {
    /** `GET`, JSON — the tab fetches this on open and re-reads it after every write. */
    index: string;
    /** `POST`, Inertia, `back()` with a flash. */
    store: string;
    /** `GET`, JSON — an unsaved rule's next run. A read, so it carries no token. */
    preview: string;
    /** `PUT`, Inertia. */
    update: (id: number) => string;
    /** `POST`, Inertia — `RecurringTaskEngine::generate(force: true)`. */
    generate: (id: number) => string;
    /** `GET`, JSON — one template's attempts, newest first. */
    log: (id: number) => string;
}

/**
 * Every recurring endpoint, spelled once.
 *
 * There is no `surface` parameter, and that is the rule rather than an omission: a retainer
 * template is an Admin object (master prompt Phase 3, "Screens (Admin)"), so there is one set of
 * routes and `RecurringTaskPolicy` is what refuses everybody else — not the absence of an
 * employee route that somebody would eventually add.
 */
export function recurringRoutes(projectId: number): RecurringRoutes {
    const collection = `/admin/projects/${projectId}/recurring`;

    return {
        index: collection,
        store: collection,
        preview: `${collection}/preview`,
        update: (id) => `/admin/recurring-tasks/${id}`,
        generate: (id) => `/admin/recurring-tasks/${id}/generate`,
        log: (id) => `/admin/recurring-tasks/${id}/log`,
    };
}

/* ------------------------------------------------------------------ reading */

/**
 * A JSON read's answer. A refusal is told apart from a network failure, because the two need
 * different words on screen: one is "you may not see this" and the other is "try again".
 */
export type RecurringLoad<T> = { ok: true; payload: T } | { ok: false; reason: 'forbidden' | 'failed' };

async function readJson<T>(url: string): Promise<RecurringLoad<T>> {
    try {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            return { ok: false, reason: response.status === 403 ? 'forbidden' : 'failed' };
        }

        return { ok: true, payload: (await response.json()) as T };
    } catch {
        return { ok: false, reason: 'failed' };
    }
}

export function loadTemplates(routes: RecurringRoutes): Promise<RecurringLoad<RecurringIndexResponse>> {
    return readJson<RecurringIndexResponse>(routes.index);
}

export function loadLog(routes: RecurringRoutes, id: number): Promise<RecurringLoad<RecurringLogResponse>> {
    return readJson<RecurringLogResponse>(routes.log(id));
}

/**
 * The next run of a rule nobody has saved.
 *
 * A 422 here is not an error worth a red panel: it means the rule is half-typed, which is the
 * normal state of a form somebody is filling in. The caller shows nothing rather than shouting.
 */
export function loadPreview(
    routes: RecurringRoutes,
    draft: RecurrenceDraft,
): Promise<RecurringLoad<{ preview: RecurrencePreview }>> {
    return readJson<{ preview: RecurrencePreview }>(`${routes.preview}?${ruleQuery(draft)}`);
}

/* ------------------------------------------------------------------ the editor's half */

/** What the editor holds while somebody is choosing. One shape for all three frequencies. */
export interface RecurrenceDraft {
    frequency: RecurrenceFrequency;
    day_of_month: number;
    weekday: number;
    interval_days: number;
    anchor: string;
    /** `true` when the instance is due at the end of its period — the default. */
    due_at_period_end: boolean;
    due_offset_days: number;
}

export const WEEKDAYS: { value: number; label: string }[] = [
    { value: 1, label: 'Monday' },
    { value: 2, label: 'Tuesday' },
    { value: 3, label: 'Wednesday' },
    { value: 4, label: 'Thursday' },
    { value: 5, label: 'Friday' },
    { value: 6, label: 'Saturday' },
    { value: 7, label: 'Sunday' },
];

/**
 * `RecurrenceRule::MAX_DAY_OF_MONTH`. A monthly rule is clamped to the 28th so that it never
 * skips February, and the editor offers only what the server would keep.
 */
export const MAX_DAY_OF_MONTH = 28;

export const MIN_INTERVAL_DAYS = 1;
export const MAX_INTERVAL_DAYS = 366;

export function emptyRecurrenceDraft(today: string): RecurrenceDraft {
    return {
        frequency: 'monthly',
        day_of_month: 1,
        weekday: 1,
        interval_days: 14,
        anchor: today,
        due_at_period_end: true,
        due_offset_days: 7,
    };
}

/**
 * Rebuild the editor's draft from a saved template.
 *
 * The parameters that do not belong to the saved frequency keep their defaults, so toggling
 * "monthly" over to "weekly" in the editor offers Monday rather than an empty control — and
 * toggling back offers the day of the month that was actually stored.
 */
export function draftFromTemplate(template: RecurringTemplate, today: string): RecurrenceDraft {
    const rule = template.recurrence_rule;

    return {
        frequency: rule.frequency,
        day_of_month: rule.day_of_month ?? 1,
        weekday: rule.weekday ?? 1,
        interval_days: rule.interval_days ?? 14,
        anchor: rule.anchor ?? today,
        due_at_period_end: rule.due_offset_days === undefined || rule.due_offset_days === null,
        due_offset_days: rule.due_offset_days ?? 7,
    };
}

/**
 * The draft as the server's Form Request takes it.
 *
 * This is the whole of the editor's job. `BuildsRecurrenceRule` sends exactly one of
 * `day_of_month`, `weekday` or (`interval_days` + `anchor`) to one of `RecurrenceRule`'s three
 * named constructors, and ignores the fields the chosen frequency does not use. So the three
 * shapes on screen are three sets of controls over one request body, and neither side invents a
 * fourth: the form does not decide what a period is, and the rule does not decide what a control
 * looks like.
 *
 * The other frequencies' values are sent anyway rather than stripped. They are ignored by the
 * server, and sending them means a mistyped frequency fails as a frequency rather than as three
 * mysteriously missing fields.
 */
export function ruleFields(draft: RecurrenceDraft): Record<string, string | number | null> {
    return {
        frequency: draft.frequency,
        day_of_month: draft.day_of_month,
        weekday: draft.weekday,
        interval_days: draft.interval_days,
        anchor: draft.anchor === '' ? null : draft.anchor,
        due_offset_days: draft.due_at_period_end ? null : draft.due_offset_days,
    };
}

/** The same fields as a query string, for the preview GET. */
export function ruleQuery(draft: RecurrenceDraft): string {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(ruleFields(draft))) {
        if (value !== null && value !== '') {
            params.set(key, String(value));
        }
    }

    return params.toString();
}

/* ------------------------------------------------------------------ presentation */

/**
 * The badge a template's state is drawn with.
 *
 * Three states, not two: a template can be switched on and still produce nothing, because the
 * project it is on was cancelled — the stop is derived on every run and never written to the
 * row (decision 3-4). A screen that only knew `active` would draw that template as healthy.
 *
 * The label is always printed beside the tone, because a state carried by colour alone is
 * DESIGN.md §5.6. The tone mapping lives here rather than being asked of the server: unlike a
 * task's status, a template's state has no server-side tone to drift from.
 */
export function stateOf(template: RecurringTemplate): { status: StatusKey; label: string } {
    if (!template.active) {
        return { status: 'todo', label: 'Off' };
    }

    return template.stop_reason === null
        ? { status: 'done', label: 'Running' }
        : { status: 'waiting', label: 'Stopped' };
}

/** The tone a log row is drawn with. Warnings are worth looking at; the rest is history. */
export function logTone(entry: RecurringLogEntry): StatusKey {
    if (!entry.is_warning) {
        return 'done';
    }

    return entry.outcome === 'generated' ? 'review' : 'waiting';
}

const DATE = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' });
const DATE_TIME = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short' });

/**
 * A date the server computed, formatted for reading. It parses and prints; it never adds a day.
 */
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

/** Today, as the date input and the anchor default want it: `YYYY-MM-DD`, local. */
export function todayIso(): string {
    const now = new Date();
    const month = `${now.getMonth() + 1}`.padStart(2, '0');
    const day = `${now.getDate()}`.padStart(2, '0');

    return `${now.getFullYear()}-${month}-${day}`;
}
