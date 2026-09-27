import type { StatusKey } from '@/Components/StatusBadge.vue';

/**
 * The audit log's client-side contract — Phase 12, Admin → Audit Log.
 *
 * Every type here is the shape `AuditLogResource` sends and nothing more. In particular the
 * labels are **not** derived here: `event_label`, `event_group`, a field's `label` and a
 * target's `label` all come from the server, because `AuditEvent` is the only thing that knows
 * what an event value means and a second copy of that mapping in TypeScript would drift the
 * first time a phase added an event. What this file holds is the handful of words that are
 * about *reading a diff* rather than about the domain.
 */

/** What happened to one field between `old_value` and `new_value`. */
export type AuditDiffState = 'added' | 'removed' | 'changed' | 'unchanged';

/**
 * What kind of pair the row carries.
 *
 * `created` and `deleted` are not "unchanged": a created record has no earlier value and a
 * deleted one has no later value, and both are different from a field that stayed the same.
 * `opaque` is a value that is not a map of fields at all (nothing in this build writes one, but
 * the column is `jsonb` and an older build might have); `empty` is a row with no values.
 */
export type AuditDiffKind = 'created' | 'deleted' | 'updated' | 'opaque' | 'empty';

export interface AuditDiffField {
    /** The raw JSON key, shown beside the label so an auditor can quote the stored name. */
    key: string;
    label: string;
    state: AuditDiffState;
    /** Whether the key existed on that side at all — not the same as its value being null. */
    in_old: boolean;
    in_new: boolean;
    old: unknown;
    new: unknown;
}

export interface AuditDiff {
    kind: AuditDiffKind;
    fields: AuditDiffField[];
    changed_count: number;
    unchanged_count: number;
}

export interface AuditActor {
    id: number;
    name: string;
    email: string;
}

export interface AuditTarget {
    /** The morph class as stored — the FQCN, because the application registers no morph map. */
    type: string;
    label: string;
    id: number | null;
}

export interface AuditEntry {
    id: number;
    event: string;
    event_label: string;
    event_group: string;
    /** False for a value this build has no `AuditEvent` case for — an older build's row. */
    event_known: boolean;
    actor: AuditActor | null;
    target: AuditTarget | null;
    old_value: unknown;
    new_value: unknown;
    diff: AuditDiff;
    created_at: string | null;
    /** The same instant in the agency's timezone, rendered by the server. */
    recorded_at: string | null;
    ip: string | null;
    user_agent: string | null;
}

export interface AuditFilters {
    actor: string | null;
    event: string | null;
    target_type: string | null;
    date_from: string | null;
    date_to: string | null;
}

export interface AuditOption {
    value: string;
    label: string;
}

export interface AuditEventOption extends AuditOption {
    group: string;
}

export interface AuditOptions {
    actors: AuditOption[];
    events: AuditEventOption[];
    target_types: AuditOption[];
}

/**
 * The word for each state. **The word is the state** — a reader must not have to know that
 * green means added, and four of the eight status tints fail 3:1 with their label removed
 * (DESIGN.md §5.6, §2.2). Every tint below is paired with one of these, always.
 */
export const STATE_WORD: Record<AuditDiffState, string> = {
    added: 'Set',
    removed: 'Removed',
    changed: 'Changed',
    unchanged: 'Unchanged',
};

/**
 * The status tone each state borrows.
 *
 * Borrows, rather than introduces: the eight `--status-*` families are the whole palette a
 * screen may tint with (DESIGN.md §5.3), so a diff uses them instead of inventing a diff
 * colour. `done` for a value that now exists, `cancelled` for one that no longer does,
 * `progress` for one that moved, and the neutral `todo` for one that did not.
 */
export const STATE_TONE: Record<AuditDiffState, StatusKey> = {
    added: 'done',
    removed: 'cancelled',
    changed: 'progress',
    unchanged: 'todo',
};

/** Who did it. A row with no actor was written by a command, a job or the scheduler. */
export function actorName(entry: AuditEntry): string {
    return entry.actor?.name ?? 'System';
}

/** What it was about — `Employee #4`, or the word for nothing. */
export function targetName(entry: AuditEntry): string {
    if (entry.target === null) {
        return 'No record';
    }

    return entry.target.id === null ? entry.target.label : `${entry.target.label} #${entry.target.id}`;
}

/**
 * The sentence above a diff, which says which of the five kinds this is before any field is
 * read. A created record and a record whose fields all stayed the same look identical if you
 * only list fields, so the difference is stated in words first.
 */
export function diffHeadline(diff: AuditDiff): string {
    switch (diff.kind) {
        case 'created':
            return 'Created — there was no earlier value. Every field below is what it was first recorded as.';
        case 'deleted':
            return 'Deleted — there is no later value. Every field below is what it last said, and this row is all that is left of it.';
        case 'updated':
            return diff.changed_count === 0
                ? 'Recorded, with no field different from before.'
                : 'Changed. The fields that moved are below; the rest are unchanged.';
        case 'opaque':
            return 'The values on this row are not a set of named fields, so there is nothing to compare field by field. Both are shown whole.';
        case 'empty':
            return 'No values were recorded with this event — the event itself is the whole of the entry.';
    }
}

/**
 * The one-line summary of a row, for the list's Change column.
 *
 * A field NAME when exactly one moved, because that is the useful word and it fits; a count
 * after that, because four field names in a table cell is a paragraph.
 */
export function changeSummary(entry: AuditEntry): string {
    const { kind, fields, changed_count: changed } = entry.diff;

    if (kind === 'opaque') {
        return 'Values recorded';
    }

    if (kind === 'empty') {
        return 'No values';
    }

    if (kind === 'created') {
        return fields.length === 1 ? `Created — ${lower(fields[0].label)}` : `Created — ${fields.length} fields`;
    }

    if (kind === 'deleted') {
        return fields.length === 1 ? `Deleted — ${lower(fields[0].label)}` : `Deleted — ${fields.length} fields`;
    }

    if (changed === 0) {
        return 'Nothing changed';
    }

    if (changed === 1) {
        const moved = fields.find((field) => field.state !== 'unchanged');

        return moved === undefined ? '1 field changed' : `${moved.label} changed`;
    }

    return `${changed} fields changed`;
}

/** `Base salary` → `base salary`, for the middle of a sentence. */
function lower(label: string): string {
    return label.charAt(0).toLowerCase() + label.slice(1);
}

/**
 * Which columns of a field block are worth drawing.
 *
 * On a created record every "was" cell would read *Not recorded*, and on a deleted one every
 * "now" cell would — a column of the same four words repeated is noise competing with the
 * values beside it. The headline has already said which side is missing.
 */
export function diffColumns(kind: AuditDiffKind): { before: boolean; after: boolean } {
    return { before: kind !== 'created', after: kind !== 'deleted' };
}
